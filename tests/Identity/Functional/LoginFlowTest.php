<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

final class LoginFlowTest extends IdentityWebTestCase
{
    #[Test]
    public function it_should_require_the_email_code_after_the_password(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');

        $this->submitPassword('jane@example.com');

        self::assertResponseRedirects('/');
        self::assertEmailCount(1);
        $code = $this->lastEmailCode();
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa', message: 'The area stays closed until the second factor.');

        $this->submitSecondFactor($code);
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $actions = array_column(AuditLog::entries($this->connection(), 'identity.'), 'action');
        self::assertContains('identity.login_password', $actions);
        self::assertContains('identity.issue_email_login_code', $actions);
        self::assertContains('identity.verify_email_login_code', $actions);
        self::assertContains('identity.login', $actions);
    }

    #[Test]
    public function it_should_answer_the_same_for_an_unknown_email_and_a_wrong_password(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');

        $this->submitPassword('jane@example.com', 'wrong password here');
        self::assertResponseRedirects('/login');
        $wrongPassword = $this->client->followRedirect()->filter('[role=alert]')->text();

        $this->submitPassword('nobody@example.com');
        self::assertResponseRedirects('/login');
        $unknown = $this->client->followRedirect()->filter('[role=alert]')->text();

        self::assertSame($wrongPassword, $unknown);
        self::assertEmailCount(0);
    }

    #[Test]
    public function it_should_refuse_a_wrong_code_and_audit_a_single_failure(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->submitPassword('jane@example.com');
        $code = $this->lastEmailCode();

        $this->submitSecondFactor('000000' === $code ? '111111' : '000000');

        self::assertResponseRedirects('/2fa');
        $failures = array_filter(AuditLog::entries($this->connection(), 'identity.verify_email_login_code'), static fn (array $e): bool => 'failure' === $e['outcome']);
        self::assertCount(1, $failures);
        self::assertSame('rejected', array_first($failures)['reason']);
    }

    #[Test]
    public function it_should_log_in_with_totp_and_refuse_a_replayed_code(): void
    {
        $user = Accounts::create(self::getContainer(), 'jane@example.com');
        $totp = Accounts::withTotp(self::getContainer(), $user);

        $this->submitPassword('jane@example.com');
        self::assertEmailCount(0, message: 'No email code once TOTP is active.');
        $code = Accounts::totpCode($totp['secret']);
        $this->submitSecondFactor($code);
        self::assertResponseRedirects('/');

        $this->client->request('POST', '/logout');
        $this->client->getCookieJar()->clear();
        $this->submitPassword('jane@example.com');
        $this->submitSecondFactor($code);
        self::assertResponseRedirects('/2fa', message: 'A TOTP code is accepted once.');
    }

    #[Test]
    public function it_should_accept_a_backup_code_once(): void
    {
        $user = Accounts::create(self::getContainer(), 'jane@example.com');
        $totp = Accounts::withTotp(self::getContainer(), $user);

        $this->submitPassword('jane@example.com');
        $this->submitSecondFactor($totp['backupCodes'][0]);
        self::assertResponseRedirects('/');

        $this->client->getCookieJar()->clear();
        $this->submitPassword('jane@example.com');
        $this->submitSecondFactor($totp['backupCodes'][0]);
        self::assertResponseRedirects('/2fa');
    }

    #[Test]
    public function it_should_refuse_an_account_without_the_login_permission_or_disabled(): void
    {
        $user = Accounts::create(self::getContainer(), 'jane@example.com');
        $user->deactivate(new \DateTimeImmutable());
        Accounts::manager(self::getContainer())->flush();

        $this->submitPassword('jane@example.com');

        self::assertResponseRedirects('/login');
        self::assertEmailCount(0);
    }

    #[Test]
    public function it_should_regenerate_the_session_id_after_the_second_factor(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->submitPassword('jane@example.com');
        $code = $this->lastEmailCode();
        $before = $this->client->getRequest()->getSession()->getId();

        $this->submitSecondFactor($code);

        self::assertResponseRedirects('/');
        self::assertNotSame('', $before);
        self::assertNotSame($before, $this->client->getRequest()->getSession()->getId());
    }
}
