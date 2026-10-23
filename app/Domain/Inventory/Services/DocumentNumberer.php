<?php

namespace App\Domain\Inventory\Services;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gap-tolerant, collision-free per-organization document numbers
 * (ADJ-000001 …). The sequence row is locked for the rest of the caller's
 * transaction, so call it inside the transaction that creates the document.
 */
class DocumentNumberer
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function next(string $prefix): string
    {
        $organizationId = $this->tenant->id() ?? throw new \LogicException('Document numbering requires a tenant context.');

        // Lock first; insert only for a new prefix. INSERT IGNORE on an existing row takes a shared lock,
        // and two transactions upgrading shared locks to exclusive deadlock (found by the Phase 10 load test).
        $query = fn () => DB::table('document_sequences')->where('organization_id', $organizationId)->where('prefix', $prefix)->lockForUpdate()->first();
        $row = $query();
        if ($row === null) {
            DB::table('document_sequences')->insertOrIgnore(['organization_id' => $organizationId, 'prefix' => $prefix, 'next_value' => 1]);
            $row = $query();
        }
        DB::table('document_sequences')->where('organization_id', $organizationId)->where('prefix', $prefix)->update(['next_value' => $row->next_value + 1]);

        return sprintf('%s-%06d', $prefix, $row->next_value);
    }
}
