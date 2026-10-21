<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Models\OutboxEvent;
use App\Domain\Integration\Models\WebhookDelivery;
use App\Domain\Integration\Models\WebhookSubscription;
use App\Domain\Integration\Webhooks\OutboundEvents;
use App\Domain\Integration\Webhooks\WebhookService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Outbound events: webhook subscriptions, their deliveries and the outbox.
 */
class IntegrationWebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function eventTypes(): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => OutboundEvents::describe()]);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => WebhookSubscription::query()->orderBy('name')->get()->map(fn ($s) => $this->data($s))]);
    }

    public function show(string $id): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => $this->data(WebhookSubscription::query()->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:2048', 'url'],
            'event_types' => ['required', 'array', 'min:1', 'max:20'],
            'event_types.*' => ['string', 'max:64', 'distinct'],
            'connector_id' => ['nullable', 'ulid'],
        ]);
        [$subscription, $secret] = $this->webhooks->create($data, $request->user());

        return response()->json(['data' => $this->data($subscription) + ['secret' => $secret,
            'notice' => 'Store this signing secret in the receiving system now. It is shown only once.']], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'url' => ['sometimes', 'string', 'max:2048', 'url'],
            'event_types' => ['sometimes', 'array', 'min:1', 'max:20'],
            'event_types.*' => ['string', 'max:64', 'distinct'],
        ]);

        return response()->json(['data' => $this->data($this->webhooks->update(WebhookSubscription::query()->findOrFail($id), $data))]);
    }

    public function status(string $id, string $action): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $status = ['activate' => 'active', 'pause' => 'paused', 'disable' => 'disabled'][$action];

        return response()->json(['data' => $this->data($this->webhooks->setStatus(WebhookSubscription::query()->findOrFail($id), $status))]);
    }

    public function rotateSecret(string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $secret = $this->webhooks->rotateSecret(WebhookSubscription::query()->findOrFail($id));

        return response()->json(['data' => ['secret' => $secret, 'notice' => 'Shown only once. Deliveries are signed with the new secret from now on.']], 201);
    }

    public function test(string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $deliveryId = $this->webhooks->test(WebhookSubscription::query()->findOrFail($id));

        return response()->json(['data' => $this->deliveryData(WebhookDelivery::query()->with('outbox')->findOrFail($deliveryId))], 202);
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        Gate::authorize('integrations.view');
        $subscription = WebhookSubscription::query()->findOrFail($id);
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'delivering', 'delivered', 'failed', 'dead'])]])['status'] ?? null;

        $page = WebhookDelivery::query()->with('outbox')->where('subscription_id', $subscription->id)
            ->when($status, fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn ($d) => $this->deliveryData($d)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function redeliver(string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');

        return response()->json(['data' => $this->deliveryData($this->webhooks->redeliver(WebhookDelivery::query()->findOrFail($id))->fresh('outbox'))]);
    }

    /** Recent outbound events of the organization (whatever their delivery state). */
    public function outbox(Request $request): JsonResponse
    {
        Gate::authorize('integrations.view');
        $type = $request->validate(['event_type' => ['nullable', 'string', 'max:64']])['event_type'] ?? null;

        $page = OutboxEvent::query()->when($type, fn ($q, $v) => $q->where('event_type', $v))->orderByDesc('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn (OutboxEvent $e) => $e->envelope() + ['aggregate_type' => $e->aggregate_type, 'aggregate_id' => $e->aggregate_id]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    private function data(WebhookSubscription $s): array
    {
        $counts = WebhookDelivery::query()->where('subscription_id', $s->id)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'id' => $s->id,
            'name' => $s->name,
            'url' => $s->url,
            'event_types' => $s->event_types,
            'connector_id' => $s->connector_id,
            'status' => $s->status,
            'status_reason' => $s->status_reason,
            'consecutive_failures' => $s->consecutive_failures,
            'last_delivered_at' => $s->last_delivered_at?->toIso8601String(),
            'last_failure_at' => $s->last_failure_at?->toIso8601String(),
            'deliveries' => collect(['pending', 'delivering', 'delivered', 'failed', 'dead'])->mapWithKeys(fn ($k) => [$k => (int) ($counts[$k] ?? 0)]),
        ];
    }

    private function deliveryData(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id,
            'event_id' => $d->outbox->event_id,
            'event_type' => $d->outbox->event_type,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
            'last_attempt_at' => $d->last_attempt_at?->toIso8601String(),
            'response_status' => $d->response_status,
            'response_excerpt' => $d->response_excerpt,
            'error' => $d->error,
            'duration_ms' => $d->duration_ms,
            'delivered_at' => $d->delivered_at?->toIso8601String(),
        ];
    }
}
