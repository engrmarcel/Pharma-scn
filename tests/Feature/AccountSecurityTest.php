<?php

namespace Tests\Feature;

use App\Domain\Access\Services\SecurityPolicy;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

class AccountSecurityTest extends FeatureTestCase
{
    private function setPolicy(array $values): void
    {
        app(SecurityPolicy::class)->update($values, User::factory()->create());
    }

    /** A valid code for a future step inside the accepted window, so it is never a replay of the last one used. */
    private function nextCode(string $secret, int $offset = 1): string
    {
        $g = new Google2FA;

        return $g->oathTotp($secret, $g->getTimestamp() + $offset);
    }

    private function enrol(User $user): array
    {
        $this->actingAsApi($user);
        $secret = $this->api('POST', 'auth/mfa/setup', ['password' => 'password'])->assertOk()->json('data.secret');
        $codes = $this->api('POST', 'auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->json('data.recovery_codes');

        return [$secret, $codes];
    }

    public function test_both_controls_are_off_by_default(): void
    {
        $this->assertSame(['mfa_mode' => 'off', 'email_verification_required' => false], app(SecurityPolicy::class)->current());

        $user = User::factory()->unverified()->create();
        $this->actingAsApi($user)->api('GET', 'reference/countries')->assertOk();
        $this->api('POST', 'auth/mfa/setup', ['password' => 'password'])
            ->assertForbidden()->assertJsonPath('error.code', 'mfa_disabled');
    }

    public function test_platform_admin_can_switch_controls_on_and_it_is_audited(): void
    {
        $admin = $this->platformUser('platform_super_admin');
        [$owner] = $this->organizationMember();

        $this->actingAsApi($owner)->api('PUT', 'admin/security-settings', ['mfa_mode' => 'optional'])->assertForbidden();

        $this->actingAsApi($admin)->api('PUT', 'admin/security-settings', ['mfa_mode' => 'optional', 'email_verification_required' => true])
            ->assertOk()
            ->assertJsonPath('data.mfa_mode', 'optional')
            ->assertJsonPath('data.email_verification_required', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.security_policy_updated']);

        // Mandatory staff MFA cannot be switched on by someone who is not enrolled (lock-out guard).
        $this->api('PUT', 'admin/security-settings', ['mfa_mode' => 'required_for_platform_staff'])
            ->assertStatus(409)->assertJsonPath('error.code', 'mfa_enrollment_required');
    }

    public function test_enrolled_user_must_pass_the_second_factor_to_get_a_token(): void
    {
        // This test makes more attempts than the login rate limit allows (covered in AuthenticationTest).
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->setPolicy(['mfa_mode' => 'optional']);
        $user = User::factory()->create(['email' => 'mfa@example.test']);
        [$secret, $recovery] = $this->enrol($user);
        $this->assertCount(8, $recovery);
        $this->assertNotNull($user->fresh()->mfa_enabled_at);
        $this->assertStringNotContainsString($recovery[0], (string) $user->fresh()->getRawOriginal('mfa_recovery_codes'));

        $login = ['email' => 'mfa@example.test', 'password' => 'password', 'device_name' => 'phone'];

        $this->postJson('/api/v1/auth/tokens', $login)->assertUnauthorized()->assertJsonPath('error.code', 'mfa_required');
        $this->postJson('/api/v1/auth/tokens', $login + ['mfa_code' => '000000'])->assertUnauthorized()->assertJsonPath('error.code', 'mfa_code_invalid');

        $code = $this->nextCode($secret);
        $this->postJson('/api/v1/auth/tokens', $login + ['mfa_code' => $code])->assertCreated();
        // The same code cannot be replayed.
        $this->postJson('/api/v1/auth/tokens', $login + ['mfa_code' => $code])->assertUnauthorized();

        // A recovery code works exactly once.
        $this->postJson('/api/v1/auth/tokens', $login + ['recovery_code' => $recovery[0]])->assertCreated();
        $this->postJson('/api/v1/auth/tokens', $login + ['recovery_code' => $recovery[0]])->assertUnauthorized();
    }

    public function test_switching_mfa_off_platform_wide_stops_challenging_enrolled_users(): void
    {
        $this->setPolicy(['mfa_mode' => 'optional']);
        $user = User::factory()->create(['email' => 'mfa2@example.test']);
        $this->enrol($user);

        $this->setPolicy(['mfa_mode' => 'off']);
        $this->postJson('/api/v1/auth/tokens', ['email' => 'mfa2@example.test', 'password' => 'password', 'device_name' => 'x'])->assertCreated();
    }

    public function test_required_for_platform_staff_blocks_unenrolled_staff_but_not_tenants(): void
    {
        $this->setPolicy(['mfa_mode' => 'required_for_platform_staff']);
        $staff = $this->platformUser('compliance_officer');
        [$owner, $org] = $this->organizationMember();

        $this->actingAsApi($staff)->api('GET', 'admin/organizations')
            ->assertForbidden()->assertJsonPath('error.code', 'mfa_enrollment_required');
        // Enrolment routes stay reachable.
        $this->api('GET', 'auth/me')->assertOk();
        $this->api('GET', 'auth/security')->assertOk()->assertJsonPath('data.mfa_required', true);

        $this->actingAsApi($owner, $org)->api('GET', 'organization')->assertOk();

        [$secret] = $this->enrol($staff);
        $this->actingAsApi($staff)->api('GET', 'admin/organizations')->assertOk();

        // Required users cannot switch it off.
        $this->api('DELETE', 'auth/mfa', ['password' => 'password', 'code' => $this->nextCode($secret)])
            ->assertForbidden()->assertJsonPath('error.code', 'mfa_required_by_policy');
    }

    public function test_organization_can_require_mfa_for_its_members_only_when_platform_allows_it(): void
    {
        [$owner, $org] = $this->organizationMember();
        [$member] = $this->organizationMember(['pharmacist'], organization: $org);

        // Platform MFA off: the organization cannot meaningfully require it.
        $this->actingAsApi($owner, $org)->api('PATCH', 'organization/security', ['require_mfa' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'mfa_enrollment_required');

        $this->setPolicy(['mfa_mode' => 'optional']);
        $this->enrol($owner);
        $this->actingAsApi($owner, $org)->api('PATCH', 'organization/security', ['require_mfa' => true])
            ->assertOk()->assertJsonPath('data.effective', true);

        $this->actingAsApi($member, $org)->api('GET', 'organization')
            ->assertForbidden()->assertJsonPath('error.code', 'mfa_enrollment_required');
        // Outside that organization the member is unaffected.
        $this->api('GET', 'reference/countries')->assertOk();

        $this->setPolicy(['mfa_mode' => 'off']);
        $this->actingAsApi($member, $org)->api('GET', 'organization')->assertOk();
    }

    public function test_email_verification_when_required(): void
    {
        Notification::fake();
        $this->setPolicy(['email_verification_required' => true]);

        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User', 'email' => 'new.user@example.test', 'password' => 'Secret12345', 'password_confirmation' => 'Secret12345', 'device_name' => 'web',
        ])->assertCreated()->assertJsonPath('data.email_verified', false)->assertJsonPath('data.email_verification_required', true)->json('data.token');

        $user = User::query()->where('email', 'new.user@example.test')->first();
        Notification::assertSentTo($user, VerifyEmail::class);

        $headers = ['Authorization' => "Bearer {$token}"];
        $this->getJson('/api/v1/reference/countries', $headers)->assertForbidden()->assertJsonPath('error.code', 'email_unverified');
        $this->postJson('/api/v1/auth/email/verification-notification', [], $headers)->assertStatus(202);

        $this->getJson("/email/verify/{$user->id}/".sha1('wrong@example.test'))->assertForbidden(); // unsigned
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->getJson($link)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reference/countries', $headers)->assertOk();
    }

    public function test_registration_without_verification_requirement_sends_nothing(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Plain', 'email' => 'plain@example.test', 'password' => 'Secret12345', 'password_confirmation' => 'Secret12345', 'device_name' => 'web',
        ])->assertCreated();

