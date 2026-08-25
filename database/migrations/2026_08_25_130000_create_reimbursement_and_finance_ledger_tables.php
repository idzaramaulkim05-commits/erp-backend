<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursement_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('requested_by_id');
            $table->string('requester_role');
            $table->string('requester_division');
            $table->date('transaction_date');
            $table->text('description');
            $table->integer('total_claim')->default(0);
            $table->string('status')->default('draft');
            $table->string('receipt_path')->nullable();
            $table->text('finance_notes')->nullable();
            $table->text('management_notes')->nullable();
            $table->string('finance_reviewed_by')->nullable();
            $table->timestamp('finance_reviewed_at')->nullable();
            $table->string('management_reviewed_by')->nullable();
            $table->timestamp('management_reviewed_at')->nullable();
            $table->string('paid_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by_id', 'status']);
            $table->index(['status', 'transaction_date']);
        });

        Schema::create('reimbursement_request_items', function (Blueprint $table) {
            $table->id();
            $table->string('reimbursement_request_id');
            $table->string('item_name');
            $table->integer('quantity')->default(1);
            $table->string('unit');
            $table->integer('unit_amount')->default(0);
            $table->integer('subtotal')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table
                ->foreign('reimbursement_request_id')
                ->references('id')
                ->on('reimbursement_requests')
                ->cascadeOnDelete();
        });

        Schema::create('finance_mutations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->date('transaction_date');
            $table->string('type');
            $table->string('category');
            $table->integer('amount')->default(0);
            $table->text('description');
            $table->string('reference')->nullable();
            $table->string('status')->default('posted');
            $table->string('created_by_id');
            $table->timestamps();

            $table->index(['transaction_date', 'type']);
            $table->index(['category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_mutations');
        Schema::dropIfExists('reimbursement_request_items');
        Schema::dropIfExists('reimbursement_requests');
    }
};
