<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

enum CodeKind: string
{
    case Totp = 'totp';
    case TotpEnrollment = 'totp_enrollment';
    case EmailCode = 'email_code';
    case BackupCode = 'backup_code';
    case Passkey = 'passkey';
    case PasskeyLogin = 'passkey_login';
}
