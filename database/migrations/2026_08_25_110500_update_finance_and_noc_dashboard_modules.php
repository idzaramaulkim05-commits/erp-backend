<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('key', 'finance')
            ->update(['dashboard_module_key' => 'finance']);

        DB::table('roles')
            ->where('key', 'noc')
            ->update(['dashboard_module_key' => 'noc']);
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('key', ['finance', 'noc'])
            ->update(['dashboard_module_key' => 'service_registrations']);
    }
};
