<?php

declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Portal license validator for ETechFlow_ProductFitmentFinder.
 *
 *   isValid() priority:
 *     1. revoked = 1                       → false (portal revoke wins even in dev)
 *     2. dev / staging host                → true (unlicensed dev bypass; production hosts never match)
 *     3. SP-XXXX key, portal answers       → portal's answer is final (true/false)
 *     4. SP-XXXX key, portal unreachable   → cached-success grace (48h)
 *     5. otherwise                         → false
 *
 *   The signing secret lives ONLY on the portal. The module holds no secret and
 *   cannot mint a valid key, so a customer cannot forge a licence for their own
 *   domain. Offline grace is derived solely from a cached, genuine portal
 *   "valid" response — never from admin-settable config — so it cannot be
 *   fabricated either.
 *
 *   The portal-first ordering is what makes IP-revocation work: when the admin
 *   removes a server's IP from the portal subscription, /license/validate
 *   returns HTTP 403 with valid:false, which counts as an "explicit reject" and
 *   locks the module immediately. Grace only applies when the portal literally
 *   cannot be reached (timeout, network error, no URL).
 */
class LicenseValidator
{
    public const XML_PATH_LICENSE_KEY            = 'etechflow_vehiclecompat/license/license_key';
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_vehiclecompat/license/production_environment';
    public const XML_PATH_PORTAL_URL             = 'etechflow_vehiclecompat/license/portal_url';
    public const XML_PATH_PORTAL_API_URL         = 'etechflow_vehiclecompat/license/portal_api_url';

    /** Shared config path — same value across all eTechFlow modules. */
    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    private const MODULE_ID = 'vehicle-compat';

    private const CACHE_TTL_VALID  = 60; // portal said valid → cache 60s so admin IP-removal propagates within 1 minute
    private const CACHE_TTL_REJECT = 60; // portal said NO → recheck within 1 minute so re-authorisation propagates fast
    private const GRACE_TTL        = 172800; // 48h offline grace, refreshed on every portal success
    private const CACHE_TAG = 'etechflow_pff_license';

