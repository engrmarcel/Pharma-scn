<?php

namespace App\Domain\Catalogue\Enums;

enum RegulatoryVerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /** Status after recording a verification with the given result. */
    public static function fromResult(string $result): self
    {
        return match ($result) {
            'verified' => self::Verified,
            'failed' => self::Failed,
            'expired' => self::Expired,
            'revoked' => self::Revoked,
            default => self::Pending, // inconclusive: needs follow-up
        };
    }
}
