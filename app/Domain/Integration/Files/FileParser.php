<?php

namespace App\Domain\Integration\Files;

use App\Support\Exceptions\BusinessRuleViolation;
use SimpleXMLElement;

/**
 * Turns an uploaded or transferred file into records (associative arrays)
 * for the integration inbox. Field mapping happens later, exactly as for
 * records pushed over the API.
 *
 *   csv   first row is the header; delimiter from settings.csv_delimiter or detected (, ; tab |)
 *   json  a list of objects, or {"records": [...]} / {"data": [...]}
 *   xml   every element named settings.xml_record_element becomes a record (children → keys,
 *         repeated children → lists, attributes → "@name"). External entities and network
 *         access are disabled.
 */
class FileParser
{
    public const FORMATS = ['csv', 'json', 'xml'];

    /** @return list<array<string, mixed>> */
    public function parse(string $content, string $format, array $settings = []): array
    {
        if (strlen($content) > config('integrations.files.max_bytes')) {
            throw new BusinessRuleViolation('file_too_large', 'The file is larger than '.(int) (config('integrations.files.max_bytes') / 1048576).' MB. Split it into smaller files.', 422);
        }
        $content = $this->stripBom($content);
        if (trim($content) === '') {
            throw new BusinessRuleViolation('file_empty', 'The file is empty.', 422);
        }

        $records = match ($format) {
            'csv' => $this->csv($content, $settings['csv_delimiter'] ?? null),
            'json' => $this->json($content),
            'xml' => $this->xml($content, $settings['xml_record_element'] ?? null),
            default => throw new BusinessRuleViolation('file_format_unsupported', 'Supported formats: '.implode(', ', self::FORMATS).'.', 422),
        };

        if ($records === []) {
            throw new BusinessRuleViolation('file_no_records', 'The file contains no records.', 422);
        }
        if (count($records) > config('integrations.files.max_records')) {
            throw new BusinessRuleViolation('file_too_many_records', 'The file has more than '.config('integrations.files.max_records').' records. Split it into smaller files.', 422);
        }

        return $records;
    }

    public static function formatFromName(?string $name): ?string
    {
        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return in_array($extension, self::FORMATS, true) ? $extension : ($extension === 'txt' ? 'csv' : null);
    }

    private function csv(string $content, ?string $delimiter): array
    {
        $delimiter ??= $this->detectDelimiter(strtok($content, "\r\n") ?: '');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $records = [];
        $line = 0;
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue; // blank line
            }
            if ($header === null) {
                $header = array_map(fn ($h) => trim((string) $h), $row);
                if (in_array('', $header, true) || count(array_unique($header)) !== count($header)) {
                    fclose($stream);
                    throw new BusinessRuleViolation('file_unreadable', 'The CSV header must name every column once.', 422, ['line' => $line]);
                }

                continue;
            }
            if (count($row) !== count($header)) {
                fclose($stream);
                throw new BusinessRuleViolation('file_unreadable', "Line {$line} has ".count($row).' fields; the header has '.count($header).'.', 422, ['line' => $line]);
            }
            $records[] = array_combine($header, array_map(fn ($v) => (string) $v, $row));
        }
        fclose($stream);

        return $records;
    }

    private function detectDelimiter(string $headerLine): string
    {
        $counts = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($headerLine, $candidate);
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function json(string $content): array
    {
        $data = json_decode($content, true, 64);
        if (! is_array($data)) {
            throw new BusinessRuleViolation('file_unreadable', 'The file is not valid JSON: '.json_last_error_msg().'.', 422);
        }
        $records = array_is_list($data) ? $data : ($data['records'] ?? $data['data'] ?? null);
        if (! is_array($records) || ! array_is_list($records)) {
            throw new BusinessRuleViolation('file_unreadable', 'JSON files must hold a list of records, or an object with a "records" list.', 422);
        }
        foreach ($records as $i => $record) {
            if (! is_array($record) || ($record !== [] && array_is_list($record))) {
                throw new BusinessRuleViolation('file_unreadable', 'Record '.($i + 1).' is not a JSON object.', 422, ['record' => $i + 1]);
            }
        }

        return $records;
    }

    private function xml(string $content, ?string $element): array
    {
        if ($element === null || ! preg_match('/^[A-Za-z_][\w.-]*$/', $element)) {
            throw new BusinessRuleViolation('xml_record_element_missing', 'Set the connector setting xml_record_element (the element that holds one record) before importing XML.', 422);
        }

        return array_map(fn (SimpleXMLElement $node) => $this->node($node), XmlDocument::load($content)->xpath('//'.$element) ?: []);
    }

    private function node(SimpleXMLElement $node): array|string
    {
        $out = [];
        foreach ($node->attributes() as $name => $value) {
            $out['@'.$name] = (string) $value;
        }
        $children = [];
        foreach ($node->children() as $name => $child) {
            $children[$name][] = $child->count() > 0 || $child->attributes()->count() > 0 ? $this->node($child) : trim((string) $child);
        }
        foreach ($children as $name => $values) {
            $out[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $out === [] ? trim((string) $node) : $out;
    }

    private function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }
}
