<?php

use App\Models\NetworkPop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $existingPops = NetworkPop::query()->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'region' => $p->region,
            'cluster_code' => $p->cluster_code ?? 'SDA',
            'address' => $p->address,
            'pic_name' => $p->pic_name ?? 'NOC Team',
            'pic_phone' => $p->pic_phone ?? '08123456789',
            'power_backup_info' => $p->power_backup_info ?? 'Rectifier 48V + Baterai',
            'rack_capacity' => $p->rack_capacity ?? '42U',
            'status' => $p->status ?? 'active',
        ])->toArray();

        DB::table('admin_master_data_groups')->updateOrInsert(
            ['key' => 'pops'],
            [
                'label' => 'Server Cabang (POP)',
                'items' => json_encode($existingPops),
                'editable_fields' => json_encode(['code', 'name', 'region', 'cluster_code', 'address', 'pic_name', 'pic_phone', 'rack_capacity', 'power_backup_info', 'status']),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('admin_master_data_groups')
            ->where('key', 'pops')
            ->delete();
    }
};
