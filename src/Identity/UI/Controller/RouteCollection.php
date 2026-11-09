<?php

declare(strict_types=1);

namespace Identity\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case TWO_FACTOR_RESEND = 'two_factor_resend';
    case PASSWORD_FORGOT = 'password_forgot';
    case PASSWORD_RESET_LANDING = 'password_reset_landing';
    case PASSWORD_RESET_NEW = 'password_reset_new';
    case REAUTHENTICATE = 'reauthenticate';
    case INVITATION_LANDING = 'invitation_landing';
    case INVITATION_PENDING = 'invitation_pending';
    case INVITATION_ACCEPT = 'invitation_accept';
    case ACCOUNT_SECURITY = 'account_security';
    case ACCOUNT_PASSWORD = 'account_password';
    case ACCOUNT_TOTP_START = 'account_totp_start';
    case ACCOUNT_TOTP_CONFIRM = 'account_totp_confirm';
    case ACCOUNT_TOTP_DISABLE = 'account_totp_disable';
    case ACCOUNT_BACKUP_CODES = 'account_backup_codes';
    case ADMIN_USERS = 'admin_users';
    case ADMIN_USER_CREATE = 'admin_user_create';
    case ADMIN_USER_RESET_SECOND_FACTOR = 'admin_user_reset_second_factor';
    case ADMIN_USER_DEACTIVATE = 'admin_user_deactivate';
    case ADMIN_USER_REACTIVATE = 'admin_user_reactivate';
    case ADMIN_USER_DELETE = 'admin_user_delete';
    case ADMIN_USER_RESTORE = 'admin_user_restore';
    case ADMIN_USER_GRANT_SUPER_ADMIN = 'admin_user_grant_super_admin';
    case ADMIN_USER_REVOKE_SUPER_ADMIN = 'admin_user_revoke_super_admin';

    public function prefixed(): string
    {
        return 'identity_'.$this->value;
    }
}
