<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table): void {
            $table->string('ordered_by')->nullable()->after('management_approval');
            $table->timestamp('ordered_at')->nullable()->after('ordered_by');
            $table->text('ordered_notes')->nullable()->after('ordered_at');
            $table->text('rejection_notes')->nullable()->after('ordered_notes');
            $table->string('last_rejected_by')->nullable()->after('rejection_notes');
            $table->timestamp('last_rejected_at')->nullable()->after('last_rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'ordered_by',
                'ordered_at',
                'ordered_notes',
                'rejection_notes',
                'last_rejected_by',
                'last_rejected_at',
            ]);
        });
    }
};
