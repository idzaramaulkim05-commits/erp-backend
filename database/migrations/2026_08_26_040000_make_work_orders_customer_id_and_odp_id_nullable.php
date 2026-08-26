<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop existing foreign key on customer_id if exists
        try {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropForeign(['customer_id']);
            });
        } catch (\Throwable $e) {
            // Ignore if foreign key was already dropped
        }

        // 2. Make customer_id and odp_id nullable for new installation work orders before activation
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE work_orders ALTER COLUMN customer_id DROP NOT NULL');
            DB::statement('ALTER TABLE work_orders ALTER COLUMN odp_id DROP NOT NULL');
        } else {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->string('customer_id')->nullable()->change();
                $table->string('odp_id')->nullable()->change();
            });
        }

        // 3. Re-add foreign key with null on delete
        try {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            });
        } catch (\Throwable $e) {
            // Ignore if already configured
        }
    }

    public function down(): void
    {
        try {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropForeign(['customer_id']);
            });
        } catch (\Throwable $e) {
            // Ignore
        }

        $fallbackCustomerId = DB::table('customers')->orderBy('id')->value('id');
        $fallbackOdpId = DB::table('network_odps')->orderBy('id')->value('id');

        if ($fallbackCustomerId !== null && $fallbackOdpId !== null) {
            DB::table('work_orders')
                ->whereNull('customer_id')
                ->update(['customer_id' => $fallbackCustomerId]);

            DB::table('work_orders')
                ->whereNull('odp_id')
                ->update(['odp_id' => $fallbackOdpId]);

            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE work_orders ALTER COLUMN customer_id SET NOT NULL');
                DB::statement('ALTER TABLE work_orders ALTER COLUMN odp_id SET NOT NULL');
            } else {
                Schema::table('work_orders', function (Blueprint $table) {
                    $table->string('customer_id')->nullable(false)->change();
                    $table->string('odp_id')->nullable(false)->change();
                });
            }

            Schema::table('work_orders', function (Blueprint $table) {
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            });
        }
    }
};
