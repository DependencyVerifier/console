<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Tests\Identity\Support\VirtualAuthenticator;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

/**
 * The real ceremony (web-auth/webauthn-lib) against an authenticator signing like a browser's: register a passkey,
 * sign in with it (no password, no second factor), use it as the second factor and to authenticate again, remove it.
 */
final class PasskeyFlowTest extends IdentityWebTestCase
{
    private VirtualAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $defaultUri = $_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'] ?? '';
        $uri = parse_url(\is_string($defaultUri) ? $defaultUri : '');
        self::assertIsArray($uri);
        $this->authenticator = new VirtualAuthenticator(\sprintf('%s://%s%s', $uri['scheme'] ?? 'https', $uri['host'] ?? '', isset($uri['port']) ? ':'.$uri['port'] : ''));
    }

    #[Test]
    public function it_should_register_a_passkey_then_sign_in_with_it_alone(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $this->register('YubiKey office');
        self::assertResponseRedirects('/account/security');
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM identity_passkey'));
        self::assertSame('YubiKey office', $this->fetchString('SELECT name FROM identity_passkey'));
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'identity.register_passkey'), 'outcome'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'YubiKey office');

        $this->signOut();
        $this->signInWithPasskey();
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/account/security');
        self::assertResponseIsSuccessful('A full session: no second factor after a passkey.');
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'identity.login_passkey'), 'outcome'));
        self::assertNotSame('', $this->fetchString('SELECT COALESCE(last_used_at::text, \'\') FROM identity_passkey'));
    }

    #[Test]
    public function it_should_refuse_a_replayed_cloned_or_foreign_signature(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $this->register('Phone');
        $this->signOut();

        $this->signInWithPasskey(signCount: 5);
        self::assertResponseRedirects('/');
        $this->signOut();

        $this->signInWithPasskey(signCount: 5);
        $this->assertRefusedSignIn('A counter that does not grow: a cloned authenticator.');

        $options = $this->loginOptions();
        $answer = $this->authenticator->get($options);
        $this->postSignIn($answer);
        self::assertResponseRedirects('/');
        $this->signOut();
        $this->loginOptions();
        $this->postSignIn($answer);
        $this->assertRefusedSignIn('The answer to another challenge.');

        $foreign = new VirtualAuthenticator('https://evil.example');
        $this->postSignIn($foreign->claiming($this->userId())->get($this->loginOptions()));
        $this->assertRefusedSignIn('An unknown credential.');

        $phished = clone $this->authenticator;
        $phished->origin = 'https://evil.example';
        $this->postSignIn($phished->get($this->loginOptions()));
        $this->assertRefusedSignIn('Another origin.');

        $unverified = clone $this->authenticator;
        $unverified->flags = 0x01;
        $this->postSignIn($unverified->get($this->loginOptions()));
        $this->assertRefusedSignIn('A user present but not verified.');

        $this->postSignIn($this->authenticator->get('{"challenge":"x","rpId":"localhost"}'));
        $this->assertRefusedSignIn('No options given by the server.');
        self::assertCount(5, array_filter(AuditLog::entries($this->connection(), 'identity.login_passkey'), static fn (array $e): bool => 'failure' === $e['outcome']), 'Each refusal audited once, by the command.');
        $logins = array_filter(AuditLog::entries($this->connection(), 'identity.login'), static fn (array $e): bool => 'identity.login' === $e['action'] && 'failure' === $e['outcome']);
        self::assertSame(['bad_credentials'], array_values(array_unique(array_column($logins, 'reason'))), 'Only the refusal before the command (no options) is a login failure.');
        self::assertCount(1, $logins);
    }

    #[Test]
    public function it_should_offer_the_passkey_as_the_second_factor(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $this->register('Laptop');
        $this->signOut();

        $this->submitPassword('jane@example.com');
        $crawler = $this->client->request('GET', '/2fa');
        self::assertCount(1, $crawler->filter('form[data-passkey-mode-value="get"]'), 'The passkey first.');
        self::assertCount(1, $crawler->filter('a[href="/2fa?preferProvider=depverify_email"]'), 'The email code a link away.');
        $options = $this->options('/2fa/passkey/options', (string) $crawler->filter('form[data-passkey-csrf-value]')->attr('data-passkey-csrf-value'));
        $this->client->submit($crawler->filter('form[action="/2fa_check"]')->form(['_auth_code' => $this->authenticator->get($options)]));
        self::assertResponseRedirects('/');
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'identity.verify_passkey'), 'outcome'));

        $this->client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function it_should_authenticate_again_with_a_passkey(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $this->register('Laptop');
        $session = $this->client->getRequest()->getSession();
        $session->set('_depverify.recent_authentication_at', time() - 601);
        $session->save();

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->filter('form[action="/account/security/totp/start"]')->form());
        self::assertResponseRedirects('/reauthenticate');
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[action="/reauthenticate/passkey"]');
        $options = $this->options('/reauthenticate/passkey/options', (string) $form->attr('data-passkey-csrf-value'));
        $this->client->submit($form->form(['_passkey' => $this->authenticator->get($options)]));
        self::assertResponseRedirects('/account/security', message: 'Back to the stored route.');
    }

    #[Test]
    public function it_should_sign_every_session_out_when_a_passkey_is_removed(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $this->register('Laptop');

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->filter('form[action$="/revoke"]')->form());
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM identity_passkey'));
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'identity.revoke_passkey'), 'outcome'));
        $this->client->request('GET', '/account/security');
        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function it_should_let_a_super_admin_remove_a_passkey_of_an_account(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $this->register('Laptop');
        $janeId = $this->userId();
        $passkeyId = $this->fetchString('SELECT id::text FROM identity_passkey');
        $this->signOut();

        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');
        $frame = ['HTTP_TURBO_FRAME' => \sprintf('edit-user-%s-form', $janeId)];
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/edit', $janeId), server: $frame);
        self::assertSelectorTextContains('turbo-frame', 'Laptop');
        $form = $crawler->filter(\sprintf('form[action="/admin/users/%s/passkeys/%s/revoke"]', $janeId, $passkeyId))->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM identity_passkey'));
        self::assertSame([['success', $janeId]], array_map(static fn (array $e): array => [$e['outcome'], $e['target_id']], AuditLog::entries($this->connection(), 'identity.revoke_passkey')));
    }

    #[Test]
    public function it_should_refuse_options_without_the_form_token(): void
    {
        $this->client->request('GET', '/login');
        $this->client->request('POST', '/login/passkey/options', server: ['HTTP_X_CSRF_TOKEN' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/login/passkey', ['_csrf_token' => 'forged', '_passkey' => '{}']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], AuditLog::entries($this->connection(), 'identity.login_passkey'));
    }

    #[Test]
    public function it_should_refuse_an_invalid_registration(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $crawler = $this->client->request('GET', '/account/security');
        $form = $crawler->filter('form[action="/account/security/passkeys"]');
        $options = $this->options('/account/security/passkeys/options', (string) $form->attr('data-passkey-csrf-value'));
        $phished = clone $this->authenticator;
        $phished->origin = 'https://evil.example';
        $this->client->submit($form->form(['passkey_register[name]' => 'Phone', 'passkey_register[response]' => $phished->create($options)]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM identity_passkey'));
        self::assertSame(['rule:passkey_rejected'], array_column(AuditLog::entries($this->connection(), 'identity.register_passkey'), 'reason'));
    }

    private function register(string $name): void
    {
        $crawler = $this->client->request('GET', '/account/security');
        $form = $crawler->filter('form[action="/account/security/passkeys"]');
        $options = $this->options('/account/security/passkeys/options', (string) $form->attr('data-passkey-csrf-value'));
        $this->client->submit($form->form(['passkey_register[name]' => $name, 'passkey_register[response]' => $this->authenticator->create($options)]));
    }

    private function signInWithPasskey(?int $signCount = null): void
    {
        $this->postSignIn($this->authenticator->get($this->loginOptions(), $signCount));
    }

    private function loginOptions(): string
    {
        $crawler = $this->client->request('GET', '/login');

        return $this->options('/login/passkey/options', (string) $crawler->filter('form[action="/login/passkey"]')->attr('data-passkey-csrf-value'));
    }

    private function postSignIn(string $answer): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form[action="/login/passkey"]')->form(['_passkey' => $answer]));
    }

    private function options(string $url, string $csrf): string
    {
        $this->client->request('POST', $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        return (string) $this->client->getResponse()->getContent();
    }

    private function assertRefusedSignIn(string $message): void
    {
        self::assertResponseRedirects('/login', message: $message);
        $this->client->request('GET', '/account/security');
        self::assertResponseRedirects('/login', message: $message);
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function userId(): string
    {
        return $this->fetchString('SELECT id::text FROM identity_user WHERE email = ?', ['jane@example.com']);
    }
}
