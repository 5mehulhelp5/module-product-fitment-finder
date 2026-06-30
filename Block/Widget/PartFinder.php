<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Block\Widget;

use ETechFlow\ProductFitmentFinder\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Widget\Block\BlockInterface;

/**
 * Host block for the shared Find-Your-Parts form fragment
 * (templates/partfinder/form.phtml).
 *
 * The fragment is markup-only: it must be wrapped in an element carrying
 * x-data="vehicleCompatPartFinder('<findUrl>')" (the Alpine factory is loaded
 * globally by default.xml). This block + its template (partfinder/widget.phtml)
 * provide that wrapper so the Part Finder actually renders on a page — both as
 * a CMS/layout widget (etc/widget.xml) the merchant can drop anywhere, and on
 * the dedicated Find page sidebar.
 *
 * Previously the fragment had NO host placement at all, so the entire Part
 * Finder — and every admin-configurable label (Make/Model/Year/Part, button
 * text, Show-Year toggle) — was invisible on the storefront.
 *
 * Implements Widget\BlockInterface so it is selectable in admin → Content →
 * Widgets and via the {{widget}} directive in CMS blocks/pages.
 */
class PartFinder extends Template implements BlockInterface
{
    /** Default template so the widget renders without a template parameter. */
    protected $_template = 'ETechFlow_ProductFitmentFinder::partfinder/widget.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Base URL the "Find Parts" button navigates to, carrying the chosen
     * make/model/year/part as query params (?make_id=…&model_id=…).
     */
    public function getFindUrl(): string
    {
        return $this->getUrl('vehiclecompat/find');
    }

    // Customer-facing copy — all admin-configurable so the same form rebrands
    // to any fitment domain. Mirrors the getters PartFinderData exposes for the
    // find-page templates; both delegate to Model\Config.
    public function getMakeLabel(): string       { return $this->config->getMakeLabel(); }
    public function getModelLabel(): string      { return $this->config->getModelLabel(); }
    public function getYearLabel(): string       { return $this->config->getYearLabel(); }
    public function getPartLabel(): string       { return $this->config->getPartLabel(); }
    public function isYearFieldEnabled(): bool   { return $this->config->isYearFieldEnabled(); }
    public function getFindButtonText(): string  { return $this->config->getFindButtonText(); }
    public function isSavedGarageEnabled(): bool { return $this->config->isSavedGarageEnabled(); }
    public function getSaveButtonText(): string  { return $this->config->getSaveButtonText(); }
}
