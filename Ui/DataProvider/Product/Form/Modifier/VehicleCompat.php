<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Ui\DataProvider\Product\Form\Modifier;

use ETechFlow\ProductFitmentFinder\Model\Source\Make;
use ETechFlow\ProductFitmentFinder\Model\Source\Model as ModelSource;
use ETechFlow\ProductFitmentFinder\Model\Source\Year;
use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Ui\Component\Container;
use Magento\Ui\Component\DynamicRows;
use Magento\Ui\Component\Form\Element\Checkbox;
use Magento\Ui\Component\Form\Element\DataType\Number;
use Magento\Ui\Component\Form\Element\Select;
use Magento\Ui\Component\Form\Field;
use Magento\Ui\Component\Form\Fieldset;

/**
 * Flat dynamicRows: each row = Make + Model + Years.
 * Multiple rows for same Make are allowed. Same-level filterBy keeps
 * the Model dropdown showing only models for the row's own Make.
 */
class VehicleCompat extends AbstractModifier
{
    public const GROUP_NAME = 'vehicle_compat';
    public const FIELD_NAME = 'vehicle_compat_data';
    private const GROUP_SORT_ORDER = 35;

    private LocatorInterface $locator;
    private Make $makeSource;
    private ModelSource $modelSource;
    private Year $yearSource;
    private UrlInterface $urlBuilder;

    public function __construct(
        LocatorInterface $locator,
        Make $makeSource,
        ModelSource $modelSource,
        Year $yearSource,
        UrlInterface $urlBuilder
    ) {
        $this->locator     = $locator;
        $this->makeSource  = $makeSource;
        $this->modelSource = $modelSource;
        $this->yearSource  = $yearSource;
        $this->urlBuilder  = $urlBuilder;
    }

    /**
     * Decode JSON from EAV into FLAT rows for the dynamicRows component.
     * The grouped shape ([{make,models:[…]}]) becomes a flat array
     * ([{make_id,model_id,years},…]).
     */
    public function modifyData(array $data): array
    {
        $productId = $this->locator->getProduct()->getId();
        if (!$productId) return $data;

        $raw = $this->locator->getProduct()->getData(self::FIELD_NAME);
        $rows = [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $rows = $this->flattenGroupedShape($decoded);
            }
        } elseif (is_array($raw)) {
            $rows = $this->flattenGroupedShape($raw);
        }

        if (!isset($data[$productId]['product'])) {
            $data[$productId]['product'] = [];
        }
        $data[$productId]['product'][self::FIELD_NAME] = $rows;

        // parts_required is stored as CSV "1071,1072,…" — multiselect needs an array.
        $partsRaw = $this->locator->getProduct()->getData('parts_required');
        if (is_string($partsRaw) && $partsRaw !== '') {
            $data[$productId]['product']['parts_required'] = array_values(array_filter(
                array_map('trim', explode(',', $partsRaw)),
                fn($v) => $v !== ''
            ));
        } elseif (is_array($partsRaw)) {
            $data[$productId]['product']['parts_required'] = $partsRaw;
        }

