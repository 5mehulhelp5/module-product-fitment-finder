<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Plugin;

use ETechFlow\ProductFitmentFinder\Model\ResourceModel\Make\CollectionFactory as MakeCollectionFactory;
use ETechFlow\ProductFitmentFinder\Model\ResourceModel\Model\CollectionFactory as ModelCollectionFactory;
use Magento\Catalog\Model\Product;

/**
 * Attach make_name / model_name to flat vehicle_compat_data rows on save.
 * Frontend can then render without joining tables.
 */
class ProductActionPlugin
{
    private const FIELD = 'vehicle_compat_data';

    private MakeCollectionFactory  $makeFactory;
    private ModelCollectionFactory $modelFactory;
    private ?array $makeMap  = null;
    private ?array $modelMap = null;

    public function __construct(MakeCollectionFactory $makeFactory, ModelCollectionFactory $modelFactory)
    {
        $this->makeFactory  = $makeFactory;
        $this->modelFactory = $modelFactory;
    }

    public function beforeSave(Product $subject)
    {
        $data = $subject->getData(self::FIELD);

        // The admin dynamicRows grid submits a DOUBLED shape — the authoritative
        // rows are nested one level under the field name itself:
        //   ['0' => <stale modifyData seed>, 'vehicle_compat_data' => [<real rows>]]
        // JsonBackend::beforeSave unwraps the same way. Without this, the loop
        // below iterates the wrapper keys instead of the real rows, so make_name /
        // model_name never get enriched and persist EMPTY — which breaks the
        // storefront fitment badge/finder (they render from make_name/model_name
        // without joining the vehicle tables). Idempotent for the clean flat /
        // programmatic (API) shape, which has no such nested key.
        if (is_array($data) && isset($data[self::FIELD]) && is_array($data[self::FIELD])) {
            $data = $data[self::FIELD];
        }

        if (!is_array($data)) {
            return null;
        }
        foreach ($data as &$row) {
            if (!is_array($row)) continue;

            /* Flat */
            if (isset($row['model_id'])) {
                $makeId  = (int)($row['make_id'] ?? 0);
                $modelId = (int)$row['model_id'];
                if ($makeId > 0 && empty($row['make_name'])) {
                    $row['make_name'] = $this->getMakeName($makeId);
                }
                if ($modelId > 0 && empty($row['model_name'])) {
                    $row['model_name'] = $this->getModelName($modelId);
                }
                continue;
            }

            /* Grouped */
            $makeId = (int)($row['make_id'] ?? 0);
            if ($makeId > 0 && empty($row['make_name'])) {
                $row['make_name'] = $this->getMakeName($makeId);
            }
            foreach ((array)($row['models'] ?? []) as &$model) {
                if (!is_array($model)) continue;
                $modelId = (int)($model['model_id'] ?? 0);
                if ($modelId > 0 && empty($model['model_name'])) {
                    $model['model_name'] = $this->getModelName($modelId);
                }
            }
            unset($model);
        }
        unset($row);

        $subject->setData(self::FIELD, $data);
        return null;
    }

    private function getMakeName(int $id): string
    {
        if ($this->makeMap === null) {
            $this->makeMap = [];
            foreach ($this->makeFactory->create() as $m) {
                $this->makeMap[(int)$m->getId()] = (string)$m->getData('name');
            }
        }
        return $this->makeMap[$id] ?? '';
    }

    private function getModelName(int $id): string
    {
        if ($this->modelMap === null) {
            $this->modelMap = [];
            foreach ($this->modelFactory->create() as $m) {
                $this->modelMap[(int)$m->getId()] = (string)$m->getData('name');
            }
        }
        return $this->modelMap[$id] ?? '';
    }
}
