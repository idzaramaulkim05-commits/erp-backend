<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_return_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('warehouse_return_requests', 'return_type')) {
                $table->string('return_type')->default('replacement')->after('submitted_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_return_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('warehouse_return_requests', 'return_type')) {
                $table->dropColumn('return_type');
            }
        });
    }
};
