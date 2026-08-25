<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private function normalizeHeadKey(string $key): string
    {
        $normalized = Str::of($key)
            ->trim()
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->value();

        $normalized = preg_replace('/^[^a-z]+/', '', $normalized) ?? '';

        return $normalized === 'oprasional' ? 'operasional' : $normalized;
    }

    public function up(): void
    {
        DB::transaction(function () {
            $heads = DB::table('navigation_heads')
                ->orderBy('key')
                ->get(['key']);

            foreach ($heads as $head) {
                $canonicalKey = $this->normalizeHeadKey($head->key);

                if ($canonicalKey === '' || $canonicalKey === $head->key) {
                    continue;
                }

                $canonicalExists = DB::table('navigation_heads')
                    ->where('key', $canonicalKey)
                    ->exists();

                if ($canonicalExists) {
                    DB::table('app_navigation_modules')
                        ->where('navigation_head_key', $head->key)
                        ->update(['navigation_head_key' => $canonicalKey]);

                    DB::table('navigation_heads')
                        ->where('key', $head->key)
                        ->delete();

                    continue;
                }

                DB::table('navigation_heads')
                    ->where('key', $head->key)
                    ->update([
                        'key' => $canonicalKey,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $legacyKey = 'Oprasional';
            $canonicalKey = 'operasional';

            $canonicalExists = DB::table('navigation_heads')
                ->where('key', $canonicalKey)
                ->exists();

            if (! $canonicalExists) {
                return;
            }

            $legacyExists = DB::table('navigation_heads')
                ->where('key', $legacyKey)
                ->exists();

            if ($legacyExists) {
                DB::table('app_navigation_modules')
                    ->where('navigation_head_key', $canonicalKey)
                    ->update(['navigation_head_key' => $legacyKey]);

                DB::table('navigation_heads')
                    ->where('key', $canonicalKey)
                    ->delete();

                return;
            }

            DB::table('navigation_heads')
                ->where('key', $canonicalKey)
                ->update([
                    'key' => $legacyKey,
                    'updated_at' => now(),
                ]);
        });
    }
};
