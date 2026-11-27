<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use Identity\Infrastructure\Security\SessionKeys;
use Identity\Infrastructure\Security\SessionLifetimeSubscriber;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

final class AccountSecurityTest extends IdentityWebTestCase
{
    #[Test]
    public function it_should_enrol_totp_and_show_the_backup_codes_once(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');

        $crawler = $this->client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
        $this->client->submit($crawler->filter('form[action="/account/security/totp/start"]')->form());
        self::assertResponseRedirects('/account/security');

        $crawler = $this->client->followRedirect();
        $secret = $crawler->filter('code')->text();
        $form = $crawler->filter('form[action="/account/security/totp/confirm"]')->form(['totp_confirm[code]' => '000000' === Accounts::totpCode($secret) ? '111111' : '000000']);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422, 'A wrong code shows no backup code.');
        self::assertSelectorNotExists('[data-testid="backup-codes"]');
        self::assertCount(1, array_filter(AuditLog::entries($this->connection(), 'identity.confirm_totp'), static fn (array $e): bool => 'failure' === $e['outcome']));

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->filter('form[action="/account/security/totp/confirm"]')->form(['totp_confirm[code]' => Accounts::totpCode($secret)]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $codes = $this->client->getCrawler()->filter('[data-testid="backup-codes"] li')->each(static fn ($node): string => $node->text());
        self::assertCount(10, $codes);
        self::assertStringNotContainsString($codes[0], serialize($this->client->getRequest()->getSession()->all()), 'Never through the session.');
        self::assertSame([], array_filter(AuditLog::entries($this->connection(), ''), static fn (array $e): bool => str_contains($e['context'], $codes[0])));

        $this->client->request('GET', '/account/security');
        self::assertResponseRedirects('/login', message: 'Every session is revoked once TOTP is on.');
    }

    #[Test]
    public function it_should_ask_for_a_recent_authentication_before_a_sensitive_action(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $session = $this->client->getRequest()->getSession();
        $session->set(SessionKeys::RECENT_AUTHENTICATION_AT, time() - 601);
        $session->save();

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->filter('form[action="/account/security/totp/start"]')->form());
        self::assertResponseRedirects('/reauthenticate');

        $crawler = $this->client->followRedirect();
        $code = $this->lastEmailCode();
        $this->client->submit($crawler->filter('form')->form(['reauthenticate[password]' => 'wrong password!', 'reauthenticate[code]' => $code]));
        self::assertResponseStatusCodeSame(422);

        $this->client->submit($this->client->getCrawler()->filter('form')->form(['reauthenticate[password]' => Accounts::PASSWORD, 'reauthenticate[code]' => $code]));
        self::assertResponseRedirects('/account/security', message: 'Back to the stored route, never a client URL.');
    }

    #[Test]
    public function it_should_change_the_password_and_sign_every_session_out(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->filter('form[action="/account/security/password"]')->form([
            'change_password[password]' => 'jane@example.com is weak',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('jane@example.com is weak', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->filter('form[action="/account/security/password"]')->form([
            'change_password[password]' => 'a brand new passphrase',
        ]));
        self::assertResponseRedirects('/login');
        self::assertEmailCount(1, message: 'Alert to the holder.');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function it_should_end_an_idle_session_after_two_hours(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $clock = new MockClock();
        Clock::set($clock);
        try {
            $clock->sleep(SessionLifetimeSubscriber::IDLE_SECONDS);
            $this->client->request('GET', '/');
            self::assertResponseIsSuccessful('Two hours idle exactly: still signed in, and the request counts as activity.');

            $clock->sleep(SessionLifetimeSubscriber::IDLE_SECONDS + 1);
            $this->client->request('GET', '/');
            self::assertResponseRedirects('/login');
        } finally {
            Clock::set(new NativeClock());
        }
    }

    #[Test]
    public function it_should_never_put_a_hash_or_a_secret_in_the_session(): void
    {
        $user = Accounts::create(self::getContainer(), 'jane@example.com');
        $totp = Accounts::withTotp(self::getContainer(), $user);
        $this->submitPassword('jane@example.com');
        $this->submitSecondFactor(Accounts::totpCode($totp['secret']));

        $data = serialize($this->client->getRequest()->getSession()->all());
        self::assertStringNotContainsString('$argon2id$', $data);
        self::assertStringNotContainsString($totp['secret'], $data);
    }
}
