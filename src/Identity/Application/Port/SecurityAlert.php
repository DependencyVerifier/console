<?php

declare(strict_types=1);

namespace Identity\Application\Port;

/**
 * Decision 26: alerts sent to the account holder, without any action link ("if this was not you, contact the
 * administrator").
 */
enum SecurityAlert: string
{
    case PasswordChanged = 'password_changed';
    case TotpEnabled = 'totp_enabled';
    case TotpDisabled = 'totp_disabled';
    case BackupCodesRegenerated = 'backup_codes_regenerated';
    case SecondFactorReset = 'second_factor_reset';
    case SuperAdminGranted = 'super_admin_granted';
    case SuperAdminRevoked = 'super_admin_revoked';
    case SecondFactorThrottled = 'second_factor_throttled';
    case TrustedDeviceRegistered = 'trusted_device_registered';
    case PasskeyRegistered = 'passkey_registered';
    case PasskeyRevoked = 'passkey_revoked';
}
