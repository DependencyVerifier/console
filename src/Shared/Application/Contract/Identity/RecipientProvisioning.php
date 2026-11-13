<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

/**
 * Published port of Identity, called inside the caller's command (same transaction, no nested bus): the account of a
 * notification recipient added by email. An unknown email gets a notification-only account (no password, no
 * `account.login`, nothing sent by Identity); an existing notification-only account is returned as is. An account
 * that signs in is refused (attaching it would open a project to it without its consent: it is invited, or
 * subscribes itself), and so is a deactivated or deleted one. The caller checks its own permission first.
 */
interface RecipientProvisioning
{
    /**
     * @param string $newUserId id of the account if it has to be created
     */
    public function notificationAccount(string $email, string $newUserId): ProvisionedAccount;
}
