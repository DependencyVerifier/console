<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

enum EnrollmentTokenStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Revoked = 'revoked';
}
