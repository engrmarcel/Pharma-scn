<?php

namespace Tests\Feature;

use App\Domain\Platform\Services\OperationsMonitor;
use App\Jobs\QueueHeartbeat;
use App\Livewire\Admin\Operations;
use App\Notifications\OperationalAlertNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

/** Phase 10b: queue backlogs and failures are observable, and somebody is told. */
class OperationsMonitorTest extends FeatureTestCase
{
    use InteractsWithInventory;

    public function test_heartbeats_record_the_scheduler_and_the_queue_workers(): void
    {
        Queue::fake();
        $this->artisan('ops:heartbeat')->assertSuccessful();
        Queue::assertPushed(QueueHeartbeat::class, fn ($job) => $job->queueName === 'default' && $job->queue === 'default');
        $this->assertNotNull(DB::table('operational_heartbeats')->where('name', 'scheduler')->value('beat_at'));

        (new QueueHeartbeat('default'))->handle(app(OperationsMonitor::class));
        $metrics = app(OperationsMonitor::class)->metrics();
        $this->assertSame(0, $metrics['queues']['default']['heartbeat_age_seconds']);
        $this->assertSame(0, $metrics['scheduler_heartbeat_age_seconds']);
    }

    public function test_an_alert_opens_once_notifies_operations_staff_and_resolves_when_the_problem_clears(): void
    {
        Notification::fake();
        $ops = $this->platformUser('platform_operations_admin');
        $compliance = $this->platformUser('compliance_officer');
        $monitor = app(OperationsMonitor::class);
        $monitor->beat('scheduler');
        $monitor->beat('queue:default');

        $this->assertSame(0, $monitor->run()['opened'], 'healthy: nothing opens');

        // Two failed jobs in the last hour cross the warning threshold (1).
        foreach (range(1, 2) as $i) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now()]);
        }
        $this->assertSame(1, $monitor->run()['opened']);
        $alert = DB::table('operational_alerts')->where('check', 'failed_jobs_last_hour')->sole();
        $this->assertSame(['open', 'warning'], [$alert->status, $alert->severity]);
        Notification::assertSentTo($ops, OperationalAlertNotification::class, fn ($n) => $n->event === 'opened');
        Notification::assertNotSentTo($compliance, OperationalAlertNotification::class);

        // Still failing a minute later: the same alert, no second e-mail.
        Carbon::setTestNow(now()->addMinute());
        $monitor->beat('scheduler');
        $monitor->beat('queue:default');
        $this->assertSame(0, $monitor->run()['opened']);
        Notification::assertSentToTimes($ops, OperationalAlertNotification::class, 1);

        // The failures age out of the last hour: resolved, and staff are told.
        Carbon::setTestNow(now()->addHours(2));
        $monitor->beat('scheduler');
        $monitor->beat('queue:default');
        $this->assertSame(1, $monitor->run()['resolved']);
        $this->assertSame('resolved', DB::table('operational_alerts')->where('id', $alert->id)->value('status'));
        Notification::assertSentTo($ops, OperationalAlertNotification::class, fn ($n) => $n->event === 'resolved');
        Carbon::setTestNow();
    }

    public function test_a_stopped_scheduler_or_worker_is_reported_and_shown_on_the_operations_screen(): void
    {
        Notification::fake();
        $monitor = app(OperationsMonitor::class);
        $monitor->beat('scheduler');
        Carbon::setTestNow(now()->addMinutes(15));
        $monitor->run();
        Carbon::setTestNow();

        $open = DB::table('operational_alerts')->where('status', 'open')->pluck('severity', 'check');
        $this->assertSame('critical', $open['scheduler_heartbeat_age_seconds'], '15 minutes without the scheduler');
        $this->assertSame('critical', $open['queue_heartbeat_age_seconds'], 'no worker has ever run the heartbeat job');

        $admin = $this->platformUser('platform_super_admin');
        $this->actingAsApi($admin)->api('GET', 'admin/operations/status')->assertOk()
            ->assertJsonPath('data.alerts.0.severity', 'critical')->assertJsonStructure(['data' => ['queue' => ['by_queue' => ['default' => ['pending', 'heartbeat_age_seconds']]], 'scheduler_heartbeat_age_seconds']]);
        $this->api('GET', 'admin/operations/alerts?status=open')->assertOk()->assertJsonCount(2, 'data');
        Livewire::actingAs($admin)->test(Operations::class)->assertSee('The scheduler last ran')->assertSee('no worker has processed the heartbeat job yet');
    }

    public function test_prometheus_metrics_need_the_configured_token(): void
    {
        $this->getJson('/api/v1/metrics')->assertNotFound(); // no token configured: endpoint off

        config(['operations.metrics_token' => 'scrape-secret']);
        $this->get('/api/v1/metrics', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $body = $this->get('/api/v1/metrics', ['Authorization' => 'Bearer scrape-secret'])->assertOk()->getContent();
        $this->assertStringContainsString('# TYPE pharma_queue_pending_jobs gauge', $body);
        $this->assertStringContainsString('pharma_queue_pending_jobs{queue="default"} 0', $body);
        $this->assertStringContainsString('pharma_queue_heartbeat_age_seconds{queue="default"} NaN', $body);
        $this->assertStringContainsString('pharma_failed_jobs_total 0', $body);
    }

    public function test_the_nightly_consistency_check_raises_and_clears_an_alert(): void
    {
        Notification::fake();
        [, $org, $product, $main] = $this->stockedOrganization();
        $this->receive($org, $main, $product, 'OPS-1', '10', '+1 year');

        DB::table('inventory_balances')->where('organization_id', $org->id)->limit(1)->update(['quantity' => DB::raw('quantity + 1')]);
        $this->artisan('ops:consistency', ['--alert' => true])->assertFailed();
        $alert = DB::table('operational_alerts')->where('check', 'consistency')->sole();
        $this->assertSame(['open', 'critical'], [$alert->status, $alert->severity]);
        $this->assertStringContainsString('balances match movements', $alert->message);

        DB::table('inventory_balances')->where('organization_id', $org->id)->limit(1)->update(['quantity' => DB::raw('quantity - 1')]);
        $this->artisan('ops:consistency', ['--alert' => true])->assertSuccessful();
        $this->assertSame('resolved', DB::table('operational_alerts')->where('id', $alert->id)->value('status'));
    }
}
