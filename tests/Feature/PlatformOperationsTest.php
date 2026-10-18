<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Events\ProductCreated;
use App\Domain\Catalogue\Events\RegulatoryIdentifierUpdated;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Catalogue\Services\ProductCatalogueService;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\FeatureTestCase;

class PlatformOperationsTest extends FeatureTestCase
{
    public function test_health_endpoint_reports_dependency_status_without_details(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok', 'storage' => 'ok']]);
    }

    public function test_operations_status_and_failed_jobs_are_staff_only_and_hide_payloads(): void
    {
        [$owner] = $this->organizationMember();
        $ops = $this->platformUser('platform_operations_admin');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example', 'data' => ['secret' => 'patient-data']]),
            'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now(),
        ]);

        $this->actingAsApi($owner)->api('GET', 'admin/operations/failed-jobs')->assertForbidden();

        $this->actingAsApi($ops)->api('GET', 'admin/operations/status')->assertOk()->assertJsonPath('data.queue.failed_jobs', 1);
        $this->api('GET', 'admin/operations/failed-jobs')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.job', 'App\\Jobs\\Example')
            ->assertJsonPath('data.0.error', 'RuntimeException: boom')
            ->assertDontSee('patient-data');
    }

    public function test_user_directory_and_country_configuration_for_platform_staff(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        User::factory()->create(['email' => 'findme@example.test']);

        $this->actingAsApi($admin)->api('GET', 'admin/users?q=findme')->assertOk()->assertJsonPath('data.0.email', 'findme@example.test');

        $this->api('PATCH', 'admin/countries/gh', ['is_active' => true, 'settings' => ['verification' => ['required_document_categories' => ['business_registration', 'regulatory_licence']]]])
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'country.updated', 'auditable_id' => 'GH']);
    }

    public function test_domain_events_fire_only_after_commit(): void
    {
        $seen = [];
        Event::listen(ProductCreated::class, function (ProductCreated $e) use (&$seen) {
            $seen[] = $e->productId;
        });
        $actor = User::factory()->create();

        try {
            DB::transaction(function () use ($actor) {
                app(ProductCatalogueService::class)->create($this->productPayload(), $actor);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame([], $seen, 'rolled-back product must not emit an event');

        $product = app(ProductCatalogueService::class)->create($this->productPayload(), $actor);
        $this->assertSame([$product->id], $seen);
    }

    public function test_regulatory_changes_emit_events(): void
    {
        Event::fake([RegulatoryIdentifierUpdated::class]);
        $manager = $this->platformUser('platform_operations_admin');
        $product = $this->makeProduct();

        $this->actingAsApi($manager)->api('POST', "admin/products/{$product->id}/regulatory-identifiers", [
            'authority_code' => 'NAFDAC', 'identifier_type' => 'registration_number', 'value' => 'ZZ-FIXTURE0002',
        ])->assertCreated();

        Event::assertDispatched(RegulatoryIdentifierUpdated::class, fn ($e) => $e->change === 'added' && $e->subjectId === $product->id);
    }

    public function test_synthetic_identifiers_can_never_be_marked_verified(): void
    {
        $officer = $this->platformUser('compliance_officer');
        $product = $this->makeProduct();
        $identifier = RegulatoryIdentifier::query()->create([
            'subject_type' => 'product', 'subject_id' => $product->id,
            'authority_id' => RegulatoryAuthority::query()->where('code', 'NAFDAC')->value('id'),
            'country_code' => 'NG', 'identifier_type' => 'registration_number', 'value' => 'DEMO-A4-0001',
        ]);

        $this->actingAsApi($officer)->api('POST', "admin/regulatory-identifiers/{$identifier->id}/verifications", ['method' => 'other', 'result' => 'verified'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'synthetic_identifier_unverifiable');
    }
}
