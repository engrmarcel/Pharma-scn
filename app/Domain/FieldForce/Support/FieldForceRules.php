<?php

namespace App\Domain\FieldForce\Support;

use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Models\FieldVisitActivity;
use App\Domain\FieldForce\Services\LocationEvidence;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the field-force REST endpoints and the offline sync
 * handlers, so a record is judged the same way however it arrives.
 */
class FieldForceRules
{
    public static function location(string $key): array
    {
        return [
            $key => ['required', 'array'],
            "{$key}.occurred_at" => ['required', 'date'],
            "{$key}.location_status" => ['required', Rule::in(LocationEvidence::STATUSES)],
            "{$key}.latitude" => ['nullable', 'numeric', 'between:-90,90', "required_with:{$key}.longitude"],
            "{$key}.longitude" => ['nullable', 'numeric', 'between:-180,180', "required_with:{$key}.latitude"],
            "{$key}.accuracy_m" => ['nullable', 'numeric', 'min:0', 'max:100000'],
            "{$key}.mock_location" => ['nullable', 'boolean'],
            "{$key}.device" => ['nullable', 'array:platform,os_version,app_version,device_id,model'],
            "{$key}.device.*" => ['nullable', 'string', 'max:128'],
        ];
    }

    public static function checkIn(): array
    {
        return [
            'client_reference' => ['required', 'string', 'max:64'],
            'customer_id' => ['required', 'ulid'],
            'visit_type' => ['required', Rule::in(FieldVisit::TYPES)],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'captured_offline' => ['sometimes', 'boolean'],
        ] + self::location('check_in');
    }

    public static function visitDetails(string $organizationId): array
    {
        return [
            'objectives' => ['nullable', 'string', 'max:5000'],
            'discussion_notes' => ['nullable', 'string', 'max:10000'],
            'products_discussed' => ['nullable', 'array', 'max:50'],
            'products_discussed.*' => ['ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $organizationId)],
            'customer_feedback' => ['nullable', 'string', 'max:5000'],
            'competitor_information' => ['nullable', 'string', 'max:5000'],
            'order_opportunity' => ['sometimes', 'boolean'],
            'outcome' => ['nullable', Rule::in(FieldVisit::OUTCOMES)],
            'next_action' => ['nullable', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public static function activities(string $organizationId, bool $required): array
    {
        return [
            'activities' => [$required ? 'required' : 'sometimes', 'array', 'max:100'],
            'activities.*.activity_type' => ['required', Rule::in(FieldVisitActivity::TYPES)],
            'activities.*.product_id' => ['nullable', 'ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $organizationId)],
            'activities.*.notes' => ['nullable', 'string', 'max:5000'],
            'activities.*.recorded_at' => ['nullable', 'date'],
        ];
    }

    public static function checkOut(string $organizationId): array
    {
        return self::location('check_out') + self::visitDetails($organizationId) + self::activities($organizationId, false)
            + ['outcome' => ['required', Rule::in(FieldVisit::OUTCOMES)]];
    }

    public static function order(string $organizationId, bool $create): array
    {
        return [
            'warehouse_id' => ['nullable', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => [$create ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $organizationId)],
            'lines.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.indicative_unit_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function orderCapture(string $organizationId): array
    {
        return self::order($organizationId, true) + [
            'client_reference' => ['required', 'string', 'max:64'],
            'customer_id' => ['required', 'ulid'],
            'field_visit_id' => ['nullable', 'ulid'],
            'captured_at' => ['required', 'date'],
            'captured_offline' => ['sometimes', 'boolean'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }
}
