<?php

namespace Tests\Feature;

use App\Livewire\Admin\LandingPageEditor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/** Phase 10d: what has to hold when several application instances serve the platform. */
class ScalingReadinessTest extends FeatureTestCase
{
    public function test_every_scheduled_task_runs_on_one_server_only(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertTrue($event->onOneServer, "{$event->command} would run once per instance");
        }
    }

    public function test_landing_media_is_stored_and_served_from_the_shared_disk(): void
    {
        config(['platform.landing.media_disk' => 'shared-media']);
        Storage::fake('shared-media');
        Storage::fake('local');
        $admin = $this->platformUser('platform_super_admin');

        $url = Livewire::actingAs($admin)->test(LandingPageEditor::class)
            ->set('shots.0', UploadedFile::fake()->image('till.png', 390, 844))
            ->call('save')->assertHasNoErrors()
            ->get('content.app.screenshots.0.url');

        $file = 'landing/'.basename($url);
        Storage::disk('shared-media')->assertExists($file);
        Storage::disk('local')->assertMissing($file);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
