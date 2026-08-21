<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->tableExists('personal_access_tokens')) {
            return;
        }

        $tokenableIdType = $this->columnType('personal_access_tokens', 'tokenable_id');

        if ($tokenableIdType === 'character varying' || $tokenableIdType === 'text') {
            $this->ensureCompositeIndex();

            return;
        }

        DB::statement('DROP INDEX IF EXISTS personal_access_tokens_tokenable_type_tokenable_id_index');
        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE VARCHAR(255) USING tokenable_id::text');
        DB::statement('CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->tableExists('personal_access_tokens')) {
            return;
        }

        $tokenableIdType = $this->columnType('personal_access_tokens', 'tokenable_id');

        if ($tokenableIdType !== 'character varying' && $tokenableIdType !== 'text') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS personal_access_tokens_tokenable_type_tokenable_id_index');
        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE BIGINT USING tokenable_id::bigint');
        DB::statement('CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
    }

    private function tableExists(string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->exists();
    }

    private function columnType(string $table, string $column): ?string
    {
        $result = DB::table('information_schema.columns')
            ->select('data_type')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->first();

        return $result?->data_type;
    }

    private function ensureCompositeIndex(): void
    {
        $exists = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'personal_access_tokens')
            ->where('indexname', 'personal_access_tokens_tokenable_type_tokenable_id_index')
            ->exists();

        if (! $exists) {
            DB::statement('CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
        }
    }
};
