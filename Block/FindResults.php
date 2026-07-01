<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Block;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class FindResults extends Template
{
    private const PAGE_SIZE = 24;

    private CollectionFactory $productCollectionFactory;
    private RequestInterface $request;
    private ImageHelper $imageHelper;
    private PriceCurrencyInterface $priceCurrency;
    private ResourceConnection $resource;
    private StoreManagerInterface $storeManager;
    private \ETechFlow\ProductFitmentFinder\Model\Config $vcConfig;
    private \Magento\Framework\Data\Form\FormKey $formKey;
    private \Magento\Framework\View\DesignInterface $design;

    private ?Collection $cachedCollection = null;
    private ?bool $isHyva = null;

    public function __construct(
        Context $context,
        CollectionFactory $productCollectionFactory,
        RequestInterface $request,
        ImageHelper $imageHelper,
        PriceCurrencyInterface $priceCurrency,
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        \ETechFlow\ProductFitmentFinder\Model\Config $vcConfig,
        \Magento\Framework\Data\Form\FormKey $formKey,
        \Magento\Framework\View\DesignInterface $design,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->productCollectionFactory = $productCollectionFactory;
        $this->request = $request;
        $this->imageHelper = $imageHelper;
        $this->priceCurrency = $priceCurrency;
        $this->resource = $resource;
        $this->storeManager = $storeManager;
        $this->vcConfig = $vcConfig;
        $this->formKey = $formKey;
        $this->design = $design;
    }

    /**
     * Is the ACTIVE frontend theme a Hyva theme? Decisive gate for which product
     * card to render on the Find page: Hyva's ProductListItem ViewModel only emits
     * a card under a Hyva theme. The ViewModel can be present (Hyva module
     * installed) yet render empty on a Luma/custom theme, so we check the theme
     * chain rather than whether the ViewModel is non-null.
     */
    public function isHyvaTheme(): bool
    {
        if ($this->isHyva !== null) {
            return $this->isHyva;
        }
        $this->isHyva = false;
        try {
            $theme = $this->design->getDesignTheme();
            while ($theme) {
                if (stripos((string) $theme->getCode(), 'hyva') !== false) {
                    $this->isHyva = true;
                    break;
                }
                $theme = $theme->getParentTheme();
            }
        } catch (\Throwable $e) {
            $this->isHyva = false;
        }
        return $this->isHyva;
    }

    /** Form key for the non-Hyva fallback add-to-cart form (find page is uncacheable). */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /** Standard add-to-cart action URL for a product (non-Hyva fallback card). */
    public function getAddToCartUrl(Product $product): string
    {
        return $this->_urlBuilder->getUrl('checkout/cart/add', ['product' => (int) $product->getId()]);
    }

    /** v1.1.1 — admin-configurable copy for the Find page. */
    public function getFindPageTitle(): string     { return $this->vcConfig->getFindPageTitle(); }
    public function getEmptyStateMessage(): string { return $this->vcConfig->getEmptyStateMessage(); }

    public function hasAnyFilter(): bool
    {
        return ((int)$this->request->getParam('make_id') > 0)
            || ((int)$this->request->getParam('model_id') > 0)
            || ((int)$this->request->getParam('year') > 0)
            || ((int)$this->request->getParam('part_id') > 0)
            || $this->getOemTerm() !== '';
    }

    /**
     * v1.2.0 — Sanitised OEM/part-number search term from the request.
     * Empty string if none provided or stripped to nothing.
     */
    public function getOemTerm(): string
    {
        if (!$this->vcConfig->isOemSearchEnabled()) {
            return '';
        }
        $raw = (string) $this->request->getParam('oem', '');
        // Strip everything except alphanumerics, dash, dot, slash, underscore — common
        // characters in part numbers (e.g. "12-345/678.A"). Prevents LIKE-injection
        // even though we parameterise.
        $clean = preg_replace('/[^a-z0-9\-_.\/]/i', '', $raw) ?: '';
        return mb_substr($clean, 0, 64);
    }

    public function isOemSearchEnabled(): bool { return $this->vcConfig->isOemSearchEnabled(); }
    public function getOemSearchLabel(): string { return $this->vcConfig->getOemSearchLabel(); }
    public function getOemSearchPlaceholder(): string { return $this->vcConfig->getOemSearchPlaceholder(); }

    /** v1.2.1 — polish copy + tooltips + accent colour. */
    public function getNoResultsTitle(): string         { return $this->vcConfig->getNoResultsTitle(); }
    public function getNoResultsHint(): string          { return $this->vcConfig->getNoResultsHint(); }
    public function getUseFormPrompt(): string          { return $this->vcConfig->getUseFormPrompt(); }
    public function getSidebarNoFilters(): string       { return $this->vcConfig->getSidebarNoFilters(); }
    public function getOemButtonText(): string          { return $this->vcConfig->getOemButtonText(); }
    public function getOemTooltip(): string             { return $this->vcConfig->getOemTooltip(); }
    public function getAccentColour(): string           { return $this->vcConfig->getAccentColour(); }

    public function getFilterChips(): array
    {
        $conn = $this->resource->getConnection();
        $chips = [];
        $makeId  = (int) $this->request->getParam('make_id');
        $modelId = (int) $this->request->getParam('model_id');
        $year    = (int) $this->request->getParam('year');
        $partId  = (int) $this->request->getParam('part_id');
        if ($makeId > 0) {
            $n = (string) $conn->fetchOne("SELECT name FROM " . $this->resource->getTableName('etechflow_vehicle_make') . " WHERE make_id = ?", [$makeId]);
            if ($n) $chips[] = ['label' => __('Make'), 'value' => $n, 'color' => 'var(--vc-accent, #0535F5)'];
        }
        if ($modelId > 0) {
            $n = (string) $conn->fetchOne("SELECT name FROM " . $this->resource->getTableName('etechflow_vehicle_model') . " WHERE model_id = ?", [$modelId]);
            if ($n) $chips[] = ['label' => __('Model'), 'value' => $n, 'color' => 'var(--vc-accent, #0535F5)'];
        }
        if ($year > 0) {
            $chips[] = ['label' => __('Year'), 'value' => (string)$year, 'color' => 'var(--vc-accent, #0535F5)'];
        }
        if ($partId > 0) {
            $n = (string) $conn->fetchOne(
                "SELECT v.value FROM " . $this->resource->getTableName('eav_attribute_option') . " o JOIN "
                . $this->resource->getTableName('eav_attribute_option_value') . " v ON v.option_id = o.option_id WHERE o.option_id = ? AND v.store_id = 0",
                [$partId]
            );
            if ($n) $chips[] = ['label' => __('Part'), 'value' => $n, 'color' => 'var(--vc-accent, #0535F5)'];
        }
        return $chips;
    }

    public function getProductCollection(): Collection
    {
        if ($this->cachedCollection !== null) return $this->cachedCollection;

        $makeId  = (int) $this->request->getParam('make_id');
        $modelId = (int) $this->request->getParam('model_id');
        $year    = (int) $this->request->getParam('year');
        $partId  = (int) $this->request->getParam('part_id');
        $page    = max(1, (int) $this->request->getParam('p', 1));

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name','small_image','image','price','special_price','url_key','status','visibility']);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->setVisibility([Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]);
        $collection->setStoreId($this->storeManager->getStore()->getId());

        if (!$this->hasAnyFilter()) {
            $collection->addFieldToFilter('entity_id', ['eq' => 0]);
            return $this->cachedCollection = $collection;
        }

        if ($partId > 0) {
            $collection->addAttributeToFilter('parts_required', ['finset' => (string)$partId]);
        }
        if ($makeId > 0) {
            $collection->addAttributeToFilter('vehicle_compat_data', ['like' => '%"make_id":' . $makeId . ',%']);
        }
        if ($modelId > 0) {
            $collection->addAttributeToFilter('vehicle_compat_data', ['like' => '%"model_id":' . $modelId . ',%']);
        }

        // v1.2.0 — OEM/part-number search. When a search term is present,
        // OR-filter across every configured attribute code (default: just `sku`,
        // can be ["sku", "mpn", "manufacturer_part_number", "custom_oem"] etc).
        // This is an INTERSECT with any vehicle filters above — narrow by both.
        $oemTerm = $this->getOemTerm();
        if ($oemTerm !== '') {
            $codes = $this->vcConfig->getOemAttributeCodes();
            $filters = [];
            foreach ($codes as $code) {
                $filters[] = ['attribute' => $code, 'like' => '%' . $oemTerm . '%'];
            }
            if ($filters !== []) {
                // Magento collection: addAttributeToFilter with array means OR.
                $collection->addAttributeToFilter($filters);
            }
        }

        if ($year > 0 || ($makeId > 0 && $modelId > 0)) {
            $conn = $this->resource->getConnection();
            $attrId = (int) $conn->fetchOne(
                "SELECT attribute_id FROM " . $this->resource->getTableName('eav_attribute')
                . " WHERE entity_type_id = 4 AND attribute_code = 'vehicle_compat_data'"
            );
            $rows = $conn->fetchAll(
                "SELECT entity_id, value FROM " . $this->resource->getTableName('catalog_product_entity_text')
                . " WHERE attribute_id = ?",
                [$attrId]
            );
            $allowed = [];
            foreach ($rows as $r) {
                $decoded = json_decode((string)$r['value'], true);
                if (!is_array($decoded)) continue;
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) continue;
                    if ($makeId > 0 && (int)($entry['make_id'] ?? 0) !== $makeId) continue;
                    if ($modelId > 0 && (int)($entry['model_id'] ?? 0) !== $modelId) continue;
                    if ($year > 0) {
                        $years = array_map('intval', (array)($entry['years'] ?? []));
                        if (!in_array($year, $years, true)) continue;
                    }
                    $allowed[(int)$r['entity_id']] = true;
                    break;
                }
            }
            if (empty($allowed)) {
                $collection->addFieldToFilter('entity_id', ['eq' => 0]);
            } else {
                $collection->addFieldToFilter('entity_id', ['in' => array_keys($allowed)]);
            }
        }

        $collection->setPageSize(self::PAGE_SIZE)->setCurPage($page);
        return $this->cachedCollection = $collection;
    }

    public function getProductImageUrl(Product $product): string
    {
        try {
            return $this->imageHelper
                ->init($product, 'category_page_grid')
                ->setImageFile($product->getSmallImage() ?: $product->getImage())
                ->getUrl();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getProductDetailUrl(Product $product): string
    {
        $urlKey = (string) $product->getData('url_key');
        return $urlKey !== ''
            ? $this->_urlBuilder->getUrl($urlKey)
            : $product->getProductUrl();
    }

    public function formatPrice(Product $product): string
    {
        $price = (float) ($product->getData('special_price') ?: $product->getData('price'));
        if ($price <= 0) return '';
        return (string) $this->priceCurrency->format($price, false);
    }

    public function getPagerHtml(): string
    {
        $collection = $this->getProductCollection();
        $totalPages = (int) $collection->getLastPageNumber();
        if ($totalPages <= 1) return '';
        $currentPage = (int) $collection->getCurPage();
        $baseUrl = $this->_urlBuilder->getCurrentUrl();
        $baseUrl = preg_replace('/([?&])p=\d+/', '$1', $baseUrl);
        $baseUrl = rtrim($baseUrl, '&?');

        $html = '<nav class="vehiclecompat-pager" style="display:flex;justify-content:center;gap:6px;margin-top:24px;flex-wrap:wrap">';
        for ($i = 1; $i <= $totalPages; $i++) {
            $sep = (strpos($baseUrl, '?') === false) ? '?' : '&';
            $url = $baseUrl . $sep . 'p=' . $i;
            $active = ($i === $currentPage);
            $style = $active
                ? 'background:var(--vc-accent,#0535F5);color:#fff;font-weight:800;border-color:var(--vc-accent,#0535F5)'
                : 'background:#fff;color:#374151';
            $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 10px;border:1px solid #e5e7eb;border-radius:6px;text-decoration:none;font-size:.85rem;font-weight:600;' . $style . '">' . $i . '</a>';
        }
        $html .= '</nav>';
        return $html;
    }
}
