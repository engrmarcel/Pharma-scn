<?php

namespace App\Console\Commands;

use App\Domain\Integration\Files\SftpTransfer;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Polls the SFTP folders of active SFTP connectors (scheduled every five
 * minutes): imports new files and pushes due exports. Each connector runs in
 * its own organization's context; one failing server never stops the others.
 */
class PollIntegrationSftp extends Command
{
    protected $signature = 'integration:sftp-poll {--connector= : Only this connector id}';

    protected $description = 'Import files from and push exports to connector SFTP servers';

    public function handle(SftpTransfer $transfer, TenantContext $tenant): int
    {
        $connectors = IntegrationConnector::acrossTenants()->with('organization')
            ->where('inbound_transport', 'sftp')->where('status', 'active')
            ->when($this->option('connector'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($connectors as $connector) {
            if (! $connector->organization->isActive()) {
                continue;
            }
            try {
                $summary = $tenant->runAs($connector->organization, fn () => $transfer->poll($connector));
                $this->info("{$connector->code}: ".json_encode($summary));
            } catch (BusinessRuleViolation $e) {
                $this->warn("{$connector->code}: {$e->errorCode}");
            }
        }

        return self::SUCCESS;
    }
}
