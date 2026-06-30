<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Block;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Framework\App\Cache\Type\Block as BlockCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Aggregates `vehicle_compat_data` (JSON) + `parts_required` (multiselect)
 * across every saved product into a single JSON tree that the header
 * Find-Your-Parts modal consumes for cascading filters.
 *
 * Cached forever under `etechflow_vehicle_compat_tree`, tagged with
 * CatalogProduct::CACHE_TAG so any product save invalidates it.
 *
 * Tree shape:
 *   {
 *     "makes": [
 *       {
 *         "id": <make_id>,
 *         "name": "<make_name>",
 *         "models": [
 *           {
 *             "id": <model_id>,
 *             "name": "<model_name>",
 *             "years": [yyyy, …],
 *             "parts": [<part_option_id>, …]
 *           }
 *         ]
 *       }
 *     ],
 *     "parts": [
 *       {"id": <option_id>, "name": "<part name>"}
 *     ]
 *   }
 */
class PartFinderData extends Template
{
    private const CACHE_KEY = 'etechflow_vehicle_compat_tree_v2';

    private ResourceConnection $resource;
    private CacheInterface $cache;
    private SerializerInterface $serializer;
    private \ETechFlow\ProductFitmentFinder\Model\Config $config;
    private ?array $tree = null;

    public function __construct(
        Context $context,
        ResourceConnection $resource,
        CacheInterface $cache,
        SerializerInterface $serializer,
        \ETechFlow\ProductFitmentFinder\Model\Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->resource   = $resource;
        $this->cache      = $cache;
        $this->serializer = $serializer;
        $this->config     = $config;
    }

    /** Customer-facing label for the Make dropdown — admin-configurable since v1.0.2. */
    public function getMakeLabel(): string  { return $this->config->getMakeLabel(); }

    /** Customer-facing label for the Model dropdown — admin-configurable since v1.0.2. */
    public function getModelLabel(): string { return $this->config->getModelLabel(); }

    /** Customer-facing label for the Year dropdown — admin-configurable since v1.0.2. */
    public function getYearLabel(): string  { return $this->config->getYearLabel(); }

    /** Customer-facing label for the Parts Required dropdown — admin-configurable since v1.0.2. */
    public function getPartLabel(): string  { return $this->config->getPartLabel(); }

    /** Whether the Year row should render at all — admin-configurable since v1.0.2. */
    public function isYearFieldEnabled(): bool { return $this->config->isYearFieldEnabled(); }

    /** v1.1.1 — Universal customer-facing copy (button + page title + save + empty states). */
    public function getFindButtonText(): string     { return $this->config->getFindButtonText(); }
    public function getFindPageTitle(): string      { return $this->config->getFindPageTitle(); }
    public function getEmptyStateMessage(): string  { return $this->config->getEmptyStateMessage(); }
    public function getSaveButtonText(): string     { return $this->config->getSaveButtonText(); }
    public function getGarageEmptyPrompt(): string  { return $this->config->getGarageEmptyPrompt(); }

    /** v1.1.1 — Garage availability so templates can show the Save button conditionally. */
    public function isSavedGarageEnabled(): bool    { return $this->config->isSavedGarageEnabled(); }

    /** v1.2.1 — polish copy + accent colour for the form template. */
    public function getNoMatchesText(): string          { return $this->config->getNoMatchesText(); }
    public function getDropdownSearchPlaceholder(): string { return $this->config->getDropdownSearchPlaceholder(); }
    public function getSavedFeedback(): string          { return $this->config->getSavedFeedback(); }
    public function getAccentColour(): string           { return $this->config->getAccentColour(); }

    public function getTreeJson(): string
    {
        return $this->serializer->serialize($this->getTree());
    }

