<?php

declare(strict_types=1);

namespace Shared\Application\Security;

/**
 * Named limiters of config/packages/rate_limiter.yaml (one window each).
 */
enum ThrottleName: string
{
    case SecondFactorShort = 'second_factor_short';
    case SecondFactorLong = 'second_factor_long';
    case SecondFactorIp = 'second_factor_ip';
    case SecondFactorAlert = 'second_factor_alert';
    case PasswordResetEmail = 'password_reset_email';
    case PasswordResetIp = 'password_reset_ip';
    case EmailCodeResend = 'email_code_resend';
    case InvitationResend = 'invitation_resend';
    case InvitationResendTeam = 'invitation_resend_team';
}
