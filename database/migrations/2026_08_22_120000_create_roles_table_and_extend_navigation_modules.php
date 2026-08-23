<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->string('division');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('app_navigation_modules', function (Blueprint $table) {
            $table->boolean('show_in_navbar')->default(true)->after('is_active');
            $table->boolean('admin_only_dashboard')->default(false)->after('show_in_navbar');
        });

        $roleItems = [];

        if (Schema::hasTable('admin_master_data_groups')) {
            $group = DB::table('admin_master_data_groups')->where('key', 'role_division_map')->first();
            if ($group && isset($group->items)) {
                $decodedItems = json_decode($group->items, true);
                if (is_array($decodedItems)) {
                    $roleItems = $decodedItems;
                }
            }
        }

        if (empty($roleItems) && Schema::hasTable('users')) {
            $roleItems = DB::table('users')
                ->select('role', 'role_title', 'division')
                ->distinct()
                ->orderBy('role')
                ->get()
                ->map(fn (object $item) => [
                    'role' => $item->role,
                    'roleTitle' => $item->role_title,
                    'division' => $item->division,
                ])
                ->all();
        }

        foreach ($roleItems as $index => $item) {
            $roleKey = (string) ($item['role'] ?? '');
            if ($roleKey === '') {
                continue;
            }

            DB::table('roles')->updateOrInsert(
                ['key' => $roleKey],
                [
                    'label' => (string) ($item['roleTitle'] ?? $roleKey),
                    'division' => (string) ($item['division'] ?? 'General Division'),
                    'description' => $roleKey === 'superadmin'
                        ? 'Akses penuh ke dashboard admin sistem dan seluruh modul operasional.'
                        : null,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('app_navigation_modules')) {
            DB::table('app_navigation_modules')
                ->whereIn('module_key', [
                    'admin_users',
                    'admin_roles',
                    'admin_master',
                    'admin_modules',
                    'admin_module_roles',
                    'admin_mappings',
                    'admin_audit',
                ])
                ->update([
                    'show_in_navbar' => false,
                    'admin_only_dashboard' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('app_navigation_modules', function (Blueprint $table) {
            $table->dropColumn(['show_in_navbar', 'admin_only_dashboard']);
        });

        Schema::dropIfExists('roles');
    }
};
