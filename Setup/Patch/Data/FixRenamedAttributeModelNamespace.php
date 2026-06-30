<?php
declare(strict_types=1);

namespace ETechFlow\ProductFitmentFinder\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Repairs EAV attributes whose backend/source/frontend model class still
 * references the pre-rename namespace `ETechFlow\VehicleCompat`.
 *
 * The module was renamed `module-vehicle-compat` → `module-product-fitment-finder`
 * (`ETechFlow\VehicleCompat` → `ETechFlow\ProductFitmentFinder`) in v2.0.0. On any
 * store UPGRADED from the old package, the `vehicle_compat_data` attribute row was
 * created by the old install with `backend_model =
 * ETechFlow\VehicleCompat\Model\Attribute\Backend\JsonBackend` — a class that no
 * longer exists after the rename. Magento then fails to load the JSON backend on
 * save and silently drops the value, so fitment data never persists and the PDP
 * badge stays blank.
 *
 * This patch rewrites the stale namespace on every affected attribute so the live
 * class is used again. Idempotent — a no-op on fresh installs (which already have
 * the correct namespace) and on stores already repaired.
 */
class FixRenamedAttributeModelNamespace implements DataPatchInterface
{
    private const OLD_NS = 'ETechFlow\\VehicleCompat';
    private const NEW_NS = 'ETechFlow\\ProductFitmentFinder';
    private const MODEL_COLUMNS = ['backend_model', 'source_model', 'frontend_model'];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $conn  = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('eav_attribute');

        $select = $conn->select()
            ->from($table, array_merge(['attribute_id'], self::MODEL_COLUMNS))
            ->where('backend_model LIKE ?', '%VehicleCompat%')
            ->orWhere('source_model LIKE ?', '%VehicleCompat%')
            ->orWhere('frontend_model LIKE ?', '%VehicleCompat%');

        foreach ($conn->fetchAll($select) as $row) {
            $bind = [];
            foreach (self::MODEL_COLUMNS as $col) {
                if ($row[$col] !== null && str_contains((string) $row[$col], self::OLD_NS)) {
                    $bind[$col] = str_replace(self::OLD_NS, self::NEW_NS, (string) $row[$col]);
                }
            }
            if ($bind) {
                $conn->update($table, $bind, ['attribute_id = ?' => (int) $row['attribute_id']]);
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
