<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_registrations', function (Blueprint $table) {
            $table->dropForeign(['odp_id']);
        });

        DB::statement('ALTER TABLE service_registrations ALTER COLUMN odp_id DROP NOT NULL');

        Schema::table('service_registrations', function (Blueprint $table) {
            $table->foreign('odp_id')->references('id')->on('network_odps')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_registrations', function (Blueprint $table) {
            $table->dropForeign(['odp_id']);
        });

        $fallbackOdpId = DB::table('network_odps')->orderBy('id')->value('id');

        if ($fallbackOdpId !== null) {
            DB::table('service_registrations')
                ->whereNull('odp_id')
                ->update(['odp_id' => $fallbackOdpId]);

            DB::statement('ALTER TABLE service_registrations ALTER COLUMN odp_id SET NOT NULL');
        }

        Schema::table('service_registrations', function (Blueprint $table) {
            $table->foreign('odp_id')->references('id')->on('network_odps')->cascadeOnDelete();
        });
    }
};
