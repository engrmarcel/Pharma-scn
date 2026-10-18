<?php

namespace App\Domain\FieldForce\Services;

use App\Domain\Parties\Models\Customer;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;

/**
 * Turns what the device reported at check-in or check-out into stored
 * evidence (spec §12.2). The server never fills in coordinates and never
 * upgrades an unverifiable event to "verified":
 *
 *   verified                    real fix, precise enough, within the radius of the customer's recorded location
 *   outside_radius              real fix, but too far from the customer
 *   low_accuracy                fix reported, but less precise than the configured limit
 *   mock_location               the device flagged the fix as simulated
 *   no_location                 permission denied/restricted, location services off, unavailable or timed out
 *   customer_location_unknown   real fix, but the customer has no recorded location to compare with
 */
class LocationEvidence
{
    public const STATUSES = ['granted', 'denied', 'restricted', 'service_disabled', 'unavailable', 'timeout'];

    /**
     * @param  array{occurred_at: string, location_status: string, latitude?: ?float, longitude?: ?float, accuracy_m?: ?float, mock_location?: ?bool, device?: ?array}  $event
     * @return array<string, mixed> columns prefixed with $prefix
     */
    public function evaluate(string $prefix, array $event, Customer $customer): array
    {
        $occurredAt = $this->occurredAt($event['occurred_at']);
        $hasFix = $event['location_status'] === 'granted' && isset($event['latitude'], $event['longitude']);

        if ($event['location_status'] === 'granted' && ! $hasFix) {
            throw new BusinessRuleViolation('location_missing', 'Location permission was granted but no coordinates were sent. Report the actual status instead (for example "unavailable" or "timeout").', 422);
        }
        if (! $hasFix && (isset($event['latitude']) || isset($event['longitude']))) {
            throw new BusinessRuleViolation('location_status_inconsistent', 'Coordinates may only be sent with location status "granted".', 422);
        }

        $distance = null;
        if (! $hasFix) {
            $verification = 'no_location';
        } elseif ($event['mock_location'] ?? false) {
            $verification = 'mock_location';
        } elseif (! isset($event['accuracy_m']) || $event['accuracy_m'] > config('field_force.max_accuracy_m')) {
            $verification = 'low_accuracy';
        } elseif ($customer->latitude === null || $customer->longitude === null) {
            $verification = 'customer_location_unknown';
        } else {
            $distance = $this->distanceMetres((float) $event['latitude'], (float) $event['longitude'], (float) $customer->latitude, (float) $customer->longitude);
            $verification = $distance <= config('field_force.verification_radius_m') ? 'verified' : 'outside_radius';
        }

        // Distance is informative even when the fix is not trusted enough to verify.
        if ($distance === null && $hasFix && $customer->latitude !== null && $customer->longitude !== null) {
            $distance = $this->distanceMetres((float) $event['latitude'], (float) $event['longitude'], (float) $customer->latitude, (float) $customer->longitude);
        }

        return [
            "{$prefix}_at" => $occurredAt,
            "{$prefix}_received_at" => now(),
            "{$prefix}_location_status" => $event['location_status'],
            "{$prefix}_latitude" => $hasFix ? $event['latitude'] : null,
            "{$prefix}_longitude" => $hasFix ? $event['longitude'] : null,
            "{$prefix}_accuracy_m" => $hasFix ? ($event['accuracy_m'] ?? null) : null,
            "{$prefix}_mock_location" => $hasFix ? (bool) ($event['mock_location'] ?? false) : null,
            "{$prefix}_distance_m" => $distance === null ? null : (int) round($distance),
            "{$prefix}_verification" => $verification,
            "{$prefix}_device" => $event['device'] ?? null,
        ];
    }

    /** Device clock, refused when implausibly in the future or too old for an offline capture. */
    public function occurredAt(string $value): Carbon
    {
        $at = Carbon::parse($value);
        if ($at->greaterThan(now()->addMinutes(config('field_force.max_clock_skew_minutes')))) {
            throw new BusinessRuleViolation('device_clock_ahead', 'The event time is in the future. Check the device clock.', 422);
        }
        if ($at->lessThan(now()->subDays(config('field_force.max_offline_age_days')))) {
            throw new BusinessRuleViolation('capture_too_old', 'This capture is older than the offline limit and cannot be accepted.', 422);
        }

        return $at;
    }

    /** Great-circle distance (haversine). */
    public function distanceMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