    public function getTree(): array
    {
        if ($this->tree !== null) {
            return $this->tree;
        }
        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            $this->tree = $this->serializer->unserialize($cached);
            return $this->tree;
        }
        $this->tree = $this->buildTree();
        $this->cache->save(
            $this->serializer->serialize($this->tree),
            self::CACHE_KEY,
            [CatalogProduct::CACHE_TAG, BlockCache::CACHE_TAG],
            null
        );
        return $this->tree;
    }

    private function buildTree(): array
    {
        $conn = $this->resource->getConnection();

        // Step 1: resolve attribute ids
        $compatAttrId = (int)$conn->fetchOne(
            "SELECT attribute_id FROM " . $this->resource->getTableName('eav_attribute')
            . " WHERE entity_type_id = 4 AND attribute_code = 'vehicle_compat_data'"
        );
        $partsAttrId  = (int)$conn->fetchOne(
            "SELECT attribute_id FROM " . $this->resource->getTableName('eav_attribute')
            . " WHERE entity_type_id = 4 AND attribute_code = 'parts_required'"
        );
        if ($compatAttrId <= 0) return ['makes' => [], 'parts' => []];

        // Step 1b: canonical Make/Model names from the source tables. The
        // *_name fields denormalised into vehicle_compat_data are left EMPTY by
        // admin product-form saves (the form has no JS to backfill them), so we
        // resolve names by id here instead of trusting the stored copy. Falls
        // back to the stored name only when a table row is missing.
        $makeNameMap  = $conn->fetchPairs(
            "SELECT make_id, name FROM " . $this->resource->getTableName('etechflow_vehicle_make')
        );
        $modelNameMap = $conn->fetchPairs(
            "SELECT model_id, name FROM " . $this->resource->getTableName('etechflow_vehicle_model')
        );

        // Step 2: parts_required option map (option_id => label)
        $partLabels = [];
        if ($partsAttrId > 0) {
            $rows = $conn->fetchAll(
                "SELECT o.option_id, v.value
                   FROM " . $this->resource->getTableName('eav_attribute_option') . " o
                   JOIN " . $this->resource->getTableName('eav_attribute_option_value') . " v ON v.option_id = o.option_id
                  WHERE o.attribute_id = ? AND v.store_id = 0",
                [$partsAttrId]
            );
            foreach ($rows as $r) {
                $partLabels[(int)$r['option_id']] = (string)$r['value'];
            }
        }

        // Step 3: load every product's vehicle_compat_data + parts_required
        // Use a self-join so we only scan products that actually have the JSON.
        $sql = "SELECT c.entity_id, c.value AS compat, p.value AS parts
                  FROM " . $this->resource->getTableName('catalog_product_entity_text') . " c
             LEFT JOIN " . $this->resource->getTableName('catalog_product_entity_varchar') . " p
                    ON p.entity_id = c.entity_id AND p.attribute_id = ? AND p.store_id = 0
                 WHERE c.attribute_id = ? AND c.store_id = 0 AND c.value IS NOT NULL AND c.value <> ''";
        $rows = $conn->fetchAll($sql, [$partsAttrId, $compatAttrId]);

        // Step 4: aggregate
        $makes = [];   // [make_id] => ['name' => , 'models' => [model_id => ['name', years => set, parts => set]]]
        foreach ($rows as $row) {
            $decoded = json_decode((string)$row['compat'], true);
            if (!is_array($decoded)) continue;
            $productParts = [];
            if (!empty($row['parts'])) {
                foreach (explode(',', (string)$row['parts']) as $p) {
                    $pi = (int)trim($p);
                    if ($pi > 0) $productParts[$pi] = true;
                }
            }
            foreach ($decoded as $entry) {
                if (!is_array($entry)) continue;
                $makeId   = (int)($entry['make_id'] ?? 0);
                $modelId  = (int)($entry['model_id'] ?? 0);
                if ($makeId <= 0 || $modelId <= 0) continue;
                // Authoritative name from the table; stored *_name is only a fallback.
                $makeName  = (string)($makeNameMap[$makeId]   ?? ($entry['make_name']  ?? ''));
                $modelName = (string)($modelNameMap[$modelId] ?? ($entry['model_name'] ?? ''));

                if (!isset($makes[$makeId])) {
                    $makes[$makeId] = ['name' => $makeName, 'models' => []];
                } elseif ($makes[$makeId]['name'] === '' && $makeName !== '') {
                    $makes[$makeId]['name'] = $makeName;
                }
                if (!isset($makes[$makeId]['models'][$modelId])) {
                    $makes[$makeId]['models'][$modelId] = [
                        'name'  => $modelName,
                        'years' => [],
                        'parts' => [],
                    ];
                } elseif ($makes[$makeId]['models'][$modelId]['name'] === '' && $modelName !== '') {
                    $makes[$makeId]['models'][$modelId]['name'] = $modelName;
                }
                foreach ((array)($entry['years'] ?? []) as $y) {
                    $yi = (int)$y;
                    if ($yi > 0) $makes[$makeId]['models'][$modelId]['years'][$yi] = true;
                }
                foreach ($productParts as $pi => $_) {
                    $makes[$makeId]['models'][$modelId]['parts'][$pi] = true;
                }
            }
        }

        // Step 5: serialise — sort makes/models alphabetically, years desc, parts by label
        $makesOut = [];
        $makeIds = array_keys($makes);
        usort($makeIds, function ($a, $b) use ($makes) {
            return strcasecmp($makes[$a]['name'], $makes[$b]['name']);
        });
        foreach ($makeIds as $makeId) {
            $m = $makes[$makeId];
            $modelIds = array_keys($m['models']);
            usort($modelIds, function ($a, $b) use ($m) {
                return strcasecmp($m['models'][$a]['name'], $m['models'][$b]['name']);
            });
            $modelsOut = [];
            foreach ($modelIds as $modelId) {
                $mod = $m['models'][$modelId];
                $years = array_keys($mod['years']);
                rsort($years);
                $parts = array_keys($mod['parts']);
                usort($parts, function ($a, $b) use ($partLabels) {
                    return strcasecmp($partLabels[$a] ?? '', $partLabels[$b] ?? '');
                });
                $modelsOut[] = [
                    'id'    => $modelId,
                    'name'  => $mod['name'],
                    'years' => array_values($years),
                    'parts' => array_values($parts),
                ];
            }
            $makesOut[] = [
                'id'     => $makeId,
                'name'   => $m['name'],
                'models' => $modelsOut,
            ];
        }

        $partsOut = [];
        foreach ($partLabels as $oid => $label) {
            $partsOut[] = ['id' => $oid, 'name' => $label];
        }
        usort($partsOut, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['makes' => $makesOut, 'parts' => $partsOut];
    }
}
