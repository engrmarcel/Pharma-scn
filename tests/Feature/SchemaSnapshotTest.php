<?php

namespace Tests\Feature;

use App\Console\Commands\ExportSchemaSql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\FeatureTestCase;

/**
 * Guards the rule that the SQL schema file matches the implemented schema.
 * If this fails, run: php artisan migrate && php artisan schema:export-sql
 */
class SchemaSnapshotTest extends FeatureTestCase
{
    public function test_sql_schema_file_lists_exactly_the_migrated_tables_and_columns(): void
    {
        $sql = file_get_contents(base_path(ExportSchemaSql::PATH));
        $this->assertNotFalse($sql, 'Schema SQL file is missing.');

        preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\n\)/s', $sql, $matches, PREG_SET_ORDER);
        $fileTables = [];
        foreach ($matches as [, $table, $body]) {
            preg_match_all('/^\s+`([^`]+)`/m', $body, $columns);
            $fileTables[$table] = $columns[1];
        }

        // Limit to this database: on MySQL/MariaDB the listing otherwise spans every schema on the server.
        $dbTables = collect(Schema::getTableListing(DB::getDatabaseName(), schemaQualified: false))->sort()->values()->all();
        $this->assertSame($dbTables, collect(array_keys($fileTables))->sort()->values()->all(), 'Tables differ from migrations.');

        foreach ($dbTables as $table) {
            $this->assertEqualsCanonicalizing(Schema::getColumnListing($table), $fileTables[$table], "Columns of {$table} differ from migrations.");
        }
    }
}
