<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('login_requests')) {
            Schema::create('login_requests', function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->string('user_id', 50)->index();
                $table->string('username', 50);
                $table->string('nama', 100)->nullable();
                $table->string('role', 30)->default('custom');
                $table->string('ip_address', 45)->nullable();
                $table->string('status', 20)->default('pending'); // pending, approved, rejected
                $table->timestamp('approved_at')->nullable();
                $table->string('approved_by', 50)->nullable();
                $table->timestamps();

                $table->index(['status', 'token']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_requests');
    }
};
