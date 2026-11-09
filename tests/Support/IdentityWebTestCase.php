<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

use function Symfony\Component\String\u;

/**
 * Functional tests of the authentication flows. The limiter states live on the non-static `rate_limiter`
 * connection (committed), so each test starts from empty windows.
 */
abstract class IdentityWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient(server: ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost']);
        self::getContainer()->get('doctrine.dbal.rate_limiter_connection')->executeStatement('DELETE FROM rate_limiter_state');
    }

    protected function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    /**
     * Password step; the email code provider sends a code right away when TOTP is off.
     */
    protected function submitPassword(string $email, string $password = Accounts::PASSWORD): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form[action="/login"]')->form(['_username' => $email, '_password' => $password]);
        $this->client->submit($form);
    }

    protected function submitSecondFactor(string $code, bool $trust = false): void
    {
        $crawler = $this->client->request('GET', '/2fa');
        $values = ['_auth_code' => $code];
        if ($trust) {
            $values['_trusted'] = '1';
        }

        $this->client->submit($crawler->filter('form[action="/2fa_check"]')->form($values));
    }

    protected function logIn(string $email): void
    {
        $this->submitPassword($email);
        $this->submitSecondFactor($this->lastEmailCode());
        self::assertResponseRedirects('/');
    }

    protected function lastEmailCode(): string
    {
        $messages = self::getMailerMessages();
        $last = end($messages);
        self::assertInstanceOf(Email::class, $last, 'An email login code was sent.');
        $body = $last->getTextBody();
        self::assertIsString($body);
        $match = u($body)->match('/^(\d{6})$/m');
        self::assertIsString($match[1] ?? null);

        return $match[1];
    }
}
