<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Controller\Adminhtml\Vehicle;

use ETechFlow\ProductFitmentFinder\Model\MakeFactory;
use ETechFlow\ProductFitmentFinder\Model\ModelFactory;
use ETechFlow\ProductFitmentFinder\Model\ResourceModel\Make\CollectionFactory as MakeCollectionFactory;
use ETechFlow\ProductFitmentFinder\Model\ResourceModel\Model\CollectionFactory as ModelCollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST etechflow_vehicle/vehicle/importCsv (multipart, file field name "csv")
 *
 * Expected CSV columns (header row required, case-insensitive):
 *   Make, Model, Year         — single year per row, aggregated across rows
 *   Make, Model, Year From, Year To  — year range per row
 *   Make, Model, Years        — flexible: "2010", "2010-2012", "2010,2012,2014"
 *
 * Behaviour:
 *   - Missing Makes / Models are auto-created in etechflow_vehicle_make /
 *     etechflow_vehicle_model so the dropdowns immediately have them.
 *   - Rows are grouped by (make, model) and years are aggregated + de-duplicated.
 *   - Returns JSON shape suitable for direct injection into the dynamicRows component.
 */
class ImportCsv extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    private JsonFactory $jsonFactory;
    private MakeFactory $makeFactory;
    private ModelFactory $modelFactory;
    private MakeCollectionFactory $makeCollFactory;
    private ModelCollectionFactory $modelCollFactory;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        MakeFactory $makeFactory,
        ModelFactory $modelFactory,
        MakeCollectionFactory $makeCollFactory,
        ModelCollectionFactory $modelCollFactory
    ) {
        parent::__construct($context);
        $this->jsonFactory      = $jsonFactory;
        $this->makeFactory      = $makeFactory;
        $this->modelFactory     = $modelFactory;
        $this->makeCollFactory  = $makeCollFactory;
        $this->modelCollFactory = $modelCollFactory;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $files = $_FILES['csv'] ?? null;
        if (!$files || empty($files['tmp_name']) || !is_uploaded_file($files['tmp_name'])) {
            return $result->setData(['error' => 'No CSV file uploaded.']);
        }

        $fh = fopen($files['tmp_name'], 'r');
        if (!$fh) return $result->setData(['error' => 'Could not read uploaded file.']);

        // PHP 8.4+: the $escape parameter must be passed explicitly or fgetcsv()
        // emits an E_DEPRECATED notice. In developer mode that notice is printed
        // into the response body BEFORE this JSON, making it unparseable on the
        // client ("Upload failed, status 200"). Pass '' (RFC-4180 no-escape).
        $headerRow = fgetcsv($fh, null, ',', '"', '');
        if (!$headerRow) { fclose($fh); return $result->setData(['error' => 'Empty file.']); }

        $headers = array_map(static fn($h) => strtolower(trim((string)$h)), $headerRow);
        $makeIdx  = array_search('make', $headers, true);
        $modelIdx = array_search('model', $headers, true);
        $yearIdx  = array_search('year', $headers, true);
        if ($yearIdx === false) $yearIdx = array_search('years', $headers, true);
        $fromIdx  = array_search('year from', $headers, true);
        $toIdx    = array_search('year to', $headers, true);

        if ($makeIdx === false || $modelIdx === false) {
            fclose($fh);
            return $result->setData([
                'error' => 'CSV must contain "Make" and "Model" columns (and either "Year" or "Year From"+"Year To").',
            ]);
        }

        /* Cache existing Makes / Models for fast lookup + creation */
        $makesByName = [];
        foreach ($this->makeCollFactory->create() as $m) {
            $makesByName[strtolower(trim((string)$m->getName()))] = $m;
        }
        $modelsByKey = [];
        foreach ($this->modelCollFactory->create() as $m) {
            $modelsByKey[(int)$m->getMakeId() . '|' . strtolower(trim((string)$m->getName()))] = $m;
        }

        $aggregate = [];   /* key = "makeId|modelId" => row */
        $createdMakes  = 0;
        $createdModels = 0;
        $rowsRead = 0;
        $rowsSkipped = 0;

        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $rowsRead++;
            $makeName  = trim((string)($row[$makeIdx] ?? ''));
            $modelName = trim((string)($row[$modelIdx] ?? ''));
            if ($makeName === '' || $modelName === '') { $rowsSkipped++; continue; }

            $years = [];
            if ($yearIdx !== false) {
                $years = $this->parseYearText((string)($row[$yearIdx] ?? ''));
            } elseif ($fromIdx !== false && $toIdx !== false) {
                $from = (int)($row[$fromIdx] ?? 0);
                $to   = (int)($row[$toIdx]   ?? 0);
                if ($from && $to) {
                    $lo = min($from, $to); $hi = max($from, $to);
                    for ($y = $lo; $y <= $hi; $y++) $years[] = $y;
                }
            }

            $makeKey = strtolower($makeName);
            $make = $makesByName[$makeKey] ?? null;
            if (!$make) {
                $make = $this->makeFactory->create();
                $make->setData('name', $makeName);
                $make->save();
                $makesByName[$makeKey] = $make;
                $createdMakes++;
            }

            $modelKey = (int)$make->getId() . '|' . strtolower($modelName);
            $model = $modelsByKey[$modelKey] ?? null;
            if (!$model) {
                $model = $this->modelFactory->create();
                $model->setData('make_id', (int)$make->getId());
                $model->setData('name', $modelName);
                $model->save();
                $modelsByKey[$modelKey] = $model;
                $createdModels++;
            }

            $rowKey = (int)$make->getId() . '|' . (int)$model->getId();
            if (!isset($aggregate[$rowKey])) {
                $aggregate[$rowKey] = [
                    'make_id'    => (int)$make->getId(),
                    'make_name'  => (string)$make->getName(),
                    'model_id'   => (int)$model->getId(),
                    'model_name' => (string)$model->getName(),
                    'years'      => [],
                    'selected'   => 0,
                ];
            }
            foreach ($years as $y) {
                if ($y >= 1900 && $y <= 2099 && !in_array($y, $aggregate[$rowKey]['years'], true)) {
                    $aggregate[$rowKey]['years'][] = $y;
                }
            }
        }
        fclose($fh);

        foreach ($aggregate as &$r) sort($r['years']);
        unset($r);

        return $result->setData([
            'rows'           => array_values($aggregate),
            'rowsRead'       => $rowsRead,
            'rowsSkipped'    => $rowsSkipped,
            'createdMakes'   => $createdMakes,
            'createdModels'  => $createdModels,
        ]);
    }

    /** "2010" | "2010-2012" | "2010,2012,2014" | "2010, 2012-2014" → year list */
    private function parseYearText(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];
        $out = [];
        foreach (preg_split('/\s*,\s*/', $text) as $token) {
            if ($token === '') continue;
            if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $token, $m)) {
                $lo = min((int)$m[1], (int)$m[2]); $hi = max((int)$m[1], (int)$m[2]);
                for ($y = $lo; $y <= $hi; $y++) $out[] = $y;
            } elseif (preg_match('/^\d{4}$/', $token)) {
                $out[] = (int)$token;
            }
        }
        return array_values(array_unique($out));
    }
}