        return $data;
    }

    /**
     * Convert grouped { make, models[{model, years}] } → flat [{make_id, model_id, years}].
     * Also accepts already-flat input and passes it through.
     */
    private function flattenGroupedShape(array $stored): array
    {
        $flat = [];
        foreach ($stored as $entry) {
            if (!is_array($entry)) continue;
            /* Already flat row */
            if (isset($entry['model_id']) && !isset($entry['models'])) {
                $flat[] = [
                    'make_id'    => (int)($entry['make_id'] ?? 0),
                    'make_name'  => (string)($entry['make_name'] ?? ''),
                    'model_id'   => (int)$entry['model_id'],
                    'model_name' => (string)($entry['model_name'] ?? ''),
                    'years'      => array_values(array_map('intval', (array)($entry['years'] ?? []))),
                ];
                continue;
            }
            /* Grouped row → expand */
            $makeId   = (int)($entry['make_id'] ?? 0);
            $makeName = (string)($entry['make_name'] ?? '');
            foreach ((array)($entry['models'] ?? []) as $m) {
                if (!is_array($m)) continue;
                $flat[] = [
                    'make_id'    => $makeId,
                    'make_name'  => $makeName,
                    'model_id'   => (int)($m['model_id'] ?? 0),
                    'model_name' => (string)($m['model_name'] ?? ''),
                    'years'      => array_values(array_map('intval', (array)($m['years'] ?? []))),
                ];
            }
        }
        return $flat;
    }

    public function modifyMeta(array $meta): array
    {
        $meta = array_replace_recursive($meta, [
            self::GROUP_NAME => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label'         => __('Vehicle Compatibility'),
                            'componentType' => Fieldset::NAME,
                            'collapsible'   => true,
                            'opened'        => true,
                            'sortOrder'     => self::GROUP_SORT_ORDER,
                            'dataScope'     => 'data.product',
                        ],
                    ],
                ],
                'children' => [
                    'csv_import_toolbar' => $this->csvImportToolbarMeta(),
                    'parts_required'     => $this->partsRequiredMeta(),
                    self::FIELD_NAME     => $this->dynamicRowsMeta(),
                ],
            ],
        ]);
        return $meta;
    }

    private function csvImportToolbarMeta(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType'   => Container::NAME,
                        'component'       => 'ETechFlow_ProductFitmentFinder/js/form/csv-import',
                        'template'        => 'ETechFlow_ProductFitmentFinder/csv-import',
                        'importUrl'       => $this->urlBuilder->getUrl('etechflow_vehicle/vehicle/importCsv'),
                        'dynamicRowsName' => 'product_form.product_form.' . self::GROUP_NAME . '.' . self::FIELD_NAME,
                        'sortOrder'       => 5,
                    ],
                ],
            ],
        ];
    }

    private function partsRequiredMeta(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => \Magento\Ui\Component\Form\Field::NAME,
                        'formElement'   => 'multiselect',
                        'component'     => 'Magento_Ui/js/form/element/multiselect',
                        'elementTmpl'   => 'ui/form/element/multiselect',
                        'dataType'      => 'multiselect',
                        'dataScope'     => 'parts_required',
                        'label'         => __('Parts Required'),
                        'notice'        => __('Pick every part type this product is. Drives the Find-Your-Parts cascading filter on the storefront.'),
                        'options'       => $this->getPartsRequiredOptions(),
                        'sortOrder'     => 7,
                        'size'          => 8,
                    ],
                ],
            ],
        ];
    }

    /**
     * Load options from the parts_required EAV attribute on demand.
     * Cached per request.
     */
    private ?array $partsRequiredOptions = null;
    private function getPartsRequiredOptions(): array
    {
        if ($this->partsRequiredOptions !== null) {
            return $this->partsRequiredOptions;
        }
        try {
            $attribute = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Eav\Model\Config::class)
                ->getAttribute(\Magento\Catalog\Model\Product::ENTITY, 'parts_required');
            $rawOptions = $attribute->getSource()->getAllOptions(false);
            $opts = [];
            foreach ($rawOptions as $o) {
                $opts[] = [
                    'value' => (string)($o['value'] ?? ''),
                    'label' => (string)($o['label'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $opts = [];
        }
        return $this->partsRequiredOptions = $opts;
    }

    private function dynamicRowsMeta(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType'       => DynamicRows::NAME,
                        'component'           => 'Magento_Ui/js/dynamic-rows/dynamic-rows',
                        'addButtonLabel'      => __('Add Compatible Vehicle'),
                        'label'               => __('Compatible Vehicles'),
                        'renderDefaultRecord' => false,
                        'columnsHeader'       => true,
                        'columnsHeaderAfterRender' => true,
                        'dataScope'           => self::FIELD_NAME,
                        'deleteProperty'      => false,
                        'isTemplate'          => false,
                        'additionalClasses'   => 'admin__field-wide ks-vc',
                    ],
                ],
            ],
            'children' => [
                'record' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => Container::NAME,
                                'component'     => 'Magento_Ui/js/dynamic-rows/record',
                                'isTemplate'    => true,
                                'is_collection' => true,
                            ],
                        ],
                    ],
                    'children' => [
                        'selected'   => $this->selectedField(),
                        'make_id'    => $this->makeField(),
                        'make_name'  => $this->hiddenField('make_name'),
                        'model_id'   => $this->modelField(),
                        'model_name' => $this->hiddenField('model_name'),
                        'years'      => $this->yearsField(),
                    ],
                ],
            ],
        ];
    }

    private function makeField(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Field::NAME,
                        'formElement'   => Select::NAME,
                        'dataType'      => Number::NAME,
                        'label'         => __('Make'),
                        'dataScope'     => 'make_id',
                        'options'       => $this->makeSource->toOptionArray(),
                        'validation'    => ['required-entry' => true],
                        'sortOrder'     => 10,
                    ],
                ],
            ],
        ];
    }

    private function modelField(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Field::NAME,
                        'formElement'   => Select::NAME,
                        'dataType'      => Number::NAME,
                        'label'         => __('Model'),
                        'dataScope'     => 'model_id',
                        // Native Magento select, fully populated with composite
                        // "Make – Model" labels. There is deliberately NO custom
                        // cascade component: a custom component that wipes its own
                        // options and rebuilds them per-row from the UI registry
                        // mis-times inside dynamicRows record clones, leaving the
                        // dropdown empty (won't open) AND a saved model_id with no
                        // option to display (saved fitment row renders blank).
                        // A plain, always-populated native select can't fail that
                        // way: it always opens, and any saved model_id resolves to
                        // its option so the saved Make/Model/Year shows on edit.
                        // The make is in the label, so the Make column stays clear.
                        'options'       => $this->buildModelOptions(),
                        // Model is optional: a Make-only fitment is valid and
                        // persists (see JsonBackend). Requiring it here blocked
                        // the whole product save whenever the Model wasn't set.
                        'sortOrder'     => 20,
                    ],
                ],
            ],
        ];
    }

    /**
     * Model options for the admin form, with composite "Make – Model" labels so
     * the unfiltered list stays unambiguous. Keeps make_id/make_name on each
     * option for any downstream use.
     *
     * @return array<int,array<string,mixed>>
     */
    private ?array $modelOptions = null;
    private function buildModelOptions(): array
    {
        if ($this->modelOptions !== null) {
            return $this->modelOptions;
        }
        $options = [];
        foreach ($this->modelSource->toOptionArray() as $opt) {
            $makeName  = (string)($opt['make_name'] ?? '');
            $modelName = (string)($opt['label'] ?? '');
            $options[] = [
                'value'     => $opt['value'] ?? '',
                'label'     => $makeName !== '' ? $makeName . ' – ' . $modelName : $modelName,
                'make_id'   => (int)($opt['make_id'] ?? 0),
                'make_name' => $makeName,
            ];
        }
        return $this->modelOptions = $options;
    }

    private function yearsField(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Field::NAME,
                        'formElement'   => 'multiselect',
                        'component'     => 'Magento_Ui/js/form/element/multiselect',
                        'elementTmpl'   => 'ui/form/element/multiselect',
                        'dataType'      => 'multiselect',
                        'label'         => __('Years'),
                        'dataScope'     => 'years',
                        'options'       => $this->yearSource->toOptionArray(),
                        'sortOrder'     => 30,
                        'size'          => 6,
                    ],
                ],
            ],
        ];
    }

    private function selectedField(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Field::NAME,
                        'formElement'   => Checkbox::NAME,
                        'dataType'      => 'number',
                        'label'         => __(' '),
                        'description'   => __('Select'),
                        'dataScope'     => 'selected',
                        'prefer'        => 'checkbox',
                        'valueMap'      => [
                            'false' => 0,
                            'true'  => 1,
                        ],
                        'default'       => 0,
                        'sortOrder'     => 1,
                        'additionalClasses' => 'ks-vc-select-col',
                    ],
                ],
            ],
        ];
    }

    private function hiddenField(string $name): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Field::NAME,
                        'formElement'   => 'input',
                        'dataType'      => 'text',
                        'dataScope'     => $name,
                        'visible'       => false,
                    ],
                ],
            ],
        ];
    }
}
