<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Platform\Services\BrandingService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PlatformAdminController extends Controller
{
    public function updateBranding(Request $request, BrandingService $branding): JsonResponse
    {
        Gate::authorize('platform.settings.manage');

        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:100'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'favicon_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'primary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'email_from_name' => ['sometimes', 'string', 'max:100'],
            'email_from_address' => ['sometimes', 'email'],
            'support_email' => ['sometimes', 'email'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        return response()->json(['data' => $branding->update($data, $request->user())]);
    }

    public function suspendUser(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('platform.users.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if ($user->is($request->user())) {
            throw new BusinessRuleViolation('cannot_suspend_self', 'You cannot suspend your own account.', 403);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => $data['reason']])->save();
            // Suspension ends every API session and web session immediately.
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        $audit->record('user.suspended', $user, ['status' => 'active'], ['status' => 'suspended'], ['reason' => $data['reason']], false);

        return response()->json(['data' => ['id' => $user->id, 'status' => $user->status]]);
    }

    public function reactivateUser(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('platform.users.manage');

        $user->forceFill(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])->save();
        $audit->record('user.reactivated', $user, ['status' => 'suspended'], ['status' => 'active'], organizationId: false);

        return response()->json(['data' => ['id' => $user->id, 'status' => $user->status]]);
    }
}
