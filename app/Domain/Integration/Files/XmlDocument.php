<?php

namespace App\Domain\Integration\Files;

use App\Support\Exceptions\BusinessRuleViolation;
use SimpleXMLElement;

/**
 * Safe XML loading for files and IDocs from external systems: no DTDs, no
 * external entities, no network access (XXE and "billion laughs" protection).
 */
final class XmlDocument
{
    public static function load(string $xml): SimpleXMLElement
    {
        // A DOCTYPE is never needed for business data and is the vehicle for entity attacks.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new BusinessRuleViolation('file_unreadable', 'XML with a DOCTYPE declaration is not accepted.', 422);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            $error = libxml_get_errors()[0] ?? null;
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            throw new BusinessRuleViolation('file_unreadable', 'The XML is not well-formed'.($error ? " (line {$error->line}: ".trim($error->message).')' : '').'.', 422,
                $error ? ['line' => $error->line] : []);
        }

        return $document;
    }
}