    /**
     * @param ScopeConfigInterface  $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CacheInterface        $cache
     * @param Curl                  $curl
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Curl $curl
    ) {
    }

    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return false;
        }

        if ($this->isExplicitlyRevoked()) {
            return false;
        }

        // Dev / staging bypass: localhost, private ranges, *.test/.local,
        // dev./staging./qa. and *-dev./*-staging. hosts run unlicensed so the team
        // and Marketplace QA can exercise the module without a paid SP- key.
        // isDevHost() never matches a real production domain, so a live customer
        // store still requires a portal-issued licence. Portal revoke (above)
        // still wins even on a dev host.
        if ($this->isDevHost()) {
            return true;
        }

        if (!$this->isProductionEnvironment()) {
            return true;
        }

        // A per-module SP- key, or failing that a bundle-wide SP- key. Both are
        // portal-issued and portal-validated; the module never signs anything.
        $configuredKey = $this->getConfiguredKey();
        if (!str_starts_with($configuredKey, 'SP-')) {
            $configuredKey = $this->getConfiguredBundleKey();
        }

        // SECURITY: only portal-issued (SP-) keys are honoured. The former
        // "legacy HMAC" fallbacks computed a key from a secret that shipped in
        // this file — anyone with the module could forge a valid key for their
        // own domain, so they were removed. Offline grace now comes only from a
        // cached genuine portal success, which the customer cannot fabricate.
        if (!str_starts_with($configuredKey, 'SP-')) {
            return false;
        }

        $portalAnswer = $this->validateViaPortal($host, $configuredKey);
        if ($portalAnswer === true) {
            return true;
        }
        if ($portalAnswer === false) {
            return false;
        }

        // Portal unreachable → honour the grace window if a genuine prior
        // success is still cached. A network blip won't black-hole live
        // storefronts, but nothing the customer can set grants this.
        return $this->hasValidGrace($host, $configuredKey);
    }

    /**
     * Ask the portal whether this host+key is currently authorised.
     *
     * @return bool|null  true  = portal said valid
     *                    false = portal explicitly rejected (200 valid:false, 401, 403)
     *                    null  = portal unreachable / unconfigured (caller may fall back to grace)
     */
    private function validateViaPortal(string $host, string $licenseKey): ?bool
    {
        $cacheKey = 'etf_pff_lic_' . md5($host . ':' . $licenseKey);
        $cached   = $this->cache->load($cacheKey);
        if ($cached === '1') {
            return true;
        }
        if ($cached === '0') {
            return false;
        }

        $apiBase = $this->getPortalApiBase();
        if ($apiBase === '') {
            return null; // no portal configured → grace fallback
        }

        $url = rtrim($apiBase, '/') . '/license/validate'
            . '?domain='      . urlencode($this->canonicalize($host))
            . '&license_key=' . urlencode($licenseKey)
            . '&platform=magento'
            . '&module='      . urlencode(self::MODULE_ID);

        $status = 0;
        $body   = '';
        try {
            $this->curl->setTimeout(5);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-PFF/1.0');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Exception) {
            return null; // network error → grace fallback
        }

        if ($status === 200 && $body !== '') {
            $data  = json_decode($body, true);
            $valid = !empty($data['valid']);
            $this->cache->save(
                $valid ? '1' : '0',
                $cacheKey,
                [self::CACHE_TAG],
                $valid ? self::CACHE_TTL_VALID : self::CACHE_TTL_REJECT
            );
            if ($valid) {
                $this->writeGrace($host, $licenseKey);
            } else {
                $this->clearGrace($host, $licenseKey);
            }
            return $valid;
        }

        if ($status === 401 || $status === 403) {
            // Portal answered and said NO (e.g. IP revoked, subscription suspended, key revoked).
            $this->cache->save('0', $cacheKey, [self::CACHE_TAG], self::CACHE_TTL_REJECT);
            $this->clearGrace($host, $licenseKey);
            return false;
        }

        // 0 / 5xx / other → treat as unreachable, no caching.
        return null;
    }

