<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Console\Command;

use Magento\Catalog\Api\CategoryLinkRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\OptionLabel;
use Magento\Eav\Model\Entity\Attribute\OptionLabelFactory;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Bulk import vehicle compatibility + parts-required data from CSV.
 *
 * CSV header: Make,Model,Year,"Parts Required",SKU
 * Row shape : ONE (Make+Model+Year+Part) tuple with multiple pipe-separated SKUs.
 * Year      : 4-digit single year OR 8-digit concatenated range (e.g. "19801982").
 * SKU column: pipe-separated list of product SKUs.
 *
 * Internally pivots to per-SKU: builds an in-memory map sku => {compat[], parts{}}
 * from the WHOLE CSV first, then saves each product ONCE with the merged data.
 */
class ImportPartsCsv extends Command
{
    private const NAME = 'etechflow:vehiclecompat:import-parts';
    private const CAR_KEYS_PARTS_ROOT_CATEGORY_ID = 3;

    private AppState $appState;
    private ResourceConnection $resource;
    private ProductRepositoryInterface $productRepository;
    private AttributeRepositoryInterface $attributeRepository;
    private CategoryLinkRepositoryInterface $categoryLinkRepository;
    private CategoryProductLinkInterfaceFactory $categoryProductLinkFactory;
    private EavSetupFactory $eavSetupFactory;
    private OptionLabelFactory $optionLabelFactory;

    /** @var array<string,int> */
    private array $makeCache = [];
    /** @var array<string,int>  key="<makeId>|<lc_model>" */
    private array $modelCache = [];
    /** @var array<string,string> make_id => make_name */
    private array $makeNameCache = [];
    /** @var array<string,string> model_id => model_name */
    private array $modelNameCache = [];
    /** @var array<string,int>  lowercased part name => option_id */
    private array $partOptionCache = [];
    /** @var array<string,int>  lowercased category name => entity_id */
    private array $partCategoryCache = [];

