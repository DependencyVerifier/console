<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

/**
 * A POST without a valid CSRF token is a 403 with exactly one `csrf.rejected` entry, on every authentication route
 * and on the actions of the authenticated area; the login and the second factor write no login failure on top.
 */
final class CsrfTest extends IdentityWebTestCase
{
    #[Test]
    public function it_should_reject_the_login_without_a_token(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');

        $this->client->request('POST', '/login', ['_username' => 'jane@example.com', '_password' => Accounts::PASSWORD, '_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $this->assertSingleRejection('authenticate');
        self::assertSame([], AuditLog::entries($this->connection(), 'identity.login'), 'No login failure on top.');
    }

    #[Test]
    public function it_should_reject_the_second_factor_without_a_token(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->submitPassword('jane@example.com');
        $code = $this->lastEmailCode();
        $this->client->request('GET', '/2fa');

        $this->client->request('POST', '/2fa_check', ['_auth_code' => $code, '_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $this->assertSingleRejection('two_factor');
        self::assertSame([], AuditLog::entries($this->connection(), 'identity.verify_email_login_code'), 'The code is never evaluated.');
    }

    #[Test]
    public function it_should_reject_the_logout_and_the_code_resend_without_a_token(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->submitPassword('jane@example.com');

        $this->client->request('POST', '/2fa/resend', ['action' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->assertSingleRejection('two_factor_resend');

        $this->client->request('POST', '/logout', ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, AuditLog::entries($this->connection(), 'csrf.rejected'));
    }

    #[Test]
    public function it_should_reject_the_public_forms_without_a_token(): void
    {
        $this->client->request('POST', '/password/forgot', ['email_request' => ['email' => 'jane@example.com', '_token' => 'forged']]);

        self::assertResponseStatusCodeSame(403);
        $this->assertSingleRejection('password_forgot');
        self::assertEmailCount(0);
    }

    #[Test]
    public function it_should_reject_the_reauthentication_and_the_account_actions_without_a_token(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');

        $this->client->request('POST', '/reauthenticate', ['reauthenticate' => ['password' => Accounts::PASSWORD, 'code' => '1', '_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->assertSingleRejection('reauthenticate');

        $this->client->request('POST', '/account/security/totp/start', ['totp_start' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, AuditLog::entries($this->connection(), 'csrf.rejected'));
        self::assertSame([], AuditLog::entries($this->connection(), 'identity.start_totp_enrollment'));
    }

    private function assertSingleRejection(string $tokenId): void
    {
        $entries = AuditLog::entries($this->connection(), 'csrf.rejected');
        self::assertCount(1, $entries);
        self::assertSame('denied', $entries[0]['outcome']);
        self::assertSame('csrf', $entries[0]['reason']);
        self::assertSame(['csrf_token_id' => $tokenId], json_decode($entries[0]['context'], true));
    }
}