    private function getPortalApiBase(): string
    {
        $api = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_API_URL));
        if ($api !== '') {
            return $api;
        }
        $browser = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_URL));
        if ($browser !== '' && !str_contains($browser, '127.0.0.1') && !str_contains($browser, 'localhost')) {
            return $browser;
        }
        return '';
    }

    /**
     * Offline grace is keyed to a host+key pair and only ever written by a
     * genuine portal success (see validateViaPortal). The customer cannot set
     * this cache entry, so grace cannot be forged.
     */
    private function graceCacheKey(string $host, string $licenseKey): string
    {
        return 'etf_pff_lic_grace_' . md5($this->canonicalize($host) . ':' . $licenseKey);
    }

    private function writeGrace(string $host, string $licenseKey): void
    {
        $this->cache->save(
            (string) time(),
            $this->graceCacheKey($host, $licenseKey),
            [self::CACHE_TAG],
            self::GRACE_TTL
        );
    }

    private function clearGrace(string $host, string $licenseKey): void
    {
        $this->cache->remove($this->graceCacheKey($host, $licenseKey));
    }

    private function hasValidGrace(string $host, string $licenseKey): bool
    {
        $stamp = $this->cache->load($this->graceCacheKey($host, $licenseKey));
        if ($stamp === false || $stamp === '' || $stamp === null) {
            return false;
        }
        // Belt-and-braces: don't trust the backend's TTL alone.
        return (time() - (int) $stamp) < self::GRACE_TTL;
    }

    private function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BUNDLE_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function isProductionEnvironment(): bool
    {
        // Sandbox toggle removed: production licensing is always enforced.
        return true;
    }

    /**
     * Read-only licence state for the admin gate notice — one of
     * active | suspended | expired | blocked | invalid | none.
     *
     * Guide §3g Rule 1: this NEVER mutates or clears the key. It only asks the
     * portal's /license/status endpoint why access is denied, so the merchant
     * can be shown a precise reason ("suspended" vs "IP blocked" vs "expired")
     * instead of a blank page. Cached 60s to avoid a portal round-trip on every
     * admin request. Falls back to 'invalid' (unknown reason) when the portal is
     * unreachable, and 'none' when there is simply no SP- key configured yet.
     */
    public function getLicenseState(): string
    {
        $host = $this->getCurrentHost();
        $key  = $this->getConfiguredKey();
        if (!str_starts_with($key, 'SP-')) {
            $key = $this->getConfiguredBundleKey();
        }
        if ($host === '' || !str_starts_with($key, 'SP-')) {
            return 'none';
        }

        $cacheKey = 'etf_pff_lic_state_' . md5($this->canonicalize($host) . ':' . $key);
        $cached   = $this->cache->load($cacheKey);
        if ($cached !== false && $cached !== null && $cached !== '') {
            return (string) $cached;
        }

        $apiBase = $this->getPortalApiBase();
        if ($apiBase === '') {
            return 'invalid';
        }

        $url = rtrim($apiBase, '/') . '/license/status'
            . '?platform=magento'
            . '&module='      . urlencode(self::MODULE_ID)
            . '&domain='      . urlencode($this->canonicalize($host))
            . '&license_key=' . urlencode($key);

        $state = 'invalid';
        try {
            $this->curl->setTimeout(8);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-PFF/1.0');
            $this->curl->get($url);
            if ((int) $this->curl->getStatus() === 200) {
                $data = json_decode((string) $this->curl->getBody(), true);
                if (is_array($data)) {
                    $state = !empty($data['ip_blocked']) ? 'blocked'
                        : (!empty($data['state']) ? (string) $data['state']
                        : (isset($data['valid']) ? ($data['valid'] ? 'active' : 'invalid') : 'invalid'));
                }
            }
        } catch (\Throwable) {
            // portal unreachable → leave as 'invalid' (unknown), never mutate the key
        }

        $this->cache->save($state, $cacheKey, [self::CACHE_TAG], 60);
        return $state;
    }

    public function getCurrentHost(): string
    {
        try {
            $url  = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Classifies a host as dev/staging. Consulted by isValid() to grant an
     * unlicensed dev/staging bypass (priority #2) and by the admin status banner.
     * Only ever matches non-production hosts, so it can never unlock a live
     * customer domain. Ships no secret.
     */
    public function isDevHost(?string $host = null): bool
    {
        $check = $host !== null ? strtolower(trim($host)) : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($check);
    }

    private function isDevelopmentHost(string $host): bool
    {
        if ($host === 'localhost' || str_starts_with($host, '127.')) return true;
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) return true;
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) return true;
        foreach (['.test', '.local', '.localhost', '.dev', '.example', '.invalid'] as $s) {
            if (str_ends_with($host, $s)) return true;
        }
        foreach (['staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'preview.', 'sandbox.'] as $p) {
            if (str_starts_with($host, $p)) return true;
        }
        // Infix dev markers, e.g. "magento-dev.etechflow.com", "shop-staging.example.com".
        foreach (['-dev.', '-staging.', '-stage.', '-qa.', '-uat.', '-test.', '-sandbox.'] as $mid) {
            if (str_contains($host, $mid)) return true;
        }
        foreach (['.magento.cloud', '.magentocloud.com', '.ngrok.io', '.ngrok-free.app', '.ngrok-free.dev', '.loca.lt'] as $s) {
            if (str_ends_with($host, $s)) return true;
        }
        return false;
    }

    private function isExplicitlyRevoked(): bool
    {
        return (string) $this->scopeConfig->getValue(
            'etechflow_vehiclecompat/license/revoked',
            ScopeInterface::SCOPE_STORE
        ) === '1';
    }
}
