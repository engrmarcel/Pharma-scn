<?php

namespace Tests\Feature;

use App\Livewire\Admin\LandingPageEditor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * The public landing page and its editor in the administration panel: content is editable,
 * validated (safe links only, a video needs a source), escaped, audited, and uploads are served.
 */
class LandingPageTest extends FeatureTestCase
{
    public function test_the_landing_page_shows_the_network_and_the_field_app_screens(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Every medicine, traced from factory to patient,')
            ->assertSee('data-particles', false)
            ->assertSee('Manufacturers')->assertSee('Traceability ledger')
            ->assertSee('/images/landing/app-my-day.webp', false)
            ->assertSee('Field force app: Visit in progress', false)
            ->assertSee('Sign in')
            ->assertDontSee('<video', false);

        foreach (['app-my-day', 'app-visit', 'app-customer'] as $shot) {
            $this->assertFileExists(public_path("images/landing/{$shot}.webp"));
        }
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('Open dashboard');
    }

    public function test_platform_staff_edit_the_page_in_the_administration_panel(): void
    {
        Storage::fake('local');
        $admin = $this->platformUser('platform_super_admin');

        // Only staff who manage platform settings can open the editor.
        [$owner] = $this->organizationMember();
        $this->actingAs($owner)->get('/admin/landing-page')->assertForbidden();
        $this->actingAs($this->platformUser('platform_operations_admin'))->get('/admin/landing-page')->assertForbidden();
        $this->actingAs($admin)->get('/admin/landing-page')->assertOk()->assertSee('Hero background')->assertSee('Phone screens');

        $editor = Livewire::actingAs($admin)->test(LandingPageEditor::class)
            ->set('content.hero.title', 'Medicines you can trust <script>alert(1)</script>')
            ->set('content.hero.primary_url', 'javascript:alert(1)')
            ->call('save')
            ->assertHasErrors(['content.hero.primary_url']);

        $editor->set('content.hero.primary_url', '/login')
            ->call('add', 'stats')
            ->set('content.stats.4.value', '250')
            ->set('content.stats.4.suffix', '+')
            ->set('content.stats.4.label', 'Pharmacies onboarded (demo)')
            ->call('move', 'network.nodes', 1, -1)
            ->set('shots.1', UploadedFile::fake()->image('visit.png', 390, 844))
            ->call('save')
            ->assertHasNoErrors();

        $uploaded = $editor->get('content.app.screenshots.1.url');
        $this->assertMatchesRegularExpression('#^/landing-media/[a-z0-9]{32}\.png$#', $uploaded);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.landing_page_updated']);

        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Medicines you can trust &lt;script&gt;alert(1)&lt;/script&gt;', $page);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringContainsString('Pharmacies onboarded (demo)', $page);
        $this->assertStringContainsString($uploaded, $page);
        $this->assertLessThan(strpos($page, '>Manufacturers<'), strpos($page, '>Importers<'), 'reordered');
        $this->get($uploaded)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/landing-media/'.str_repeat('a', 32).'.png')->assertNotFound();
        $this->get('/landing-media/..%2F..%2F.env')->assertNotFound();

        // A video background needs a source; with one, the page plays it under the overlay.
        $editor->set('content.background.mode', 'video')->set('content.background.video_url', null)->call('save')->assertHasErrors(['content.background.video_url']);
        $editor->set('content.background.video_url', 'https://cdn.example.test/network.mp4')->set('content.background.overlay_opacity', 80)->call('save')->assertHasNoErrors();
        $this->get('/')->assertSee('<video', false)->assertSee('https://cdn.example.test/network.mp4', false)->assertSee('--hero-overlay: 0.8', false)->assertDontSee('data-particles', false);

        // Reset restores the defaults.
        $editor->call('resetToDefaults');
        $this->get('/')->assertSee('Every medicine, traced from factory to patient,')->assertSee('data-particles', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.landing_page_reset']);
    }
}
