<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\Services\SecurityPolicy;
use App\Domain\Access\Services\TwoFactorService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    private const PENDING_KEY = 'login.mfa_pending';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityPolicy $policy,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::query()->where('email', strtolower($credentials['email']))->where('status', 'active')->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Password accepted; enrolled users must still pass the second factor before a session exists.
        if ($this->policy->mfaAvailable() && $this->twoFactor->isEnabled($user)) {
            $request->session()->put(self::PENDING_KEY, ['user_id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->timestamp]);

            return redirect()->route('login.mfa');
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    public function showMfaChallenge(Request $request): View|RedirectResponse
    {
        return $this->pending($request) ? view('auth.mfa-challenge') : redirect()->route('login');
    }

    public function verifyMfaChallenge(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['nullable', 'string', 'max:10'], 'recovery_code' => ['nullable', 'string', 'max:20']]);
        $user = User::query()->where('status', 'active')->findOrFail($pending['user_id']);

        if (! $this->twoFactor->verifyChallenge($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            $this->audit->record('auth.mfa_challenge_failed', $user, organizationId: false);
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid or expired.']);
        }

        $request->session()->forget(self::PENDING_KEY);

        return $this->completeLogin($request, $user, $pending['remember']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function completeLogin(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.web_login', $user, organizationId: false);

        return redirect()->intended(route('dashboard'));
    }

    /** A pending second-factor login is valid for 5 minutes. */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        return $pending && now()->timestamp - $pending['at'] <= 300 ? $pending : null;
    }
}
