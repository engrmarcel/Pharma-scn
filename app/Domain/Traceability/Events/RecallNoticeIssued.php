<?php

namespace App\Domain\Traceability\Events;

use App\Support\Events\DomainEvent;

/** A recall reached an organization (also published to its webhook subscribers as recall.notice_issued). */
class RecallNoticeIssued extends DomainEvent
{
    public function __construct(
        public readonly string $recallId,
        public readonly string $noticeId,
        public readonly string $organizationId,
    ) {
        parent::__construct();
    }
}
