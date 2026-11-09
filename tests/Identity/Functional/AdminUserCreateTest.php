<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * "Create a user" from /admin/users: an invitation by email, the holder chooses the password (nobody else knows
 * it), then signs in with the second factor.
 */
final class AdminUserCreateTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'create-user-form'];

    #[Test]
    public function it_should_invite_a_user_who_then_chooses_a_password_and_signs_in(): void
    {
        $this->asSuperAdmin();
        $this->client->request('GET', '/admin/users');
        self::assertSelectorExists('dialog turbo-frame#create-user-form[src="/admin/users/new"]');

        $this->submit('jane@example.com');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertCount(1, AuditLog::entries($this->connection(), 'identity.invite_user'));
        $link = $this->setupLink();

        $row = array_values(array_filter(DataTables::draw($this->client, '/admin/users', 'users')['data'], static fn (array $r): bool => u($r['email'])->containsAny('jane@example.com')));
        self::assertStringContainsString('Invited', $row[0]['active']);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        $crawler = $this->client->clickLink('Continue');
        $this->client->submit($crawler->filter('form')->form([
            'new_password[password][first]' => Accounts::PASSWORD,
            'new_password[password][second]' => Accounts::PASSWORD,
        ]));
        self::assertResponseRedirects('/login');

        $this->submitPassword('jane@example.com');
        self::assertResponseRedirects('/', message: 'Signed in, the second factor comes next.');
    }

    #[Test]
    public function it_should_refuse_a_taken_email_in_the_dialog(): void
    {
        $this->asSuperAdmin();
        Accounts::create(self::getContainer(), 'jane@example.com');

        $this->submit('jane@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#create-user-form form');
        self::assertEmailCount(0);
        $denied = array_values(array_filter(AuditLog::entries($this->connection(), 'identity.invite_user'), static fn (array $e): bool => 'denied' === $e['outcome']));
        self::assertSame('rule:email_already_registered', $denied[0]['reason'] ?? null);
    }

    #[Test]
    public function it_should_serve_the_dialog_to_the_super_admin_only(): void
    {
        $this->asSuperAdmin();
        $this->client->request('GET', '/admin/users/new');
        self::assertResponseRedirects('/admin/users', 303);

        $this->client->getCookieJar()->clear();
        Accounts::create(self::getContainer(), 'john@example.com');
        $this->logIn('john@example.com');
        $this->client->request('GET', '/admin/users/new', server: self::FRAME);
        self::assertResponseStatusCodeSame(403);
    }

    private function asSuperAdmin(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');
    }

    private function submit(string $email): void
    {
        $form = $this->client->request('GET', '/admin/users/new', server: self::FRAME)
            ->filter('turbo-frame#create-user-form form')->form(['user[email]' => $email]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...self::FRAME]);
    }

    private function setupLink(): string
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Your DependencyVerifier account', $email->getSubject());
        $match = u((string) $email->getTextBody())->match('#(https://localhost/password/reset/[A-Za-z0-9_-]{43})#');
        self::assertIsString($match[1] ?? null);

        return $match[1];
    }
}
