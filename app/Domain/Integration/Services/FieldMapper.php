<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationFieldMapping;
use App\Support\Exceptions\BusinessRuleViolation;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Maps a source record into the canonical model using the connector's field
 * mappings for that entity. A connector with no mappings for an entity is
 * expected to send canonical records already.
 *
 * Transforms: trim, upper, lower, strip_leading_zeros (SAP material numbers),
 * date (options.format, e.g. "Ymd" or "d.m.Y"), decimal (options.decimal_separator,
 * options.thousands_separator) and map (options.values, options.fallback).
 */
class FieldMapper
{
    public const TRANSFORMS = ['trim', 'upper', 'lower', 'strip_leading_zeros', 'date', 'decimal', 'map'];

    /** @var array<string, Collection> */
    private array $cache = [];

    public function map(IntegrationConnector $connector, string $entity, array $record): array
    {
        $mappings = $this->mappings($connector, $entity);
        if ($mappings->isEmpty()) {
            return $record;
        }

        $out = [];
        foreach ($mappings as $mapping) {
            $value = $mapping->source_path !== null ? data_get($record, $mapping->source_path) : null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '') {
                $value = $mapping->default_value;
            }
            if ($value !== null && $mapping->transform !== null) {
                $value = $this->transform($value, $mapping);
            }
            if ($value !== null && $value !== '') {
                $out[$mapping->target_field] = $value;
            }
        }

        return $out;
    }

    /**
     * Mapping and validation without side effects, for previews: canonical record plus
     * errors, each error naming the source field it was mapped from.
     */
    public function preview(IntegrationConnector $connector, string $entity, array $record): array
    {
        try {
            $canonical = $this->map($connector, $entity, $record);
        } catch (BusinessRuleViolation $e) {
            return ['canonical' => null, 'valid' => false, 'errors' => [['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details]]];
        }
        $validator = Validator::make($canonical, CanonicalModel::rules($entity));

        return ['canonical' => $canonical, 'valid' => ! $validator->fails(), 'errors' => $this->explain($connector, $entity, $validator->errors()->toArray())];
    }

    /** Validation errors with the source field (or the missing mapping) behind each canonical field. */
    public function explain(IntegrationConnector $connector, string $entity, array $errors): array
    {
        $mappings = $this->mappings($connector, $entity)->keyBy('target_field');
        $out = [];
        foreach ($errors as $field => $messages) {
            $top = explode('.', $field)[0];
            $mapping = $mappings[$top] ?? null;
            $out[] = array_filter([
                'field' => $field,
                'messages' => $messages,
                'source_path' => $mapping?->source_path,
                'hint' => $mappings->isEmpty() ? null : ($mapping === null
                    ? "No mapping for '{$top}'. Add one under the connector's {$entity} mappings."
                    : "Mapped from '{$mapping->source_path}'. Check that field in the source record."),
            ]);
        }

        return $out;
    }

    public function forget(IntegrationConnector $connector): void
    {
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $connector->id.'|')) {
                unset($this->cache[$key]);
            }
        }
    }

    private function mappings(IntegrationConnector $connector, string $entity): Collection
    {
        return $this->cache[$connector->id.'|'.$entity] ??= IntegrationFieldMapping::query()
            ->where('connector_id', $connector->id)->where('entity_type', $entity)->orderBy('id')->get();
    }

    private function transform(mixed $value, IntegrationFieldMapping $mapping): mixed
    {
        $options = $mapping->options ?? [];
        $fail = fn (string $why) => throw new BusinessRuleViolation('mapping_failed',
            "Field '{$mapping->target_field}' (from '{$mapping->source_path}'): {$why}", 422,
            ['field' => $mapping->target_field, 'source_path' => $mapping->source_path, 'value' => is_scalar($value) ? (string) $value : null]);

        if (! is_scalar($value)) {
            $fail('expected a single value.');
        }
        $value = (string) $value;

        return match ($mapping->transform) {
            'trim' => trim($value),
            'upper' => strtoupper($value),
            'lower' => strtolower($value),
            'strip_leading_zeros' => ltrim($value, '0') === '' ? '0' : ltrim($value, '0'),
            'date' => $this->date($value, $options['format'] ?? 'Y-m-d', $fail),
            'decimal' => $this->decimal($value, $options, $fail),
            'map' => array_key_exists($value, $options['values'] ?? [])
                ? $options['values'][$value]
                : ($options['fallback'] ?? $fail("no mapped value for '{$value}'.")),
        };
    }

    private function date(string $value, string $format, callable $fail): string
    {
        // SAP sends 00000000 for "no date".
        if (trim($value, '0') === '') {
            return '';
        }
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $fail("'{$value}' is not a date in the format {$format}.");
        }

        return str_contains($format, 'H') ? $parsed->format(DATE_ATOM) : $parsed->format('Y-m-d');
    }

    private function decimal(string $value, array $options, callable $fail): string
    {
        $value = str_replace(' ', '', $value);
        if (isset($options['thousands_separator'])) {
            $value = str_replace($options['thousands_separator'], '', $value);
        }
        if (($options['decimal_separator'] ?? '.') !== '.') {
            $value = str_replace($options['decimal_separator'], '.', $value);
        }
        // Trailing minus (SAP style: "12.500-").
        if (str_ends_with($value, '-')) {
            $value = '-'.substr($value, 0, -1);
        }
        if (! is_numeric($value)) {
            $fail("'{$value}' is not a number.");
        }

        return $value;
    }
}
