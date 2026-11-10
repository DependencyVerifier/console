<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * A new email asked from the user edit dialog: nothing changes until the holder of the new address confirms it
 * from the link sent there; the previous address is told, every session is revoked.
 */
final class EmailChangeTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_apply_a_new_email_only_once_confirmed_from_its_link(): void
    {
        $janeId = $this->askChange('jane.doe@example.com');
        self::assertResponseIsSuccessful();
        $link = $this->linkTo('jane.doe@example.com');
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

        $frame = ['HTTP_TURBO_FRAME' => \sprintf('edit-user-%s-form', $janeId)];
        $form = $this->client->request('GET', \sprintf('/admin/users/%s/edit', $janeId), server: $frame)
            ->filter(\sprintf('form[action="/admin/users/%s/edit"]', $janeId))->form(['user_edit[email]' => $newEmail]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);

        return $janeId;
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
