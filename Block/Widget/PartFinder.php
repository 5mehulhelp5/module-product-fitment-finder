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
        private readonly \Magento\Framework\Registry $registry,
        private readonly \Magento\Framework\App\ResourceConnection $resource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Render a sibling partial (the shared styles / form fragment) in this
     * block's own context. Used by widget.phtml instead of a raw include(),
     * which the Marketplace security pre-check (Magento2.Security.IncludeFile)
     * forbids. fetchView() exposes $block (= $this) and $escaper to the partial,
     * exactly as the previous include did.
     *
     * @param string $template
     * @return string
     */
    public function renderFragment(string $template): string
    {
        return $this->fetchView($this->getTemplateFile($template));
    }

    /**
     * Server-resolved selection for a pre-filled finder (e.g. the Find results
     * page reached via ?make_id=… or /parts/bmw/…). The finder JS hydrates IDs
     * from the URL but not the NAMES, so without this the pre-filled finder shows
     * "Select Make" and — critically — Save Selection silently fails (the garage
     * save needs makeLabel). Emitted as window.etechflowPreselect by widget.phtml.
     *
     * @return array<string,string>|null null when nothing is pre-selected
     */
    public function getPreselection(): ?array
    {
        $req = $this->getRequest();
        $makeId = (int) $req->getParam('make_id');
        if ($makeId <= 0) {
            return null;
        }
        $conn = $this->resource->getConnection();
        $makeName = (string) $conn->fetchOne(
            "SELECT name FROM " . $this->resource->getTableName('etechflow_vehicle_make') . " WHERE make_id = ?",
            [$makeId]
        );
        if ($makeName === '') {
            return null;
        }
        $sel = ['make' => (string) $makeId, 'makeLabel' => $makeName];

        $modelId = (int) $req->getParam('model_id');
        if ($modelId > 0) {
            $modelName = (string) $conn->fetchOne(
                "SELECT name FROM " . $this->resource->getTableName('etechflow_vehicle_model')
                . " WHERE model_id = ? AND make_id = ?",
                [$modelId, $makeId]
            );
            if ($modelName !== '') {
                $sel['model'] = (string) $modelId;
                $sel['modelLabel'] = $modelName;
            }
        }
        $year = (int) $req->getParam('year');
        if ($year > 0) {
            $sel['year'] = (string) $year;
        }
        $partId = (int) $req->getParam('part_id');
        if ($partId > 0) {
            $eav = $this->resource->getTableName('eav_attribute');
            $attrId = (int) $conn->fetchOne(
                "SELECT attribute_id FROM $eav WHERE entity_type_id = 4 AND attribute_code = 'parts_required'"
            );
            $partName = $attrId ? (string) $conn->fetchOne(
                "SELECT value FROM " . $this->resource->getTableName('eav_attribute_option_value')
                . " WHERE option_id = ? AND store_id = 0",
                [$partId]
            ) : '';
            if ($partName !== '') {
                $sel['part'] = (string) $partId;
                $sel['partLabel'] = $partName;
            }
        }
        return $sel;
    }

    /**
     * Base URL the "Find Parts" button navigates to, carrying the chosen
     * make/model/year/part as query params (?make_id=…&model_id=…).
     *
     * Target:
     *  - an explicit `find_url` block/widget arg always wins;
     *  - `find_target="current"` opts INTO refining the current category in place
     *    (only reliable when layered nav honours an entity_id constraint — e.g.
     *    flat/DB collections; OpenSearch fulltext categories do not, so this is
     *    opt-in, not the default);
     *  - default → the dedicated whole-catalog Find page, which renders results
     *    reliably on every theme + every layered-nav backend.
     */
    public function getFindUrl(): string
    {
        $explicit = (string) $this->getData('find_url');
        if ($explicit !== '') {
            return $explicit;
        }
        if ((string) $this->getData('find_target') === 'current') {
            try {
                $category = $this->registry->registry('current_category');
                if ($category && (int) $category->getId() > 0) {
                    return $category->getUrl();
                }
            } catch (\Throwable $e) {
                // fall through to the Find page
            }
        }
        return $this->getUrl('vehiclecompat/find');
    }

    // Customer-facing copy — all admin-configurable so the same form rebrands
    // to any fitment domain. Mirrors the getters PartFinderData exposes for the
    // find-page templates; both delegate to Model\Config.
    /** Heading rendered above the form (admin-configurable; blank = no heading). */
    public function getPartFinderHeading(): string { return $this->config->getPartFinderHeading(); }

    /** Accent colour fallback for themes that don't style the `action primary` button. */
    public function getAccentColour(): string { return $this->config->getAccentColour(); }

    public function getMakeLabel(): string       { return $this->config->getMakeLabel(); }
    public function getModelLabel(): string      { return $this->config->getModelLabel(); }
    public function getYearLabel(): string       { return $this->config->getYearLabel(); }
    public function getPartLabel(): string       { return $this->config->getPartLabel(); }
    public function isYearFieldEnabled(): bool   { return $this->config->isYearFieldEnabled(); }
    public function getFindButtonText(): string  { return $this->config->getFindButtonText(); }
    public function isSavedGarageEnabled(): bool { return $this->config->isSavedGarageEnabled(); }
    public function getSaveButtonText(): string  { return $this->config->getSaveButtonText(); }

    // v1.2.1 — polish copy consumed by the shared form fragment (form.phtml).
    // These were exposed only on the orphaned PartFinderData block (never placed
    // in any layout), so form.phtml fell back to hardcoded __() literals and the
    // admin overrides did nothing. Wiring them here — the live host block — makes
    // the "Dropdown Search Placeholder", "No Matches" and "Saved!" fields actually
    // take effect.
    public function getNoMatchesText(): string            { return $this->config->getNoMatchesText(); }
    public function getDropdownSearchPlaceholder(): string { return $this->config->getDropdownSearchPlaceholder(); }
    public function getSavedFeedback(): string            { return $this->config->getSavedFeedback(); }

    // v1.2.0 — OEM / part-number search, now surfaced WITH the finder form (it
    // used to render only on the Find page). All copy is admin-configurable; the
    // box is a native GET form that submits to getFindUrl() (the Find page), which
    // runs the actual attribute LIKE search in FindResults.
    public function isOemSearchEnabled(): bool        { return $this->config->isOemSearchEnabled(); }
    public function getOemSearchLabel(): string       { return $this->config->getOemSearchLabel(); }
    public function getOemSearchPlaceholder(): string { return $this->config->getOemSearchPlaceholder(); }
    public function getOemButtonText(): string        { return $this->config->getOemButtonText(); }
    public function getOemTooltip(): string           { return $this->config->getOemTooltip(); }

    /**
     * Current ?oem term (sanitised) so the input keeps its value after a search
     * on the Find page. Same whitelist as FindResults::getOemTerm().
     */
    public function getOemTerm(): string
    {
        if (!$this->config->isOemSearchEnabled()) {
            return '';
        }
        $raw   = (string) $this->getRequest()->getParam('oem', '');
        $clean = preg_replace('/[^a-z0-9\-_.\/]/i', '', $raw) ?: '';
        return mb_substr($clean, 0, 64);
    }

    /**
     * Admin-controlled placement gate. Layout placements (home / product /
     * product_list) are tagged with a `vc_location` arg and render only when they
     * match the single location the admin picked in
     * Stores → Config → Product Fitment Finder → Part Finder Placement.
     *
     * Instances WITHOUT a vc_location arg — the Find page's own sidebar form and
     * any merchant-dropped {{widget}} / CMS block — are never gated, so they
     * always render.
     */
    protected function _toHtml()
    {
        // Licence gate: an unlicensed store renders no Part Finder form anywhere —
        // Find-page sidebar, {{widget}}, CMS block or an auto-placement. isEnabled()
        // folds in LicenseValidator::isValid(), so this is the same silence the
        // module shows when the admin master toggle is off.
        if (!$this->config->isEnabled()) {
            return '';
        }
        $location = (string) $this->getData('vc_location');
        if ($location !== '' && !$this->config->isPartFinderEnabledFor($location)) {
            return '';
        }
        return parent::_toHtml();
    }
}
