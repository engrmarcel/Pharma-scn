<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Services\DeviceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $devices) {}

    /** Members see their own devices; device managers see all of the organization's. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['active', 'revoked'])], 'user_id' => ['nullable', 'integer']]);

        return response()->json(Device::query()->with('user:id,name,email')
            ->when(! Gate::allows('devices.manage'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->latest()->paginate(50));
    }

    /** Called once by the app after sign-in; the response's token replaces the sign-in token. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::in(['android', 'ios', 'other'])],
            'app_version' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in(Device::ROLES)],
            'pos_terminal_id' => ['nullable', 'ulid', 'required_if:role,pos'],
        ]);
        $result = $this->devices->register($request->user(), $data);

        return response()->json(['data' => [
            'device' => $result['device'],
            'token' => $result['token'],
            'token_expires_at' => $result['expires_at'],
            'note' => 'Use this token for all further requests from this device. The token expires after a period without contact; sign in again to renew it.',
        ]], 201);
    }

    /** Device managers revoke any device; members may revoke their own (for example a lost phone). */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $device = Device::query()->findOrFail($id);
        abort_unless(Gate::allows('devices.manage') || $device->user_id === $request->user()->id, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'wipe' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->devices->revoke($device, $request->user(), $data['reason'], (bool) ($data['wipe'] ?? false))]);
    }
}
