<?php

declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed config reader for the VehicleCompat module.
 *
 * v1.0.2 added the "Universal Fitment" options group: configurable Year
 * lower bound, optional Year field, and admin-customisable labels for
 * Make / Model / Year / Part so the same module can sell to vehicle,
 * cycling, marine, RV, and non-vehicle product-fitment domains without
 * code changes.
 */
class Config
{
    private const XML_PATH_ENABLED              = 'etechflow_vehiclecompat/general/enabled';
    private const XML_PATH_EARLIEST_YEAR        = 'etechflow_vehiclecompat/general/earliest_year';
    private const XML_PATH_SHOW_YEAR_FIELD      = 'etechflow_vehiclecompat/general/show_year_field';
    private const XML_PATH_LABEL_MAKE           = 'etechflow_vehiclecompat/general/label_make';
    private const XML_PATH_LABEL_MODEL          = 'etechflow_vehiclecompat/general/label_model';
    private const XML_PATH_LABEL_YEAR           = 'etechflow_vehiclecompat/general/label_year';
    private const XML_PATH_LABEL_PART           = 'etechflow_vehiclecompat/general/label_part';

    // v2.1.0 — admin-controlled storefront placement of the Part Finder form
    private const XML_PATH_PLACEMENT_LOCATION   = 'etechflow_vehiclecompat/placement/location';

    // v1.1.0 — PDP fitment badge
    private const XML_PATH_SHOW_PDP_BADGE       = 'etechflow_vehiclecompat/pdp_badge/enabled';
    private const XML_PATH_PDP_BADGE_PREFIX     = 'etechflow_vehiclecompat/pdp_badge/prefix';
    private const XML_PATH_PDP_BADGE_STYLE      = 'etechflow_vehiclecompat/pdp_badge/style';

    // v1.1.0 — SEO URLs
    private const XML_PATH_SEO_URLS_ENABLED     = 'etechflow_vehiclecompat/seo_urls/enabled';
    private const XML_PATH_SEO_URL_PREFIX       = 'etechflow_vehiclecompat/seo_urls/prefix';

    // v1.1.0 — Saved garage
    private const XML_PATH_GARAGE_ENABLED       = 'etechflow_vehiclecompat/garage/enabled';
    private const XML_PATH_GARAGE_MAX_ENTRIES   = 'etechflow_vehiclecompat/garage/max_entries';

    // v1.1.1 — Universal customer-facing copy
    private const XML_PATH_FIND_BUTTON_TEXT     = 'etechflow_vehiclecompat/copy/find_button_text';
    private const XML_PATH_FIND_PAGE_TITLE      = 'etechflow_vehiclecompat/copy/find_page_title';
    private const XML_PATH_PART_FINDER_HEADING  = 'etechflow_vehiclecompat/copy/part_finder_heading';
    private const XML_PATH_EMPTY_STATE_MESSAGE  = 'etechflow_vehiclecompat/copy/empty_state_message';
    private const XML_PATH_SAVE_BUTTON_TEXT     = 'etechflow_vehiclecompat/copy/save_button_text';
    private const XML_PATH_GARAGE_EMPTY_PROMPT  = 'etechflow_vehiclecompat/copy/garage_empty_prompt';

    // v1.2.0 — OEM / part-number search
    private const XML_PATH_OEM_ENABLED          = 'etechflow_vehiclecompat/oem/enabled';
    private const XML_PATH_OEM_ATTRIBUTES       = 'etechflow_vehiclecompat/oem/attribute_codes';
    private const XML_PATH_OEM_SEARCH_LABEL     = 'etechflow_vehiclecompat/oem/search_label';
    private const XML_PATH_OEM_SEARCH_PLACEHOLDER = 'etechflow_vehiclecompat/oem/search_placeholder';

