<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->timestamp('payment_confirmed_at')->nullable()->after('management_approval');
            $table->string('payment_confirmed_by')->nullable()->after('payment_confirmed_at');
            $table->text('payment_proof_url')->nullable()->after('payment_confirmed_by');
            $table->string('payment_channel')->nullable()->after('payment_proof_url');
            $table->text('payment_notes')->nullable()->after('payment_channel');
            $table->json('payment_details')->nullable()->after('payment_notes');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropColumn([
                'payment_confirmed_at',
                'payment_confirmed_by',
                'payment_proof_url',
                'payment_channel',
                'payment_notes',
                'payment_details',
            ]);
        });
    }
};
