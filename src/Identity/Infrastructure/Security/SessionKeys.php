<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

/**
 * Session attributes owned by Identity. Only ids, timestamps and message keys: never a token that opens a door.
 */
final class SessionKeys
{
    public const string AUTHENTICATED_AT = '_depverify.authenticated_at';
    public const string LAST_ACTIVITY = '_depverify.last_activity';
    public const string RECENT_AUTHENTICATION_AT = '_depverify.recent_authentication_at';
    public const string AUTH_ERROR = '_depverify.auth_error';
    public const string REAUTH_RETURN_ROUTE = '_depverify.reauth_return_route';
    public const string PASSWORD_RESET_REQUEST = '_depverify.password_reset_request';
    public const string INVITATION = '_depverify.invitation';
    public const string EMAIL_CHANGE_REQUEST = '_depverify.email_change_request';
}
