<?php

namespace Tests\Feature\Web;

use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\WebhookSubscription;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Livewire\Analytics\Assistant;
use App\Livewire\Analytics\Dashboards;
use App\Livewire\Analytics\Forecast;
use App\Livewire\Analytics\Recommendations;
use App\Livewire\Integration\Connectors;
use App\Livewire\Integration\References;
use App\Livewire\Integration\Webhooks;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithIntegrations;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class IntegrationSyncAnalyticsScreensTest extends FeatureTestCase
{
    use InteractsWithIntegrations, InteractsWithInventory;

    private function as(User $user, $org): void
    {
        app(TenantContext::class)->set($org, OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->sole());
    }

    public function test_connector_keys_mappings_webhooks_and_references(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $main = $this->warehouse($org);
        $this->as($admin, $org);

        $screen = Livewire::actingAs($admin)->test(Connectors::class)
            ->call('create')->set('form.code', 'S4')->set('form.name', 'Demo S/4HANA')->call('save')->assertHasNoErrors();
        $connector = IntegrationConnector::query()->sole();
        $screen->call('rotateKey')->assertSet('secret.label', 'Inbound API key')->assertSee('shown only once')
            ->call('setSigning', true)->assertSet('secret.label', 'Request signing secret')
            ->call('setStatus', 'activate');
        $this->assertSame('active', $connector->fresh()->status);
        $this->assertNotNull($connector->fresh()->signing_secret);

        $screen->set('tab', 'mappings')->set('entity', 'inventory_snapshot')
            ->set('mappingRows.0.target_field', 'warehouse_code')->set('mappingRows.0.source_path', 'WERKS')->call('saveMappings')->assertHasNoErrors()
            ->set('sample', '{"WERKS": "MAIN"}')->call('runPreview')->assertHasNoErrors()->assertSet('preview', fn ($p) => is_array($p) && count($p) === 1);

        Livewire::actingAs($admin)->test(Webhooks::class)
            ->call('create')->set('form.name', 'ERP events')->set('form.url', 'https://erp.example.test/hooks')->set('form.event_types', ['inventory.stock_received'])
            ->call('save')->assertHasNoErrors()->assertSee('Signing secret')->call('act', 'test')->assertHasNoErrors();
        $this->assertSame(1, WebhookSubscription::query()->count());

        Livewire::actingAs($admin)->test(References::class)
            ->call('create')->set('form.connector_id', $connector->id)->set('form.entity_type', 'warehouse')->set('form.external_id', '1000')->set('form.internal_id', $main->id)
            ->call('save')->assertHasNoErrors()->assertSee('1000');

        $this->actingAs($admin)->withSession([config('platform.tenancy.session_key') => $org->id]);
        foreach (['integration.connectors' => 'Demo S/4HANA', 'integration.messages' => 'No messages', 'integration.runs' => 'No runs yet', 'integration.webhooks' => 'ERP events',
            'integration.references' => '1000', 'integration.health' => 'Backlog'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }

    public function test_sync_and_analytics_pages(): void
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        OrganizationModule::query()->create(['organization_id' => $org->id, 'module' => 'analytics', 'enabled' => true]);
        $this->as($owner, $org);

        Livewire::actingAs($owner)->test(Dashboards::class)->assertSee('Revenue')->set('board', 'inventory')->assertHasNoErrors()->set('board', 'expiry-risk')->assertHasNoErrors();
        Livewire::withQueryParams(['product' => $product->id])->actingAs($owner)->test(Forecast::class)->assertSee('weeks of sales history');
        Livewire::actingAs($owner)->test(Recommendations::class)->call('generate')->assertHasNoErrors();
        Livewire::actingAs($owner)->test(Assistant::class)->set('question', 'What were sales last month?')->call('ask')->assertHasNoErrors()
            ->assertCount('conversation', 1);

        $this->actingAs($owner)->withSession([config('platform.tenancy.session_key') => $org->id]);
        foreach (['analytics.dashboards' => 'Purchases', 'analytics.forecast' => 'Choose a product', 'analytics.recommendations' => 'Refresh now',
            'analytics.assistant' => 'Ask about your data', 'sync.overview' => 'Issues to review'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }
}
