<?php

namespace App\Domain\Traceability\Support;

use Illuminate\Support\Carbon;

/**
 * Reads the GS1 data a pack carries, as a scanner delivers it:
 *   - element string with parentheses:  (01)06901234000016(17)281231(10)AMX2026A(21)SN0001
 *   - raw DataMatrix content:            01069012340000161728123110AMX2026A<GS>21SN0001
 *   - GS1 Digital Link URI:              https://id.example/01/06901234000016/10/AMX2026A/21/SN0001?17=281231
 * Supported application identifiers: 00 (SSCC), 01 (GTIN), 10 (batch/lot), 17 (expiry YYMMDD), 21 (serial).
 * The format of national schemes must be confirmed against the applicable specification.
 */
final class Gs1
{
    private const FIXED = ['00' => 18, '01' => 14, '17' => 6];

    private const VARIABLE = ['10' => 20, '21' => 20];

    /** @return array{format: string, gtin?: string, batch_number?: string, expiry_date?: string, serial?: string, sscc?: string}|null */
    public static function parse(string $code): ?array
    {
        $code = trim($code);

        if (preg_match('#^https?://[^/]+(/.*)$#i', $code, $m)) {
            if (! preg_match('#/01/(\d{8,14})#', $m[1], $g)) {
                return null;
            }
            $out = ['format' => 'digital_link', '01' => $g[1]];
            foreach (['10', '21'] as $ai) {
                if (preg_match("#/{$ai}/([^/?]+)#", $m[1], $v)) {
                    $out[$ai] = rawurldecode($v[1]);
                }
            }
            if (preg_match('#[?&]17=(\d{6})#', $m[1], $e)) {
                $out['17'] = $e[1];
            }

            return self::normalize($out);
        }

        if (str_starts_with($code, '(')) {
            preg_match_all('/\((\d{2})\)([^(]+)/', $code, $parts, PREG_SET_ORDER);
            $out = ['format' => 'gs1'];
            foreach ($parts as [, $ai, $value]) {
                $out[$ai] = trim($value);
            }

            return isset($out['01']) || isset($out['00']) ? self::normalize($out) : null;
        }

        // Raw element string; variable-length fields end at the group separator (ASCII 29) or the end.
        $code = preg_replace('/^\]d2|^\]Q3/', '', $code); // symbology identifiers some scanners prepend
        if (! preg_match('/^(00|01)\d/', $code)) {
            return null;
        }
        $out = ['format' => 'gs1'];
        $i = 0;
        $length = strlen($code);
        while ($i < $length) {
            $ai = substr($code, $i, 2);
            $i += 2;
            if (isset(self::FIXED[$ai])) {
                $out[$ai] = substr($code, $i, self::FIXED[$ai]);
                $i += self::FIXED[$ai];
            } elseif (isset(self::VARIABLE[$ai])) {
                $end = strpos($code, "\x1D", $i);
                $value = $end === false ? substr($code, $i) : substr($code, $i, $end - $i);
                $out[$ai] = substr($value, 0, self::VARIABLE[$ai]);
                $i = $end === false ? $length : $end + 1;
            } else {
                return null; // an identifier we do not read: refuse rather than guess
            }
        }

        return self::normalize($out);
    }

    private static function normalize(array $raw): array
    {
        $out = ['format' => $raw['format']];
        if (isset($raw['01'])) {
            $out['gtin'] = $raw['01'];
        }
        if (isset($raw['00'])) {
            $out['sscc'] = $raw['00'];
        }
        if (isset($raw['10'])) {
            $out['batch_number'] = strtoupper($raw['10']);
        }
        if (isset($raw['21'])) {
            $out['serial'] = $raw['21'];
        }
        if (isset($raw['17']) && preg_match('/^(\d{2})(\d{2})(\d{2})$/', $raw['17'], $d)) {
            // GS1: day 00 means the last day of the month.
            $year = 2000 + (int) $d[1];
            $month = (int) $d[2];
            if ($month < 1 || $month > 12) {
                return $out;
            }
            $day = (int) $d[3] === 0 ? Carbon::create($year, $month, 1)->daysInMonth : (int) $d[3];
            if (checkdate($month, $day, $year)) {
                $out['expiry_date'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return $out;
    }
}
