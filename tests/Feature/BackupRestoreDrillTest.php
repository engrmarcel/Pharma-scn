<?php

namespace Tests\Feature;

use App\Domain\Platform\Services\DatabaseBackups;
use Database\Seeders\TestingSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithPlatform;
use Tests\TestCase;

/**
 * Phase 10c: a backup is taken with the real client tools and restored into a throwaway database,
 * which is checked. Uses committed data (truncation, like InventoryConcurrencyTest), because the dump
 * runs in another process; skipped where mysqldump and mysql are not available.
 */
class BackupRestoreDrillTest extends TestCase
{
    use DatabaseTruncation, InteractsWithInventory, InteractsWithPlatform;

    protected bool $seed = true;

    protected string $seeder = TestingSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        // XAMPP keeps the client tools off PATH.
        foreach (['mysqldump' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe', 'mysql' => 'C:\\xampp\\mysql\\bin\\mysql.exe'] as $key => $xampp) {
            if (config("backup.{$key}") === $key && PHP_OS_FAMILY === 'Windows' && is_file($xampp)) {
                config(["backup.{$key}" => $xampp]);
            }
        }
        foreach (['mysqldump', 'mysql'] as $tool) {
            $probe = new Process([config("backup.{$tool}"), '--version']);
            $probe->run();
            if (! $probe->isSuccessful()) {
                $this->markTestSkipped("{$tool} is not available.");
            }
        }
        config(['backup.disk' => 'backups', 'backup.enabled' => true]);
        Storage::fake('backups');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        $this->seed(TestingSeeder::class);

        parent::tearDown();
    }

    public function test_a_backup_restores_into_a_throwaway_database_and_passes_every_check(): void
    {
        [, $org, $product, $main] = $this->stockedOrganization();
        $this->receive($org, $main, $product, 'BKP-1', '25', '+1 year');

        $this->artisan('ops:backup')->assertSuccessful();
        $manifest = app(DatabaseBackups::class)->list()[0];
        $this->assertGreaterThan(0, $manifest['tables']['inventory_movements']);
        Storage::disk('backups')->assertExists("backups/{$manifest['file']}");
        $this->assertSame($manifest['sha256'], hash('sha256', Storage::disk('backups')->get("backups/{$manifest['file']}")));

        $this->artisan('ops:restore-drill')->assertSuccessful()->expectsOutputToContain('The drill passed.');
        $drill = DB::table('recovery_drills')->sole();
        $this->assertSame('passed', $drill->status);
        $checks = json_decode($drill->checks, true);
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], [$checks['checksum'], $checks['tables']['status'], $checks['schema']['status'], $checks['consistency']['status']]);
        $this->assertNotNull(DB::table('operational_heartbeats')->where('name', 'restore_drill')->value('beat_at'));
        $live = config('database.connections.'.config('database.default').'.database');
        $this->assertSame(0, DB::table('information_schema.schemata')->where('schema_name', "{$live}_restore_drill")->count(), 'the drill database is dropped afterwards');
    }

    public function test_a_damaged_backup_fails_the_drill_and_raises_an_alert(): void
    {
        $this->artisan('ops:backup')->assertSuccessful();
        $manifest = app(DatabaseBackups::class)->list()[0];
        Storage::disk('backups')->put("backups/{$manifest['file']}", 'not the backup');

        $this->artisan('ops:restore-drill')->assertFailed();
        $this->assertSame('failed', DB::table('recovery_drills')->value('status'));
        $this->assertStringContainsString('checksum', DB::table('recovery_drills')->value('error'));
        $this->assertSame('critical', DB::table('operational_alerts')->where('check', 'restore_drill')->where('status', 'open')->value('severity'));
    }

    public function test_the_drill_never_restores_over_a_database_not_named_for_drills(): void
    {
        $this->artisan('ops:backup')->assertSuccessful();
        $live = config('database.connections.'.config('database.default').'.database');

        $this->artisan('ops:restore-drill', ['--database' => $live])->assertFailed()->expectsOutputToContain('must end in _restore_drill');
        $this->assertSame(0, DB::table('recovery_drills')->count());
    }
}
