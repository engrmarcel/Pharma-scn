<?php

namespace App\Domain\Organizations\Enums;

/**
 * Internal platform verification of an organization. This is the platform's
 * own due-diligence state; it is never presented as regulator approval.
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case PendingReview = 'pending_review';
    case ChangesRequested = 'changes_requested';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Revoked = 'revoked';

    public function canSubmit(): bool
    {
        return in_array($this, [self::Unverified, self::ChangesRequested, self::Rejected, self::Revoked], true);
    }

    public function canBeDecided(): bool
    {
        return $this === self::PendingReview;
    }
}