        Notification::assertNothingSent();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dup', 'email' => 'plain@example.test', 'password' => 'Secret12345', 'password_confirmation' => 'Secret12345', 'device_name' => 'web',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_platform_can_reset_a_users_mfa(): void
    {
        $this->setPolicy(['mfa_mode' => 'optional']);
        $user = User::factory()->create();
        $this->enrol($user);
        $user->createToken('old');
        $admin = $this->platformUser('platform_operations_admin');

        $this->actingAsApi($admin)->api('POST', "admin/users/{$user->id}/mfa-reset", ['reason' => 'Lost phone, identity confirmed by call'])->assertOk();

        $this->assertNull($user->fresh()->mfa_enabled_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_reset_by_platform', 'auditable_id' => (string) $user->id]);
    }

    public function test_web_login_with_two_factor_challenge(): void
    {
        $this->setPolicy(['mfa_mode' => 'optional']);
        $user = User::factory()->create(['email' => 'web.mfa@example.test']);
        [$secret] = $this->enrol($user);
        $this->app['auth']->forgetGuards();

        $this->post('/login', ['email' => 'web.mfa@example.test', 'password' => 'password'])->assertRedirect('/login/two-factor');
        $this->assertGuest();

        $this->post('/login/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/login/two-factor', ['code' => $this->nextCode($secret)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_reset_flow_revokes_existing_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $user->createToken('device');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.test'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk(); // no enumeration

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'reset@example.test', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/tokens', ['email' => 'reset@example.test', 'password' => 'NewSecret123', 'device_name' => 'x'])->assertCreated();
    }
}
