<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Model\Attribute\Backend;

use ETechFlow\ProductFitmentFinder\Model\ResourceModel\Make\CollectionFactory as MakeCollectionFactory;
use ETechFlow\ProductFitmentFinder\Model\ResourceModel\Model\CollectionFactory as ModelCollectionFactory;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;

/**
 * Stores vehicle_compat_data as JSON. Accepts both flat
 * ([{make_id, model_id, years},...]) and grouped
 * ([{make_id, models:[{model_id, years},...]}]) input shapes.
 * Always stores flat for predictability — frontend/migration both group at render time.
 *
 * This backend is the RELIABLE enrichment hook: an admin product save goes through
 * ProductRepository::save() → the resource model, which fires attribute backends
 * (this class) but NOT the Product model's beforeSave plugins. So make_name /
 * model_name — which the admin form's hidden fields leave empty when a Make/Model
 * is picked from the native select — are resolved here from make_id / model_id, so
 * the storefront badge/finder can render without joining the vehicle tables.
 */
class JsonBackend extends AbstractBackend
{
    /** @var array<int,string>|null */
    private ?array $makeMap = null;
    /** @var array<int,string>|null */
    private ?array $modelMap = null;

    public function beforeSave($object)
    {
        $code = $this->getAttribute()->getAttributeCode();
        $value = $object->getData($code);

        // The admin dynamicRows grid submits a DOUBLED shape: its authoritative
        // rows are nested one level under the field name itself, e.g.
        //   ['0' => <stale modifyData seed>, 'vehicle_compat_data' => [<real rows>]]
        // This is Magento's dynamic-rows recordData binding
        // ('${ $.provider }:${ $.dataScope }.${ $.index }') appending its own index
        // (the node key) to the scope — unavoidable from the form config. Unwrap it
        // here so we persist the grid's real export instead of the stale seed, which
        // silently kept the old value on every admin save. Idempotent for the clean
        // (programmatic / API) shape, which has no such nested key.
        if (is_array($value) && isset($value[$code]) && is_array($value[$code])) {
            $value = $value[$code];
        }

        if (is_array($value)) {
            $rows = $this->normalizeFlat($value);
            $object->setData($code, $rows ? json_encode($rows, JSON_UNESCAPED_UNICODE) : null);
        } elseif (is_string($value) && trim($value) === '') {
            $object->setData($code, null);
        }
        return parent::beforeSave($object);
    }

    private function normalizeFlat(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            /* Grouped shape: expand into flat rows */
            if (isset($row['models']) && is_array($row['models'])) {
                $makeId   = (int)($row['make_id'] ?? 0);
                if ($makeId <= 0) continue;
                $makeName = $this->resolveMakeName($makeId, (string)($row['make_name'] ?? ''));
                foreach ($row['models'] as $m) {
                    if (!is_array($m)) continue;
                    $modelId = (int)($m['model_id'] ?? 0);
                    if ($modelId <= 0) continue;
                    $out[] = [
                        'make_id'    => $makeId,
                        'make_name'  => $makeName,
                        'model_id'   => $modelId,
                        'model_name' => $this->resolveModelName($modelId, (string)($m['model_name'] ?? '')),
                        'years'      => $this->cleanYears($m['years'] ?? []),
                    ];
                }
                continue;
            }

            /* Flat shape */
            $makeId  = (int)($row['make_id'] ?? 0);
            $modelId = (int)($row['model_id'] ?? 0);
            // A Make is required; the Model is optional. A Make-only fitment
            // ("fits all BMW") is valid for universal parts and the PDP badge
            // already renders it — so we no longer discard a row that has a
            // Make but no Model (previously this silently dropped the row and
            // wiped vehicle_compat_data on save).
            if ($makeId <= 0) continue;
            $out[] = [
                'make_id'    => $makeId,
                'make_name'  => $this->resolveMakeName($makeId, (string)($row['make_name'] ?? '')),
                'model_id'   => $modelId,
                'model_name' => $modelId > 0
                    ? $this->resolveModelName($modelId, (string)($row['model_name'] ?? ''))
                    : (string)($row['model_name'] ?? ''),
                'years'      => $this->cleanYears($row['years'] ?? []),
            ];
        }
        return $out;
    }

    /** Keep a supplied name, else resolve it from the make id. */
    private function resolveMakeName(int $makeId, string $supplied): string
    {
        return $supplied !== '' ? $supplied : $this->getMakeName($makeId);
    }

    /** Keep a supplied name, else resolve it from the model id. */
    private function resolveModelName(int $modelId, string $supplied): string
    {
        return $supplied !== '' ? $supplied : $this->getModelName($modelId);
    }

    /**
     * EAV attribute backends can be rebuilt from a cached attribute without their
     * constructor running, so injected dependencies are unreliable here. Resolve
     * the collection factories lazily via ObjectManager (the same pattern the
     * form modifier uses for its option sources). Cached per instance.
     */
    private function getMakeName(int $id): string
    {
        if ($this->makeMap === null) {
            $this->makeMap = [];
            $factory = \Magento\Framework\App\ObjectManager::getInstance()->get(MakeCollectionFactory::class);
            foreach ($factory->create() as $m) {
                $this->makeMap[(int)$m->getId()] = (string)$m->getData('name');
            }
        }
        return $this->makeMap[$id] ?? '';
    }

    private function getModelName(int $id): string
    {
        if ($this->modelMap === null) {
            $this->modelMap = [];
            $factory = \Magento\Framework\App\ObjectManager::getInstance()->get(ModelCollectionFactory::class);
            foreach ($factory->create() as $m) {
                $this->modelMap[(int)$m->getId()] = (string)$m->getData('name');
            }
        }
        return $this->modelMap[$id] ?? '';
    }

    private function cleanYears($years): array
    {
        $ys = array_values(array_filter(array_map('intval', (array)$years)));
        sort($ys);
        return $ys;
    }
}
