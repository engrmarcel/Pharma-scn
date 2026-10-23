<?php

namespace App\Livewire\Admin;

use App\Livewire\Organization\AuditTrail;
use Livewire\Attributes\Title;

/** Platform-level audit trail: catalogue, branding, settings, verification and other actions outside any organization. */
#[Title('Platform audit log')]
class AuditLog extends AuditTrail
{
    public const ROUTE = 'admin.audit';

    protected string $endpoint = 'admin/audit-logs';
}
