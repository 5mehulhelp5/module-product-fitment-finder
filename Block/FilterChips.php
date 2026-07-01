<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Block;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Renders the active vehicle/parts filter chips at the top of any product
 * list page that has ?make_id/?model_id/?year/?part_id in the URL.
 * Self-disables when no params are present (returns null collection).
 */
class FilterChips extends Template
{
    private RequestInterface $request;
    private ResourceConnection $resource;

    public function __construct(
        Context $context,
        RequestInterface $request,
        ResourceConnection $resource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->request  = $request;
        $this->resource = $resource;
    }

    public function hasAnyFilter(): bool
    {
        return ((int)$this->request->getParam('make_id') > 0)
            || ((int)$this->request->getParam('model_id') > 0)
            || ((int)$this->request->getParam('year') > 0)
            || ((int)$this->request->getParam('part_id') > 0);
    }

    public function getChips(): array
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

    public function getClearUrl(): string
    {
        $currentUrl = $this->_urlBuilder->getCurrentUrl();
        $parts = parse_url($currentUrl);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '/');
    }

    protected function _toHtml()
    {
        if (!$this->hasAnyFilter()) return '';
        return parent::_toHtml();
    }
}
