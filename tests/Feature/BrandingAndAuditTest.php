<?php

namespace Tests\Feature;

use App\Domain\Platform\Models\AuditLog;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Platform\Services\BrandingService;
use LogicException;
use Tests\FeatureTestCase;

class BrandingAndAuditTest extends FeatureTestCase
{
    public function test_branding_comes_from_configuration_by_default(): void
    {
        config(['platform.branding.display_name' => 'Configured Name']);

        $this->getJson('/api/v1/branding')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Configured Name')
            ->assertJsonMissingPath('data.email_from_address');
    }

    public function test_settings_admin_can_rebrand_and_change_is_audited(): void
    {
        $admin = $this->platformUser('platform_super_admin');

        $this->actingAsApi($admin)->api('PUT', 'admin/branding', ['display_name' => 'NewBrand', 'primary_color' => '#112233'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'NewBrand');

        $this->getJson('/api/v1/branding')->assertJsonPath('data.primary_color', '#112233');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.branding_updated', 'actor_user_id' => $admin->id]);

        $this->api('PUT', 'admin/branding', ['primary_color' => 'red'])->assertUnprocessable();
    }

    public function test_organization_branding_overrides_only_permitted_keys(): void
    {
        [, $org] = $this->organizationMember();
        $org->forceFill(['branding' => ['display_name' => 'Chain Rx', 'email_from_address' => 'spoof@evil.test']])->save();

        $brand = app(BrandingService::class)->forOrganization($org);

        $this->assertSame('Chain Rx', $brand['display_name']);
        $this->assertNotSame('spoof@evil.test', $brand['email_from_address']);
    }

    public function test_audit_entries_are_immutable_and_redact_secrets(): void
    {
        $entry = app(AuditLogger::class)->record('test.event', null, [], ['password' => 'hunter2', 'nested' => ['api_key' => 'k'], 'ok' => 'visible'], organizationId: false);

        $this->assertSame('[redacted]', $entry->new_values['password']);
        $this->assertSame('[redacted]', $entry->new_values['nested']['api_key']);
        $this->assertSame('visible', $entry->new_values['ok']);

        try {
            $entry->update(['action' => 'tampered']);
            $this->fail('Audit entries must not be updatable.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        AuditLog::query()->find($entry->id)->delete();
    }
}
