<?php

namespace App\Domain\Integration\Files;

use App\Domain\Integration\Models\IntegrationConnector;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the SFTP filesystem for a connector from its settings (host, port,
 * root, host_fingerprint) and its encrypted credentials (sftp_username plus
 * sftp_password or sftp_private_key/sftp_passphrase).
 *
 * The server's host key fingerprint must be pinned: without it a
 * man-in-the-middle could receive the files and the credentials.
 */
class RemoteDisks
{
    public function for(IntegrationConnector $connector): Filesystem
    {
        $sftp = $connector->setting('sftp');
        if (! is_array($sftp) || empty($sftp['host'])) {
            throw new BusinessRuleViolation('sftp_not_configured', 'Set settings.sftp.host (and the folders) for this connector.', 422);
        }
        if (empty($sftp['host_fingerprint'])) {
            throw new BusinessRuleViolation('sftp_host_unverified', 'Set settings.sftp.host_fingerprint to the SFTP server\'s key fingerprint. Connections to unverified hosts are refused.', 422);
        }
        $credentials = $connector->credentials ?? [];
        if (empty($credentials['sftp_username']) || (empty($credentials['sftp_password']) && empty($credentials['sftp_private_key']))) {
            throw new BusinessRuleViolation('sftp_credentials_missing', 'Store sftp_username and sftp_password or sftp_private_key in the connector credentials.', 422);
        }

        return Storage::build(array_filter([
            'driver' => 'sftp',
            'host' => $sftp['host'],
            'port' => (int) ($sftp['port'] ?? 22),
            'username' => $credentials['sftp_username'],
            'password' => $credentials['sftp_password'] ?? null,
            'privateKey' => $credentials['sftp_private_key'] ?? null,
            'passphrase' => $credentials['sftp_passphrase'] ?? null,
            'hostFingerprint' => $sftp['host_fingerprint'],
            'root' => $sftp['root'] ?? '',
            'timeout' => 30,
            'throw' => true,
        ], fn ($v) => $v !== null));
    }
}
