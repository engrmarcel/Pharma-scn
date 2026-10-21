<?php

namespace App\Domain\Integration\Services;

/**
 * Masks sensitive values wherever an integration payload or error is shown
 * (spec §15.4: error details with sensitive information redacted). Matching
 * is on the key name, case-insensitive, ignoring "-", " " and "_".
 */
class PayloadRedactor
{
    private ?array $keys = null;

    public function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function isSensitive(string $key): bool
    {
        $this->keys ??= array_map(fn ($k) => $this->normalize($k), config('integrations.redact_keys'));

        return in_array($this->normalize($key), $this->keys, true);
    }

    private function normalize(string $key): string
    {
        return str_replace(['-', ' ', '_'], '', strtolower($key));
    }
}
