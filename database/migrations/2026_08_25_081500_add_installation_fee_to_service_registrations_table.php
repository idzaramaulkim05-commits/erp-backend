<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_registrations', function (Blueprint $table) {
            $table->unsignedInteger('installation_fee')->default(0)->after('monthly_fee');
        });
    }

    public function down(): void
    {
        Schema::table('service_registrations', function (Blueprint $table) {
            $table->dropColumn('installation_fee');
        });
    }
};
