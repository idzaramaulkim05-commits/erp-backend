<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $legacyKey = 'oprasional';
            $canonicalKey = 'operasional';

            $legacyExists = DB::table('navigation_heads')
                ->where('key', $legacyKey)
                ->exists();

            if (! $legacyExists) {
                return;
            }

            $canonicalExists = DB::table('navigation_heads')
                ->where('key', $canonicalKey)
                ->exists();

            if ($canonicalExists) {
                DB::table('app_navigation_modules')
                    ->where('navigation_head_key', $legacyKey)
                    ->update(['navigation_head_key' => $canonicalKey]);

                DB::table('navigation_heads')
                    ->where('key', $legacyKey)
                    ->delete();

                return;
            }

            DB::table('navigation_heads')
                ->where('key', $legacyKey)
                ->update([
                    'key' => $canonicalKey,
                    'label' => 'Operasional',
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $legacyKey = 'oprasional';
            $canonicalKey = 'operasional';

            $canonicalExists = DB::table('navigation_heads')
                ->where('key', $canonicalKey)
                ->exists();

            if (! $canonicalExists) {
                return;
            }

            DB::table('navigation_heads')
                ->where('key', $canonicalKey)
                ->update([
                    'key' => $legacyKey,
                    'label' => 'Oprasional',
                    'updated_at' => now(),
                ]);
        });
    }
};
