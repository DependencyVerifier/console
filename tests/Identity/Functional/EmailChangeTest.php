<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use Identity\Infrastructure\Security\SessionKeys;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * A new email asked from the "Change email" dialog of /admin/users (re-authenticated, never on a super admin): nothing
 * changes until the holder of the new address confirms it from the link sent there; the previous address is told,
 * every session is revoked and the reset links sent to it stop working.
 */
final class EmailChangeTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_apply_a_new_email_only_once_confirmed_from_its_link(): void
    {
        $janeId = $this->askChange('jane.doe@example.com');
        self::assertResponseIsSuccessful();
        self::assertCount(1, AuditLog::entries($this->connection(), 'identity.change_user_email'));
        $link = $this->linkTo('jane.doe@example.com');
        $resetLink = $this->resetLinkFor('jane@example.com');
        self::assertSame('jane@example.com', $this->fetchString('SELECT email FROM identity_user WHERE id = ?', [$janeId]), 'Not applied yet.');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful('A same-site relay page, never an HTTP redirect.');
        $crawler = $this->client->clickLink('Continue');
        self::assertStringNotContainsString(u($link)->afterLast('/')->toString(), (string) $this->client->getRequest()->getUri());
        self::assertSelectorTextContains('main', 'jane.doe@example.com');

        $this->client->request('POST', '/email/confirm', ['action' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, AuditLog::entries($this->connection(), 'csrf.rejected'));

        $this->client->submit($crawler->filter('form')->form());
        self::assertResponseRedirects('/login');
        self::assertSame('jane.doe@example.com', $this->fetchString('SELECT email FROM identity_user WHERE id = ?', [$janeId]));
        self::assertCount(1, AuditLog::entries($this->connection(), 'identity.confirm_email_change'));
        $alerts = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && 'jane@example.com' === $m->getTo()[0]->getAddress()));
        self::assertCount(1, $alerts, 'The previous address is told.');

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410, 'Single use.');
        $this->client->request('GET', $resetLink);
        self::assertResponseStatusCodeSame(410, 'The reset link sent to the previous address is closed.');

        $this->submitPassword('jane.doe@example.com');
        self::assertResponseRedirects('/', message: 'Signs in with the new address.');
    }

    #[Test]
    public function it_should_refuse_an_address_already_taken(): void
    {
        Accounts::create(self::getContainer(), 'john@example.com');
        $this->askChange('john@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    #[Test]
    public function it_should_refuse_the_email_of_a_super_admin(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $peerId = Accounts::create(self::getContainer(), 'peer@example.com', superAdmin: true)->id()->toString();
        $this->logIn('root@example.com');
        $this->post($peerId, 'mine@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Revoke the super admin role first.');
        self::assertEmailCount(0);
        self::assertSame('peer@example.com', $this->fetchString('SELECT email FROM identity_user WHERE id = ?', [$peerId]));
    }

    #[Test]
    public function it_should_ask_for_a_recent_authentication_before_opening_the_dialog(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $janeId = Accounts::create(self::getContainer(), 'jane@example.com')->id()->toString();
        $this->logIn('root@example.com');
        $session = $this->client->getRequest()->getSession();
        $session->set(SessionKeys::RECENT_AUTHENTICATION_AT, time() - 601);
        $session->save();

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, \sprintf('/admin/users/%s/email', $janeId), server: ['HTTP_TURBO_FRAME' => \sprintf('change-email-%s-form', $janeId)]);
            self::assertResponseRedirects('/reauthenticate', message: $method);
        }
        self::assertSame(0, $this->fetchInt('SELECT count(*) FROM identity_email_change_request'));
    }

    #[Test]
    public function it_should_answer_a_neutral_page_to_an_unknown_link_or_a_session_without_request(): void
    {
        $this->client->request('GET', '/email/confirm/'.u('a')->repeat(43)->toString());
        self::assertResponseStatusCodeSame(410);

        $this->client->request('GET', '/email/confirm');
        self::assertResponseStatusCodeSame(410);
    }

    private function askChange(string $newEmail): string
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $janeId = Accounts::create(self::getContainer(), 'jane@example.com')->id()->toString();
        $this->logIn('root@example.com');
        $this->post($janeId, $newEmail);

        return $janeId;
    }

    private function post(string $userId, string $newEmail): void
    {
        $frame = ['HTTP_TURBO_FRAME' => \sprintf('change-email-%s-form', $userId)];
        $form = $this->client->request('GET', \sprintf('/admin/users/%s/email', $userId), server: $frame)
            ->filter(\sprintf('form[action="/admin/users/%s/email"]', $userId))->form(['user_email[email]' => $newEmail]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
    }

    /**
     * A reset link asked by the holder of the previous address while the change waits for its confirmation.
     */
    private function resetLinkFor(string $address): string
    {
        $cookies = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/password/forgot');
        $this->client->submit($crawler->filter('form')->form(['email_request[email]' => $address]));
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame($address, $email->getTo()[0]->getAddress());
        $match = u((string) $email->getTextBody())->match('#(https://localhost/password/reset/[A-Za-z0-9_-]{43})#');
        self::assertIsString($match[1] ?? null);
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }

        return $match[1];
    }

    private function linkTo(string $address): string
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame($address, $email->getTo()[0]->getAddress());
        $match = u((string) $email->getTextBody())->match('#(https://localhost/email/confirm/[A-Za-z0-9_-]{43})#');
        self::assertIsString($match[1] ?? null);

        return $match[1];
    }
}
