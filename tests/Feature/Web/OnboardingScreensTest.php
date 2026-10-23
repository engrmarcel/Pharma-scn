<?php

namespace Tests\Feature\Web;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Services\MembershipService;
use App\Livewire\Onboarding\AcceptInvitation;
use App\Livewire\Onboarding\CreateOrganization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\FeatureTestCase;

class OnboardingScreensTest extends FeatureTestCase
{
    public function test_sign_up_creates_a_session_and_leads_to_organization_registration(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('register'), false)->assertSee(route('password.request'), false);
        $this->get(route('register'))->assertOk()->assertSee('Create your account');

        $this->post(route('register.store'), ['name' => 'Ada Obi', 'email' => 'ada@example.test', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->post(route('register.store'), ['name' => 'Ada Obi', 'email' => 'Ada@Example.test', 'password' => 'Correct-horse-42', 'password_confirmation' => 'Correct-horse-42'])
            ->assertRedirect(route('onboarding.organization'));
        $user = User::query()->where('email', 'ada@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->tokens()->count(), 'the web sign-up keeps no API token');

        $this->get(route('dashboard'))->assertOk()->assertSee(route('onboarding.organization'), false);
        $this->get(route('onboarding.organization'))->assertOk()->assertSee('Register your organization')->assertSee('Nigeria');

        Livewire::actingAs($user)->test(CreateOrganization::class)->set('org.name', '')->call('save')->assertHasErrors('org.name');
        Livewire::actingAs($user)->test(CreateOrganization::class)
            ->set('org.name', 'Obi Pharmacy')->set('org.type', 'pharmacy')->set('org.city', 'Lagos')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('organization.profile'));

        $org = Organization::query()->where('name', 'Obi Pharmacy')->sole();
        $this->assertTrue($org->memberships()->where('user_id', $user->id)->exists());
        $this->assertSame($org->id, session(config('platform.tenancy.session_key')));
        $this->get(route('organization.profile'))->assertOk()->assertSee('Obi Pharmacy');
    }

    public function test_forgotten_password_reset_through_the_emailed_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);

        $this->get(route('password.request'))->assertOk();
        $this->post(route('password.email'), ['email' => 'reset@example.test'])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => 'nobody@example.test'])->assertSessionHas('status'); // same answer either way

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token, $user) {
            $token = $n->token;

            // The emailed link points at the web reset page.
            return str_contains($n->toMail($user)->actionUrl, '/reset-password/'.$n->token);
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee($user->email);
        $this->post(route('password.update'), ['token' => 'wrong', 'email' => $user->email, 'password' => 'Brand-new-pass-1', 'password_confirmation' => 'Brand-new-pass-1'])
            ->assertSessionHasErrors('email');
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'Brand-new-pass-1', 'password_confirmation' => 'Brand-new-pass-1'])
            ->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('Brand-new-pass-1', $user->fresh()->password));
    }

    public function test_an_invited_person_joins_with_the_emailed_code(): void
    {
        Notification::fake();
        [$owner, $org] = $this->organizationMember();
        [, $token] = app(MembershipService::class)->invite($org, 'joiner@example.test', ['org_admin'], $owner);
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class,
            fn ($n, $channels, $notifiable) => str_contains($n->toMail($notifiable)->actionUrl, route('invitations.accept', ['token' => $token])));

        $stranger = User::factory()->create(['email' => 'someone-else@example.test']);
        Livewire::actingAs($stranger)->test(AcceptInvitation::class)->set('token', $token)->call('accept')->assertHasErrors('invite.token');

        $joiner = User::factory()->create(['email' => 'joiner@example.test']);
        $this->actingAs($joiner)->get(route('invitations.accept', ['token' => $token]))->assertOk()->assertSee($token);
        Livewire::withQueryParams(['token' => $token])->actingAs($joiner)->test(AcceptInvitation::class)->call('accept')
            ->assertHasNoErrors()->assertRedirect(route('dashboard'));
        $this->assertTrue($org->memberships()->where('user_id', $joiner->id)->where('status', 'active')->exists());
    }
}