    // v1.2.1 — Storefront copy polish + tooltips + theme colour
    private const XML_PATH_COPY_NO_RESULTS_TITLE   = 'etechflow_vehiclecompat/polish/no_results_title';
    private const XML_PATH_COPY_NO_RESULTS_HINT    = 'etechflow_vehiclecompat/polish/no_results_hint';
    private const XML_PATH_COPY_USE_FORM_PROMPT    = 'etechflow_vehiclecompat/polish/use_form_prompt';
    private const XML_PATH_COPY_NO_MATCHES         = 'etechflow_vehiclecompat/polish/no_matches';
    private const XML_PATH_COPY_DROPDOWN_SEARCH    = 'etechflow_vehiclecompat/polish/dropdown_search_placeholder';
    private const XML_PATH_COPY_FITMENT_OVERFLOW   = 'etechflow_vehiclecompat/polish/fitment_overflow_template';
    private const XML_PATH_COPY_GARAGE_TITLE       = 'etechflow_vehiclecompat/polish/garage_title';
    private const XML_PATH_COPY_GARAGE_CLEAR       = 'etechflow_vehiclecompat/polish/garage_clear';
    private const XML_PATH_COPY_GARAGE_REMOVE      = 'etechflow_vehiclecompat/polish/garage_remove';
    private const XML_PATH_COPY_SAVED_FEEDBACK     = 'etechflow_vehiclecompat/polish/saved_feedback';
    private const XML_PATH_COPY_SIDEBAR_NO_FILTERS = 'etechflow_vehiclecompat/polish/sidebar_no_filters';
    private const XML_PATH_COPY_OEM_BUTTON         = 'etechflow_vehiclecompat/polish/oem_button';
    private const XML_PATH_TOOLTIP_FITMENT         = 'etechflow_vehiclecompat/polish/tooltip_fitment';
    private const XML_PATH_TOOLTIP_GARAGE          = 'etechflow_vehiclecompat/polish/tooltip_garage';
    private const XML_PATH_TOOLTIP_OEM             = 'etechflow_vehiclecompat/polish/tooltip_oem';
    private const XML_PATH_ACCENT_COLOUR           = 'etechflow_vehiclecompat/polish/accent_colour';

    /** Allowed badge style modifiers — clamped against this whitelist. */
    private const BADGE_STYLES = ['success', 'info', 'warning', 'neutral'];

