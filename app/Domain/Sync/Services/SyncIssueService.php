<?php

namespace App\Domain\Sync\Services;

use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Sync\Models\SyncIssue;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Raising and resolving reconciliation issues found while replaying offline
 * transactions. Issues are never deleted; resolving one records who did
 * what and why.
 */
class SyncIssueService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function raise(string $type, string $severity, array $attributes): SyncIssue
    {
        $issue = SyncIssue::query()->create(['type' => $type, 'severity' => $severity] + $attributes);
        $this->audit->record('sync_issue.raised', $issue, new: ['type' => $type, 'severity' => $severity, 'subject' => $attributes['subject_type'] ?? null]);

        return $issue;
    }

    public function resolve(SyncIssue $issue, User $actor, array $data): SyncIssue
    {
        return DB::transaction(function () use ($issue, $actor, $data) {
            $locked = SyncIssue::query()->lockForUpdate()->findOrFail($issue->id);
            if ($locked->status !== 'open') {
                throw new BusinessRuleViolation('sync_issue_closed', 'This issue is already resolved.', 409);
            }

            $resolution = match ($data['action']) {
                'acknowledge' => 'acknowledged',
                'record_found_stock' => throw new BusinessRuleViolation('resolution_not_applicable', 'Recording found stock applies only to stock shortfalls.', 422),
            };

            $locked->forceFill(['status' => 'resolved', 'resolution' => $resolution, 'resolution_notes' => $data['notes'], 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            $this->audit->record('sync_issue.resolved', $locked, new: ['resolution' => $resolution, 'notes' => $data['notes']]);

            return $locked;
        });
    }
}
