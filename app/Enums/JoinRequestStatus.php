<?php

namespace App\Enums;

enum JoinRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Offen', self::Approved => 'Genehmigt', self::Rejected => 'Abgelehnt', self::Cancelled => 'Zurückgezogen'
        };
    }
}
