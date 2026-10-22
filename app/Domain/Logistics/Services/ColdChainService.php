<?php

namespace App\Domain\Logistics\Services;

use App\Domain\Logistics\Events\TemperatureExcursionDetected;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\TemperatureExcursion;
use App\Domain\Logistics\Models\TemperatureReading;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Notifications\TemperatureExcursionNotification;
use App\Support\Events\DomainEvents;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Temperature records and excursions (spec §18). Readings come from a logger, a
 * connected device or manual checks. Consecutive readings outside the shipment's
 * required range form one excursion; the first one raises an alert (e-mail to the
 * shipper, a shipment event and a logistics.temperature_excursion webhook event).
 * Every excursion must be assessed by the shipper (product usable, quarantine or
 * destroy); proof of delivery records whether one was still unassessed.
 */
class ColdChainService
{
    public function __construct(private readonly ShipmentService $shipments, private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{recorded_at: string, temperature_c: string|float, device_id?: ?string}>  $readings
     * @return array{stored: int, duplicates: int, excursions_opened: int}
     */
    public function ingest(Shipment $shipment, array $readings, string $source, User $actor): array
    {
        if (! in_array($shipment->status, ['dispatched', 'in_transit', 'delivered', 'partially_delivered'], true)) {
            throw new BusinessRuleViolation('shipment_not_in_transit', 'Temperatures are recorded from dispatch onward.', 409);
        }
        usort($readings, fn ($a, $b) => strcmp(Carbon::parse($a['recorded_at'])->toIso8601String(), Carbon::parse($b['recorded_at'])->toIso8601String()));

        return DB::transaction(function () use ($shipment, $readings, $source, $actor) {
            $result = ['stored' => 0, 'duplicates' => 0, 'excursions_opened' => 0];
            $min = $shipment->temperature_min_c === null ? null : (float) $shipment->temperature_min_c;
            $max = $shipment->temperature_max_c === null ? null : (float) $shipment->temperature_max_c;
            $opened = [];

            foreach ($readings as $r) {
                $at = Carbon::parse($r['recorded_at']);
                $device = $r['device_id'] ?? $shipment->temperature_logger_id;
                if (TemperatureReading::query()->where('shipment_id', $shipment->id)->where('recorded_at', $at)->where('device_id', $device)->exists()) {
                    $result['duplicates']++;

                    continue;
                }
                $value = (float) $r['temperature_c'];
                TemperatureReading::query()->create(['shipment_id' => $shipment->id, 'recorded_at' => $at, 'temperature_c' => $value, 'source' => $source,
                    'device_id' => $device, 'recorded_by' => $actor->id]);
                $result['stored']++;
                if (! $shipment->requires_cold_chain) {
                    continue;
                }

                $out = ($min !== null && $value < $min) || ($max !== null && $value > $max);
                $current = TemperatureExcursion::query()->where('shipment_id', $shipment->id)->whereNull('ended_at')->lockForUpdate()->first();
                if ($out && $current === null) {
                    $current = TemperatureExcursion::query()->create(['shipment_id' => $shipment->id, 'started_at' => $at, 'lowest_c' => $value, 'highest_c' => $value]);
                    $opened[] = $current;
                    $result['excursions_opened']++;
                } elseif ($out) {
                    $current->forceFill(['lowest_c' => min((float) $current->lowest_c, $value), 'highest_c' => max((float) $current->highest_c, $value),
                        'readings' => $current->readings + 1])->save();
                } elseif ($current !== null) {
                    $current->forceFill(['ended_at' => $at])->save();
                }
            }

            $opened = array_map(fn (TemperatureExcursion $e) => $e->refresh(), $opened);
            foreach ($opened as $excursion) {
                $this->shipments->event($shipment, 'temperature_excursion', $excursion->started_at,
                    notes: "Outside {$shipment->temperature_min_c} °C to {$shipment->temperature_max_c} °C: {$excursion->lowest_c}–{$excursion->highest_c} °C");
                DomainEvents::raise(new TemperatureExcursionDetected($shipment->id, $excursion->id, $shipment->organization_id));
                $this->audit->record('shipment.temperature_excursion', $shipment, new: ['excursion_id' => $excursion->id, 'started_at' => $excursion->started_at->toIso8601String()],
                    organizationId: $shipment->organization_id);
            }
            if ($opened !== []) {
                $recipients = OrganizationMembership::query()->where('organization_id', $shipment->organization_id)->where('status', 'active')
                    ->whereHas('roles', fn ($r) => $r->where('code', 'org_owner')->orWhereHas('permissions', fn ($p) => $p->where('code', 'logistics.manage')))
                    ->with('user')->get()->pluck('user')->filter();
                Notification::send($recipients, new TemperatureExcursionNotification($shipment->number, (string) $shipment->temperature_min_c,
                    (string) $shipment->temperature_max_c, (string) $opened[0]->lowest_c, (string) $opened[0]->highest_c, $opened[0]->started_at->toIso8601String()));
            }

            return $result;
        });
    }

    public function assess(TemperatureExcursion $excursion, Shipment $shipment, string $assessment, string $notes, User $actor): TemperatureExcursion
    {
        $this->shipments->assertShipper($shipment);
        if ($excursion->status === 'assessed') {
            throw new BusinessRuleViolation('excursion_assessed', 'This excursion has already been assessed.', 409);
        }
        $excursion->forceFill(['status' => 'assessed', 'assessment' => $assessment, 'assessment_notes' => $notes, 'assessed_by' => $actor->id, 'assessed_at' => now()])->save();
        $this->shipments->event($shipment, 'note', notes: "Excursion assessed: {$assessment}. {$notes}");
        $this->audit->record('shipment.excursion_assessed', $shipment, new: ['excursion_id' => $excursion->id, 'assessment' => $assessment]);

        return $excursion;
    }
}