    public function __construct(
        AppState $appState,
        ResourceConnection $resource,
        ProductRepositoryInterface $productRepository,
        AttributeRepositoryInterface $attributeRepository,
        CategoryLinkRepositoryInterface $categoryLinkRepository,
        CategoryProductLinkInterfaceFactory $categoryProductLinkFactory,
        EavSetupFactory $eavSetupFactory,
        OptionLabelFactory $optionLabelFactory
    ) {
        parent::__construct();
        $this->appState                    = $appState;
        $this->resource                    = $resource;
        $this->productRepository           = $productRepository;
        $this->attributeRepository         = $attributeRepository;
        $this->categoryLinkRepository      = $categoryLinkRepository;
        $this->categoryProductLinkFactory  = $categoryProductLinkFactory;
        $this->eavSetupFactory             = $eavSetupFactory;
        $this->optionLabelFactory          = $optionLabelFactory;
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Import vehicle compat + parts_required data from CSV (per-SKU)')
            ->addArgument('csv', InputArgument::REQUIRED, 'Path to CSV file (server-side path)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse + report only, no DB writes')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Replace existing vehicle_compat_data (default: merge)')
            ->addOption('reports-dir', null, InputOption::VALUE_REQUIRED, 'Where to write report CSVs', '')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Save flush interval', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { $this->appState->setAreaCode(Area::AREA_ADMINHTML); } catch (\Throwable $e) {}

        $csvPath = $input->getArgument('csv');
        if (!is_file($csvPath)) {
            $output->writeln("<error>CSV file not found: $csvPath</error>");
            return self::FAILURE;
        }

        $dryRun    = (bool)$input->getOption('dry-run');
        $overwrite = (bool)$input->getOption('overwrite');
        $batchSize = max(1, (int)$input->getOption('batch-size'));
        $reportsDir = $input->getOption('reports-dir') ?: ('/var/www/html/var/import-reports/' . date('Y-m-d_His'));
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0775, true);
        }

        // ============================================================
        // PHASE 1: Parse CSV and pivot to per-SKU map
        // ============================================================
        $output->writeln("<info>Phase 1: Parsing $csvPath …</info>");
        $perSku = [];          // sku => ['compat' => [{make,model,year}…], 'parts' => set]
        $sourceRows = [];      // sku => [row_numbers…]
        $emptyRows = [];
        $badYearRows = [];
        $partCounts = [];      // part name => count
        $makeNames = [];       // distinct make names from CSV
        $totalRows = 0;

        $fh = fopen($csvPath, 'rb');
        if (!$fh) {
            $output->writeln("<error>Cannot open CSV</error>");
            return self::FAILURE;
        }

        // Read header (PHP 8.4+: pass $escape explicitly to avoid E_DEPRECATED)
        $header = fgetcsv($fh, null, ',', '"', '');
        if (!$header) {
            $output->writeln("<error>Empty CSV</error>");
            fclose($fh);
            return self::FAILURE;
        }
        $header = array_map(fn($h) => strtolower(trim((string)$h, "\xEF\xBB\xBF \t\n\r\0\x0B\"")), $header);
        $colIdx = [];
        foreach (['make','model','year','parts required','sku'] as $needed) {
            $idx = array_search($needed, $header, true);
            if ($idx === false) {
                $output->writeln("<error>Required column missing: $needed</error>");
                fclose($fh);
                return self::FAILURE;
            }
            $colIdx[$needed] = $idx;
        }

        $rowNum = 1;
        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $rowNum++;
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
            $totalRows++;
            $make  = trim((string)($row[$colIdx['make']] ?? ''));
            $model = trim((string)($row[$colIdx['model']] ?? ''));
            $year  = trim((string)($row[$colIdx['year']] ?? ''));
            $part  = trim((string)($row[$colIdx['parts required']] ?? ''));
            $sku   = trim((string)($row[$colIdx['sku']] ?? ''));

            if ($make === '' || $model === '' || $year === '' || $part === '' || $sku === '') {
                $emptyRows[] = [$rowNum, $make, $model, $year, $part, $sku];
                continue;
            }

            $years = $this->expandYear($year);
            if (empty($years)) {
                $badYearRows[] = [$rowNum, $year];
                continue;
            }

            $partCounts[$part] = ($partCounts[$part] ?? 0) + 1;
            $makeNames[$make] = true;

            foreach (explode('|', $sku) as $oneSku) {
                $oneSku = trim($oneSku);
                if ($oneSku === '') continue;
                if (!isset($perSku[$oneSku])) {
                    $perSku[$oneSku]   = ['compat' => [], 'parts' => []];
                    $sourceRows[$oneSku] = [];
                }
                foreach ($years as $y) {
                    $perSku[$oneSku]['compat'][] = [
                        'make' => $make, 'model' => $model, 'year' => $y,
                    ];
                }
                $perSku[$oneSku]['parts'][$part] = true;
                $sourceRows[$oneSku][] = $rowNum;
            }
        }
        fclose($fh);

        $distinctSkus = count($perSku);
        $output->writeln("  total rows parsed: $totalRows");
        $output->writeln("  distinct SKUs: $distinctSkus");
        $output->writeln("  distinct makes in file: " . count($makeNames));
        $output->writeln("  distinct part types: " . count($partCounts));
        $output->writeln("  empty-field rows: " . count($emptyRows));
        $output->writeln("  bad-year rows: " . count($badYearRows));

        // ============================================================
        // PHASE 2: Pre-flight DB validation (catalogue lookup)
        // ============================================================
        $output->writeln("<info>Phase 2: Validating SKUs against catalogue …</info>");
        $conn = $this->resource->getConnection();
        $allCsvSkus = array_keys($perSku);
        $existingSkus = [];
        foreach (array_chunk($allCsvSkus, 1000) as $chunk) {
            $rows = $conn->fetchCol(
                $conn->select()->from($this->resource->getTableName('catalog_product_entity'), 'sku')
                    ->where('sku IN (?)', $chunk)
            );
            foreach ($rows as $s) $existingSkus[$s] = true;
        }
        $missingSkus = [];
        foreach ($perSku as $sku => $_) {
            if (!isset($existingSkus[$sku])) {
                $missingSkus[$sku] = $sourceRows[$sku] ?? [];
            }
        }
        $foundSkus = $distinctSkus - count($missingSkus);
        $output->writeln("  SKUs found in catalogue: $foundSkus");
        $output->writeln("  SKUs missing: " . count($missingSkus));

        // Build category map under root #3
        $output->writeln("<info>Phase 3: Building part→category map …</info>");
        $this->loadPartCategories($conn);
        $unmatchedParts = [];
        foreach ($partCounts as $partName => $_) {
            if (!isset($this->partCategoryCache[$this->norm($partName)])) {
                $unmatchedParts[$partName] = $_;
            }
        }
        $output->writeln("  parts with matching category: " . (count($partCounts) - count($unmatchedParts)));
        $output->writeln("  parts without category: " . count($unmatchedParts));

        // ============================================================
        // PHASE 4: Write reports
        // ============================================================
        $output->writeln("<info>Phase 4: Writing reports to $reportsDir …</info>");
        $this->writeCsv("$reportsDir/missing-skus.csv", ['sku','source_row_numbers'],
            array_map(fn($s, $rows) => [$s, implode('|', $rows)], array_keys($missingSkus), $missingSkus));
        $this->writeCsv("$reportsDir/empty-rows.csv", ['row','make','model','year','parts','sku'], $emptyRows);
        $this->writeCsv("$reportsDir/bad-year-rows.csv", ['row','year_value'], $badYearRows);
        $this->writeCsv("$reportsDir/unmatched-parts.csv", ['part_name','row_count'],
            array_map(fn($p, $c) => [$p, $c], array_keys($unmatchedParts), $unmatchedParts));

        // Per-sku preview — only for SKUs found in catalogue
        $previewRows = [];
        foreach ($perSku as $sku => $data) {
            if (!isset($existingSkus[$sku])) continue;
            $combos = count($data['compat']);
            $parts = implode('|', array_keys($data['parts']));
            $cats = [];
            foreach (array_keys($data['parts']) as $pn) {
                if (isset($this->partCategoryCache[$this->norm($pn)])) {
                    $cats[] = $this->partCategoryCache[$this->norm($pn)];
                }
            }
            $previewRows[] = [$sku, $combos, $parts, implode('|', array_unique($cats))];
        }
        $this->writeCsv("$reportsDir/per-sku-preview.csv",
            ['sku','combos_to_apply','parts_to_add','categories_to_link'], $previewRows);

        // Parts breakdown
        arsort($partCounts);
        $partBreakdownRows = array_map(fn($p, $c) => [$p, $c], array_keys($partCounts), $partCounts);
        $this->writeCsv("$reportsDir/parts-breakdown.csv", ['part_name','row_count'], $partBreakdownRows);

        // Summary
        $summary = "Import summary\n"
                 . "==============\n"
                 . "CSV: $csvPath\n"
                 . "Mode: " . ($dryRun ? "DRY-RUN" : "LIVE") . "\n"
                 . "Overwrite: " . ($overwrite ? "yes" : "no (merge)") . "\n"
                 . "Total rows: $totalRows\n"
                 . "Distinct SKUs: $distinctSkus\n"
                 . "SKUs to update: $foundSkus\n"
                 . "SKUs missing in catalogue: " . count($missingSkus) . "\n"
                 . "Empty-field rows: " . count($emptyRows) . "\n"
                 . "Bad-year rows: " . count($badYearRows) . "\n"
                 . "Distinct part types: " . count($partCounts) . "\n"
                 . "Parts without category: " . count($unmatchedParts) . "\n"
                 . "Distinct makes in file: " . count($makeNames) . "\n"
                 . "Reports dir: $reportsDir\n"
                 . "Generated: " . date('c') . "\n";
        file_put_contents("$reportsDir/import-summary.txt", $summary);
        $output->writeln($summary);

        if ($dryRun) {
            $output->writeln("<comment>--dry-run: no DB writes. Inspect reports above.</comment>");
            return self::SUCCESS;
        }

        if (!$input->getOption('no-interaction')) {
            $helper = $this->getHelper('question');
            $q = new ConfirmationQuestion("Proceed with updating $foundSkus products? [y/N] ", false);
            if (!$helper->ask($input, $output, $q)) {
                $output->writeln("<comment>Aborted by user.</comment>");
                return self::SUCCESS;
            }
        }

        // ============================================================
        // PHASE 5: Apply changes (live)
        // ============================================================
        $output->writeln("<info>Phase 5: Applying changes …</info>");

        // Seed the parts_required attribute options with every part name
        // observed in the CSV. The Eav setup auto-skips duplicates.
        $this->seedPartOptions(array_keys($partCounts), $output);

        // Pre-cache makes/models for fast lookup
        $this->primeMakeModelCache($conn);

        $failedSkus = [];
        $newMakes = [];
        $newModels = [];
        $applied = 0;
        $progress = new ProgressBar($output, $foundSkus);
        $progress->start();

        foreach ($perSku as $sku => $data) {
            if (!isset($existingSkus[$sku])) continue;
            try {
                $this->applyToSku($conn, $sku, $data, $overwrite, $newMakes, $newModels);
                $applied++;
            } catch (\Throwable $e) {
                $failedSkus[] = [$sku, $e->getMessage()];
            }
            $progress->advance();
            if ($applied % $batchSize === 0) {
                // batch hook (no-op for now)
            }
        }
        $progress->finish();
        $output->writeln("");

        $this->writeCsv("$reportsDir/failed-skus.csv", ['sku','error'], $failedSkus);
        $this->writeCsv("$reportsDir/new-makes.csv", ['make_id','name'],
            array_map(fn($id, $n) => [$id, $n], array_keys($newMakes), $newMakes));
        $this->writeCsv("$reportsDir/new-models.csv", ['model_id','make_id','name'], $newModels);

        $output->writeln("");
        $output->writeln("<info>DONE. Applied: $applied, Failed: " . count($failedSkus) . "</info>");
        $output->writeln("<info>New makes auto-created: " . count($newMakes) . "</info>");
        $output->writeln("<info>New models auto-created: " . count($newModels) . "</info>");
        $output->writeln("<info>Reports: $reportsDir</info>");

        return count($failedSkus) === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /** Expand a year cell to an inclusive list of years. */
    private function expandYear(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}$/', $raw)) {
            return [(int)$raw];
        }
        if (preg_match('/^(\d{4})(\d{4})$/', $raw, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2];
            if ($a > $b) [$a, $b] = [$b, $a];
            if ($b - $a > 80) return []; // sanity
            return range($a, $b);
        }
        // Tolerate "1980-1982", "1980 1982", "1980,1982"
        if (preg_match('/^(\d{4})[\s,\-]+(\d{4})$/', $raw, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2];
            if ($a > $b) [$a, $b] = [$b, $a];
            if ($b - $a > 80) return [];
            return range($a, $b);
        }
        return [];
    }

    private function loadPartCategories(\Magento\Framework\DB\Adapter\AdapterInterface $conn): void
    {
        $rows = $conn->fetchAll(
            "SELECT v.entity_id, v.value
               FROM " . $this->resource->getTableName('catalog_category_entity_varchar') . " v
               JOIN " . $this->resource->getTableName('eav_attribute') . " a ON a.attribute_id = v.attribute_id
               JOIN " . $this->resource->getTableName('catalog_category_entity') . " e ON e.entity_id = v.entity_id
              WHERE a.attribute_code = 'name'
                AND v.store_id = 0
                AND e.path LIKE '1/2/" . self::CAR_KEYS_PARTS_ROOT_CATEGORY_ID . "/%'"
        );
        foreach ($rows as $r) {
            $this->partCategoryCache[$this->norm((string)$r['value'])] = (int)$r['entity_id'];
        }
    }

    private function seedPartOptions(array $partNames, OutputInterface $output): void
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->getEavModuleDataSetup()]);
        $existing = $this->loadPartOptionMap();
        $toAdd = [];
        foreach ($partNames as $name) {
            if (!isset($existing[$this->norm($name)])) {
                $toAdd[] = $name;
            }
        }
        if (!empty($toAdd)) {
            $values = [];
            foreach ($toAdd as $i => $n) $values['new_' . $i] = $n;
            $eavSetup->addAttributeOption([
                'attribute_id' => $this->getPartsRequiredAttributeId(),
                'values'       => $values,
            ]);
            $output->writeln("  Seeded " . count($toAdd) . " new parts_required options");
        }
        $this->partOptionCache = $this->loadPartOptionMap();
    }

    private function getEavModuleDataSetup()
    {
        // Lazy resolve via ObjectManager — module data setup isn't a DI-constructor arg
        return \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\Setup\ModuleDataSetupInterface::class);
    }

    private function getPartsRequiredAttributeId(): int
    {
        return (int)$this->resource->getConnection()->fetchOne(
            "SELECT attribute_id FROM " . $this->resource->getTableName('eav_attribute')
            . " WHERE entity_type_id = 4 AND attribute_code = 'parts_required'"
        );
    }

    private function loadPartOptionMap(): array
    {
        $aid = $this->getPartsRequiredAttributeId();
        $rows = $this->resource->getConnection()->fetchAll(
            "SELECT o.option_id, v.value
               FROM " . $this->resource->getTableName('eav_attribute_option') . " o
               JOIN " . $this->resource->getTableName('eav_attribute_option_value') . " v ON v.option_id = o.option_id
              WHERE o.attribute_id = ? AND v.store_id = 0",
            [$aid]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$this->norm((string)$r['value'])] = (int)$r['option_id'];
        }
        return $map;
    }

    private function primeMakeModelCache(\Magento\Framework\DB\Adapter\AdapterInterface $conn): void
    {
        foreach ($conn->fetchAll("SELECT make_id, name FROM " . $this->resource->getTableName('etechflow_vehicle_make')) as $r) {
            $this->makeCache[$this->norm((string)$r['name'])] = (int)$r['make_id'];
            $this->makeNameCache[(int)$r['make_id']] = (string)$r['name'];
        }
        foreach ($conn->fetchAll("SELECT model_id, make_id, name FROM " . $this->resource->getTableName('etechflow_vehicle_model')) as $r) {
            $this->modelCache[((int)$r['make_id']) . '|' . $this->norm((string)$r['name'])] = (int)$r['model_id'];
            $this->modelNameCache[(int)$r['model_id']] = (string)$r['name'];
        }
    }

    private function applyToSku(
        \Magento\Framework\DB\Adapter\AdapterInterface $conn,
        string $sku,
        array $data,
        bool $overwrite,
        array &$newMakes,
        array &$newModels
    ): void {
        $product = $this->productRepository->get($sku, true);

        // Build [make_id][model_id] => set<year>
        $tree = [];
        foreach ($data['compat'] as $combo) {
            $makeName = $combo['make'];
            $modelName = $combo['model'];
            $year = (int)$combo['year'];

            $makeId = $this->ensureMake($conn, $makeName, $newMakes);
            $modelId = $this->ensureModel($conn, $makeId, $modelName, $newModels);

            $key = $makeId . '|' . $modelId;
            if (!isset($tree[$key])) {
                $tree[$key] = ['make_id' => $makeId, 'model_id' => $modelId, 'years' => []];
            }
            $tree[$key]['years'][$year] = true;
        }

        // Merge with existing unless overwrite
        if (!$overwrite) {
            $existingRaw = (string)$product->getData('vehicle_compat_data');
            if ($existingRaw !== '') {
                $existing = json_decode($existingRaw, true);
                if (is_array($existing)) {
                    foreach ($existing as $e) {
                        if (!is_array($e)) continue;
                        $mid = (int)($e['make_id'] ?? 0);
                        $mdid = (int)($e['model_id'] ?? 0);
                        if ($mid <= 0 || $mdid <= 0) continue;
                        $key = $mid . '|' . $mdid;
                        if (!isset($tree[$key])) {
                            $tree[$key] = ['make_id' => $mid, 'model_id' => $mdid, 'years' => []];
                        }
                        foreach ((array)($e['years'] ?? []) as $y) {
                            $tree[$key]['years'][(int)$y] = true;
                        }
                    }
                }
            }
        }

        // Serialise
        $compatJson = [];
        foreach ($tree as $entry) {
            $years = array_keys($entry['years']);
            sort($years);
            $compatJson[] = [
                'make_id'    => $entry['make_id'],
                'make_name'  => $this->makeNameCache[$entry['make_id']] ?? '',
                'model_id'   => $entry['model_id'],
                'model_name' => $this->modelNameCache[$entry['model_id']] ?? '',
                'years'      => $years,
            ];
        }
        $product->setData('vehicle_compat_data', json_encode($compatJson));

        // Parts: union with existing
        $existingPartIds = [];
        $cur = $product->getData('parts_required');
        if (is_string($cur) && $cur !== '') $existingPartIds = array_map('intval', explode(',', $cur));
        if (is_array($cur)) $existingPartIds = array_map('intval', $cur);
        $newPartIds = $existingPartIds;
        $partCategories = [];
        foreach (array_keys($data['parts']) as $partName) {
            $oid = $this->partOptionCache[$this->norm($partName)] ?? null;
            if ($oid !== null && !in_array($oid, $newPartIds, true)) {
                $newPartIds[] = $oid;
            }
            $catId = $this->partCategoryCache[$this->norm($partName)] ?? null;
            if ($catId !== null) {
                $partCategories[$catId] = true;
            }
        }
        sort($newPartIds);
        $product->setData('parts_required', implode(',', array_unique($newPartIds)));

        // Save product (triggers indexers + observers)
        $this->productRepository->save($product);

        // Assign to part categories (union, no duplicates)
        $existingCats = $product->getCategoryIds();
        foreach (array_keys($partCategories) as $catId) {
            if (!in_array((string)$catId, $existingCats, true) && !in_array($catId, $existingCats, true)) {
                $link = $this->categoryProductLinkFactory->create();
                $link->setSku($sku);
                $link->setCategoryId((int)$catId);
                $link->setPosition(0);
                try {
                    $this->categoryLinkRepository->save($link);
                } catch (\Throwable $e) { /* tolerate dup */ }
            }
        }
    }

    private function ensureMake(\Magento\Framework\DB\Adapter\AdapterInterface $conn, string $name, array &$newMakes): int
    {
        $key = $this->norm($name);
        if (isset($this->makeCache[$key])) return $this->makeCache[$key];
        $conn->insert($this->resource->getTableName('etechflow_vehicle_make'), [
            'name' => $name, 'sort_order' => 999,
        ]);
        $id = (int)$conn->lastInsertId($this->resource->getTableName('etechflow_vehicle_make'));
        $this->makeCache[$key] = $id;
        $this->makeNameCache[$id] = $name;
        $newMakes[$id] = $name;
        return $id;
    }

    private function ensureModel(\Magento\Framework\DB\Adapter\AdapterInterface $conn, int $makeId, string $name, array &$newModels): int
    {
        $key = $makeId . '|' . $this->norm($name);
        if (isset($this->modelCache[$key])) return $this->modelCache[$key];
        $conn->insert($this->resource->getTableName('etechflow_vehicle_model'), [
            'make_id' => $makeId, 'name' => $name, 'sort_order' => 999,
        ]);
        $id = (int)$conn->lastInsertId($this->resource->getTableName('etechflow_vehicle_model'));
        $this->modelCache[$key] = $id;
        $this->modelNameCache[$id] = $name;
        $newModels[] = [$id, $makeId, $name];
        return $id;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s ?? '';
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fp = fopen($path, 'wb');
        // PHP 8.4+: pass $escape explicitly to avoid the fputcsv() E_DEPRECATED.
        fputcsv($fp, $headers, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fp, (array)$r, ',', '"', '');
        }
        fclose($fp);
    }
}
