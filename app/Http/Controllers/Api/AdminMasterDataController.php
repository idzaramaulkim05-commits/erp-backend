<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUpdateMasterDataGroupRequest;
use App\Http\Resources\AdminMasterDataGroupResource;
use App\Models\AdminMasterDataGroup;
use App\Models\AuditLog;
use App\Models\NetworkPop;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminMasterDataController extends Controller
{
    public function index()
    {
        // Ensure 'pops' master data group reflects current network_pops
        $pops = NetworkPop::query()->orderBy('id')->get();
        if ($pops->isNotEmpty()) {
            $popItems = $pops->map(fn ($p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'region' => $p->region,
                'cluster_code' => $p->cluster_code ?? 'SDA',
                'address' => $p->address,
                'pic_name' => $p->pic_name ?? 'NOC Team',
                'pic_phone' => $p->pic_phone ?? '08123456789',
                'power_backup_info' => $p->power_backup_info ?? 'Rectifier 48V + Baterai',
                'rack_capacity' => $p->rack_capacity ?? '42U',
                'status' => $p->status ?? 'active',
            ])->toArray();

            AdminMasterDataGroup::query()->updateOrCreate(
                ['key' => 'pops'],
                [
                    'label' => 'Server Cabang (POP)',
                    'items' => $popItems,
                    'editable_fields' => ['code', 'name', 'region', 'cluster_code', 'address', 'pic_name', 'pic_phone', 'rack_capacity', 'power_backup_info', 'status'],
                ]
            );
        }

        return AdminMasterDataGroupResource::collection(AdminMasterDataGroup::query()->orderBy('label')->get());
    }

    public function update(AdminUpdateMasterDataGroupRequest $request, string $group)
    {
        $masterGroup = AdminMasterDataGroup::query()->findOrFail($group);
        $items = $request->input('items', []);

        $masterGroup->update([
            'label' => $request->input('label', $masterGroup->label),
            'items' => $items,
            'editable_fields' => $request->input('editable_fields', $masterGroup->editable_fields),
        ]);

        // When pops group is updated, synchronize network_pops table!
        if ($group === 'pops') {
            $activeIds = [];
            foreach ($items as $item) {
                if (empty($item['name']) || empty($item['code'])) {
                    continue;
                }

                $code = strtoupper(trim($item['code']));
                $id = !empty($item['id']) ? $item['id'] : 'POP-' . strtoupper(Str::slug($item['region'] ?? 'HUB', '')) . '-' . sprintf('%02d', random_int(10, 99));
                $activeIds[] = $id;

                NetworkPop::query()->updateOrCreate(
                    ['id' => $id],
                    [
                        'code' => $code,
                        'name' => trim($item['name']),
                        'region' => $item['region'] ?? 'Sidoarjo Kota',
                        'cluster_code' => $item['cluster_code'] ?? 'SDA',
                        'address' => $item['address'] ?? '-',
                        'pic_name' => $item['pic_name'] ?? 'NOC Team',
                        'pic_phone' => $item['pic_phone'] ?? null,
                        'rack_capacity' => $item['rack_capacity'] ?? '42U',
                        'power_backup_info' => $item['power_backup_info'] ?? null,
                        'status' => $item['status'] ?? 'active',
                    ]
                );
            }

            // Remove pops that were deleted by superadmin in master data
            if (!empty($activeIds)) {
                NetworkPop::query()->whereNotIn('id', $activeIds)->delete();
            }
        }

        $actor = $request->user();
        AuditLog::query()->create([
            'id' => 'LOG-' . Str::upper(Str::random(8)),
            'timestamp' => now(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => 'Update Master Data',
            'target' => $masterGroup->key,
            'details' => sprintf('Kelompok master data %s diperbarui oleh superadmin.', $masterGroup->label),
            'type' => 'info',
        ]);

        return AdminMasterDataGroupResource::make($masterGroup->fresh());
    }
}
