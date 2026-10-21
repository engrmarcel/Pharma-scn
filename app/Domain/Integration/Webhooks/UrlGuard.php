<?php

namespace App\Domain\Integration\Webhooks;

use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Server-side request forgery protection for webhook URLs. A subscriber's
 * URL must be HTTPS, must not carry credentials, and must not point at
 * loopback, private, link-local (cloud metadata) or reserved addresses,
 * checked again at every delivery against what the host resolves to. The
 * resolved address is pinned for the request, so DNS cannot change between
 * the check and the connection.
 *
 * integrations.webhooks.allow_http / allow_private_networks relax this for
 * local development only.
 */
final class UrlGuard
{
    /** Static checks, for subscription create and update. */
    public static function assertAcceptable(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($host === '' || ! in_array($scheme, config('integrations.webhooks.allow_http') ? ['https', 'http'] : ['https'], true)) {
            throw new BusinessRuleViolation('webhook_url_invalid', 'Webhook URLs must be absolute HTTPS URLs.', 422);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BusinessRuleViolation('webhook_url_invalid', 'Webhook URLs must not contain credentials. Verify deliveries with the signature instead.', 422);
        }
        if (! config('integrations.webhooks.allow_private_networks')
            && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')
                || (filter_var($host, FILTER_VALIDATE_IP) && ! self::isPublic($host)))) {
            throw new BusinessRuleViolation('webhook_url_blocked', 'Webhook URLs must point to a public internet address.', 422);
        }
    }

    /**
     * Checks before a delivery; returns the cURL resolve pin ("host:port:ip") or null when not needed.
     *
     * @throws BusinessRuleViolation webhook_url_blocked (permanent) | webhook_dns_failed (temporary)
     */
    public static function pin(string $url): ?string
    {
        self::assertAcceptable($url);
        if (config('integrations.webhooks.allow_private_networks')) {
            return null;
        }

        $parts = parse_url($url);
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null; // already checked as public
        }

        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw new BusinessRuleViolation('webhook_dns_failed', "The webhook host {$host} could not be resolved.", 502);
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new BusinessRuleViolation('webhook_url_blocked', "The webhook host {$host} resolves to a non-public address.", 422);
            }
        }

        return "{$host}:{$port}:{$ips[0]}";
    }

    private static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
