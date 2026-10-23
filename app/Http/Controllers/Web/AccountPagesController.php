<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Web\ApiError;
use App\Support\Web\InternalApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Guest account pages: sign-up, forgotten password and password reset. Each form calls the
 * public API endpoint in-process (ADR-0024), so the rules are the API's own.
 */
class AccountPagesController extends Controller
{
    private const WEB_SIGNUP_DEVICE = 'web-sign-up';

    public function __construct(private readonly InternalApi $api) {}

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $this->api->post('auth/register', $request->only('name', 'email', 'phone', 'password', 'password_confirmation') + ['device_name' => self::WEB_SIGNUP_DEVICE]);

        // The API answers sign-up with a bearer token for apps; the browser uses a session instead.
        $user = User::query()->where('email', strtolower((string) $request->input('email')))->firstOrFail();
        $user->tokens()->where('name', self::WEB_SIGNUP_DEVICE)->delete();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // An invitation link opened before signing up brings the person back to it.
        return redirect()->intended(route('onboarding.organization'))->with('status', 'Your account is ready. Create your organization, or accept an invitation to join one.');
    }

    public function showForgot(): View
    {
        return view('auth.forgot-password');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $result = $this->api->post('auth/forgot-password', $request->only('email'));

        return back()->with('status', $result['message'] ?? 'If an account exists for that email, a reset link has been sent.');
    }

    /** Target of the reset link in the email (Laravel's `password.reset` route). */
    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        try {
            $this->api->post('auth/reset-password', $request->only('token', 'email', 'password', 'password_confirmation'));
        } catch (ApiError $e) {
            return back()->withInput($request->only('email'))->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', 'Your password has been changed. Sign in with the new one.');
    }
}
