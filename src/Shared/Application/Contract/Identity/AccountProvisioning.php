<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

/**
 * Published port of Identity, called inside the caller's command (same transaction, no nested bus): the account of
 * an email made team admin. An existing account able to sign in is returned as is; an unknown email gets an invited
 * account (no password, a 7-day setup link leaving after the commit). Identity checks its own permission.
 */
interface AccountProvisioning
{
    /**
     * @param string $newUserId      id of the account if it has to be created
     * @param string $setupRequestId id of its setup link if it has to be created
     */
    public function teamAdminAccount(string $email, string $newUserId, string $setupRequestId): ProvisionedAccount;
}
