<?php

use App\Models\InventoryItem;
use App\Models\ProcurementRequest;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up InventoryItem names
        $items = InventoryItem::query()->get();
        foreach ($items as $item) {
            $rawName = trim((string) $item->name);
            $cleanName = preg_replace('/^(Material\s+Lainnya\s*(\/\s*Khusus)?\s*[-–—:\/]?\s*)/i', '', $rawName);
            $cleanName = trim((string) $cleanName);

            if (empty($cleanName) || strtolower($rawName) === 'material lainnya / khusus' || strtolower($rawName) === 'material lainnya') {
                $cleanName = trim((string) $item->code);
            }

            if ($cleanName !== $rawName && !empty($cleanName)) {
                $item->update([
                    'name' => $cleanName,
                    'model' => !empty($item->model) && $item->model !== $rawName ? $item->model : $cleanName,
                ]);
            }
        }

        // Clean up ProcurementRequest item_names
        $procurements = ProcurementRequest::query()->get();
        foreach ($procurements as $proc) {
            $rawName = trim((string) $proc->item_name);
            $cleanName = preg_replace('/^(Material\s+Lainnya\s*(\/\s*Khusus)?\s*[-–—:\/]?\s*)/i', '', $rawName);
            $cleanName = trim((string) $cleanName);

            if (empty($cleanName) || strtolower($rawName) === 'material lainnya / khusus' || strtolower($rawName) === 'material lainnya') {
                $cleanName = trim((string) $proc->item_code);
            }

            if ($cleanName !== $rawName && !empty($cleanName)) {
                $proc->update([
                    'item_name' => $cleanName,
                ]);
            }
        }
    }

    public function down(): void
    {
        // No reversal needed for data normalization
    }
};
