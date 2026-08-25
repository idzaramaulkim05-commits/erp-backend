<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('service_started_at')->nullable()->after('billing_due_date');
            $table->date('service_active_until')->nullable()->after('service_started_at');
        });

        DB::table('customers')->orderBy('id')->chunkById(100, function ($customers) {
            foreach ($customers as $customer) {
                $serviceStartedAt = $customer->installed_date
                    ? Carbon::parse($customer->installed_date)->toDateString()
                    : ($customer->created_at ? Carbon::parse($customer->created_at)->toDateString() : Carbon::today()->toDateString());
                $serviceActiveUntil = $customer->billing_due_date
                    ? Carbon::parse($customer->billing_due_date)->toDateString()
                    : Carbon::parse($serviceStartedAt)->addDays(30)->toDateString();

                DB::table('customers')
                    ->where('id', $customer->id)
                    ->update([
                        'service_started_at' => $serviceStartedAt,
                        'service_active_until' => $serviceActiveUntil,
                    ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['service_started_at', 'service_active_until']);
        });
    }
};
