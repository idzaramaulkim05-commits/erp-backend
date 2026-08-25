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
            $table->string('gender', 32)->nullable()->after('nik');
        });

        DB::table('service_registrations')
            ->whereNull('gender')
            ->update(['gender' => 'Laki-laki']);

        DB::statement("ALTER TABLE service_registrations ALTER COLUMN gender SET DEFAULT 'Laki-laki'");
        DB::statement('ALTER TABLE service_registrations ALTER COLUMN gender SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('service_registrations', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
