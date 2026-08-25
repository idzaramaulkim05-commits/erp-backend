<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('dashboard_module_key')->nullable()->after('description');
        });

        $defaults = [
            'superadmin' => 'dashboard',
            'management' => 'dashboard',
            'sales' => 'service_registrations',
            'helpdesk' => 'helpdesk',
            'noc' => 'service_registrations',
            'lead_tech' => 'service_registrations',
            'field_tech' => 'field_tech',
            'finance' => 'service_registrations',
            'inventory' => 'inventory',
        ];

        foreach ($defaults as $role => $dashboardModuleKey) {
            DB::table('roles')
                ->where('key', $role)
                ->update([
                    'dashboard_module_key' => $dashboardModuleKey,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('dashboard_module_key');
        });
    }
};