    /** Valid Part Finder placement locations — anything else means "not placed". */
    private const PLACEMENT_LOCATIONS = ['home', 'product', 'product_list'];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Lower bound for the Year dropdown. Default 1990. Merchant can set
     * e.g. 1950 for vintage car parts shops, 2007 for smartphone-fitment
     * shops, etc. Anything below 1900 or above current year is clamped
     * to sensible bounds.
     */
    public function getEarliestYear(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_EARLIEST_YEAR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value < 1900) {
            return 1990;
        }
        $currentYear = (int) date('Y');
        if ($value > $currentYear) {
            return $currentYear;
        }
        return $value;
    }

    /**
     * Should the Year field render in the Part Finder form? Default Yes.
     * Set No for fitment domains that don't have a year axis (phone cases,
     * watch straps, printer cartridges, appliance parts, etc.)
     */
    public function isYearFieldEnabled(?int $storeId = null): bool
    {
        // isSetFlag returns true when the config value is "1" / "yes" / etc.
        // Default in config.xml is "1" so a fresh install gets Yes.
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_YEAR_FIELD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * v2.1.0 — where the admin has chosen to auto-place the Part Finder form.
     * One of 'home' | 'product' | 'product_list', or '' when set to "don't
     * auto-place" (the merchant then uses the widget). Clamped to the whitelist.
     */
    public function getPartFinderLocation(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_PLACEMENT_LOCATION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return in_array($value, self::PLACEMENT_LOCATIONS, true) ? $value : '';
    }

    /**
     * Should a Part Finder placement tagged with $location render? True only when
     * it matches the single admin-selected location.
     */
    public function isPartFinderEnabledFor(string $location, ?int $storeId = null): bool
    {
        return $location !== '' && $location === $this->getPartFinderLocation($storeId);
    }

    public function getMakeLabel(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_LABEL_MAKE, 'Make', $storeId);
    }

    public function getModelLabel(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_LABEL_MODEL, 'Model', $storeId);
    }

    public function getYearLabel(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_LABEL_YEAR, 'Year', $storeId);
    }

    public function getPartLabel(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_LABEL_PART, 'Parts Required', $storeId);
    }

    private function labelOrDefault(string $path, string $default, ?int $storeId): string
    {
        $value = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        $value = trim($value);
        return $value !== '' ? $value : $default;
    }

    /** v1.1.0 — should the PDP "This fits:" badge render? Default off. */
    public function isShowFitmentBadgeOnPdp(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_PDP_BADGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getFitmentBadgePrefix(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_PDP_BADGE_PREFIX, 'Fits:', $storeId);
    }

    /** Style modifier — clamped to BADGE_STYLES whitelist. */
    public function getFitmentBadgeStyle(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_PDP_BADGE_STYLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return in_array($value, self::BADGE_STYLES, true) ? $value : 'success';
    }

    /** v1.1.0 — should SEO-friendly URLs route Part Finder requests? Default off. */
    public function isSeoUrlsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SEO_URLS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * URL prefix for SEO routes. Default "parts" → /parts/bmw/3-series/2020/brake-pads.
     * Sanitised to lowercase alphanumeric + dash; empty or unsafe values
     * fall back to "parts".
     */
    public function getSeoUrlPrefix(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SEO_URL_PREFIX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-]/', '', $value) ?: '';
        return $value !== '' ? $value : 'parts';
    }

    /** v1.1.0 — should the My Garage widget render when placed? Default off. */
    public function isSavedGarageEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GARAGE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /** Max vehicles a customer can save. Default 3. Clamped to 1-10. */
    public function getGarageMaxEntries(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_GARAGE_MAX_ENTRIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value < 1) { return 3; }
        if ($value > 10) { return 10; }
        return $value;
    }

    /** v1.1.1 — Customer-facing button + title + empty-state copy.
     *  All admin-configurable so non-vehicle merchants can rebrand the
     *  surrounding chrome to match their domain (phone cases, watches, etc.).
     */
    public function getFindButtonText(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_FIND_BUTTON_TEXT, 'Find Parts', $storeId);
    }

    public function getFindPageTitle(?int $storeId = null): string
    {
        // Unified with the Part Finder Heading — the separate "Find Page Title"
        // admin field was removed as redundant. Fall back to the default heading
        // when the heading is blank so the results page always has an H1.
        $heading = $this->getPartFinderHeading($storeId);
        return $heading !== '' ? $heading : 'Find Your Parts';
    }

    /**
     * v2.1.0 — heading rendered above the embedded Part Finder form. Unlike the
     * other copy getters this does NOT fall back to a default when blank: an
     * empty value means "show no heading", so a merchant can hide it.
     */
    public function getPartFinderHeading(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_PART_FINDER_HEADING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Empty-state message shown when no filters are active on the Find
     * page. Supports `{make}` / `{model}` / `{year}` / `{part}` placeholders
     * which expand to the merchant's configured labels at render time.
     */
    public function getEmptyStateMessage(?int $storeId = null): string
    {
        $template = $this->labelOrDefault(
            self::XML_PATH_EMPTY_STATE_MESSAGE,
            'Pick a {make}, {model}, {year} or {part} to see matching products.',
            $storeId
        );
        return strtr($template, [
            '{make}'  => $this->getMakeLabel($storeId),
            '{model}' => $this->getModelLabel($storeId),
            '{year}'  => $this->getYearLabel($storeId),
            '{part}'  => $this->getPartLabel($storeId),
        ]);
    }

    public function getSaveButtonText(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_SAVE_BUTTON_TEXT, 'Save Selection', $storeId);
    }

    public function getGarageEmptyPrompt(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_GARAGE_EMPTY_PROMPT,
            'Save a selection here for one-click reload later.',
            $storeId
        );
    }

    /** v1.2.0 — Should the OEM/part-number search box render on the Find page? Default off. */
    public function isOemSearchEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_OEM_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * v1.2.0 — Which product attribute codes to search against when the
     * customer types a part number. Default `sku`. Merchants with an
     * OEM-number attribute (or "mpn", "manufacturer_part_number", etc.)
     * configure multiple comma-separated codes; the OEM filter does a
     * `LIKE %term%` across the union.
     *
     * @return string[]
     */
    public function getOemAttributeCodes(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OEM_ATTRIBUTES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (trim($raw) === '') {
            return ['sku'];
        }
        $codes = array_map('trim', explode(',', $raw));
        $codes = array_filter($codes, fn($c) => $c !== '' && preg_match('/^[a-z0-9_]+$/i', $c));
        return array_values(array_unique($codes ?: ['sku']));
    }

    public function getOemSearchLabel(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_OEM_SEARCH_LABEL,
            'Or search by part number',
            $storeId
        );
    }

    public function getOemSearchPlaceholder(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_OEM_SEARCH_PLACEHOLDER,
            'Type part number…',
            $storeId
        );
    }

    // --------------------------------------------------------------------
    // v1.2.1 — Storefront copy polish. Every remaining customer-visible
    // string that v1.1.1 didn't cover, plus 3 inline tooltip strings.
    // --------------------------------------------------------------------

    public function getNoResultsTitle(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_COPY_NO_RESULTS_TITLE,
            'No products match all your filters.',
            $storeId
        );
    }

    public function getNoResultsHint(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_COPY_NO_RESULTS_HINT,
            'Try removing one or two filters — fewer constraints usually find a match.',
            $storeId
        );
    }

    public function getUseFormPrompt(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_COPY_USE_FORM_PROMPT,
            'Use the form above to start.',
            $storeId
        );
    }

    public function getNoMatchesText(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_COPY_NO_MATCHES,
            'No matches',
            $storeId
        );
    }

    public function getDropdownSearchPlaceholder(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_COPY_DROPDOWN_SEARCH,
            'Search…',
            $storeId
        );
    }

    /**
     * Template for the PDP fitment badge overflow text. Supports the
     * `{count}` placeholder. Default: "and {count} more".
     */
    public function getFitmentOverflow(int $count, ?int $storeId = null): string
    {
        $template = $this->labelOrDefault(
            self::XML_PATH_COPY_FITMENT_OVERFLOW,
            'and {count} more',
            $storeId
        );
        return strtr($template, ['{count}' => (string) $count]);
    }

    public function getGarageTitle(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_COPY_GARAGE_TITLE, 'My Garage', $storeId);
    }

    public function getGarageClearButton(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_COPY_GARAGE_CLEAR, 'Clear Garage', $storeId);
    }

    public function getGarageRemoveLabel(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_COPY_GARAGE_REMOVE, 'Remove', $storeId);
    }

    public function getSavedFeedback(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_COPY_SAVED_FEEDBACK, 'Saved!', $storeId);
    }

    public function getSidebarNoFilters(?int $storeId = null): string
    {
        return $this->labelOrDefault(
            self::XML_PATH_COPY_SIDEBAR_NO_FILTERS,
            'No filters active.',
            $storeId
        );
    }

    public function getOemButtonText(?int $storeId = null): string
    {
        return $this->labelOrDefault(self::XML_PATH_COPY_OEM_BUTTON, 'Search', $storeId);
    }

    /** v1.2.1 — Customer-facing tooltips (rendered as title=""). Optional;
     *  blank = no tooltip rendered. */
    public function getFitmentTooltip(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_TOOLTIP_FITMENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return trim($value);
    }

    public function getGarageTooltip(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_TOOLTIP_GARAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return trim($value);
    }

    public function getOemTooltip(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_TOOLTIP_OEM,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return trim($value);
    }

    /**
     * v1.2.1 — Accent colour for buttons + filter chips. Default is the
     * eTechFlow blue (#0535F5). Validated to a 6-digit hex; anything
     * malformed falls back to the default. Surfaces via a CSS custom
     * property in templates so theme overrides also work.
     */
    public function getAccentColour(?int $storeId = null): string
    {
        $value = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_ACCENT_COLOUR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if (preg_match('/^#?[0-9a-f]{6}$/i', $value)) {
            return $value[0] === '#' ? $value : ('#' . $value);
        }
        return '#0535F5';
    }
}
