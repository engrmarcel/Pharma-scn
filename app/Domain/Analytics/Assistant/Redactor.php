<?php

namespace App\Domain\Analytics\Assistant;

/**
 * Removes obvious personal identifiers from a question before it is stored or, with consent,
 * sent to an external model: e-mail addresses, phone numbers and long digit sequences
 * (national identity, account and prescription numbers). Names cannot be detected reliably,
 * which is why external processing needs the organization's explicit consent.
 */
final class Redactor
{
    public static function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
        // Dates (2026-03-31) stay: they define the reporting period.
        $text = preg_replace_callback('/\+?\d[\d\s().-]{8,}\d/', fn ($m) => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($m[0])) ? $m[0] : '[number]', $text);

        return preg_replace('/\b\d{6,}\b/', '[number]', $text);
    }
}
