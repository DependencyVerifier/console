<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Support\Accounts;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

final class PasswordResetTest extends IdentityWebTestCase
{
    #[Test]
    public function it_should_answer_the_same_for_an_unknown_email(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');

        $known = $this->ask('jane@example.com');
        self::assertEmailCount(1);
        $unknown = $this->ask('nobody@example.com');
        self::assertEmailCount(0);

        self::assertSame($known, $unknown);
    }

    #[Test]
    public function it_should_reset_the_password_through_a_single_use_link_without_token_in_the_final_url(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->ask('jane@example.com');
        $link = $this->lastLink();

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful('No HTTP redirect: it would keep the cross-site context and drop the Strict cookie.');
        self::assertSelectorExists('meta[http-equiv="refresh"][content="0;url=/password/reset/new"]');
        $crawler = $this->client->clickLink('Continue');
        self::assertStringNotContainsString(u($link)->afterLast('/')->toString(), (string) $this->client->getRequest()->getUri());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->submit($crawler->filter('form')->form([
            'new_password[password][first]' => 'another long passphrase',
            'new_password[password][second]' => 'another long passphrase',
        ]));
        self::assertResponseRedirects('/login');

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410, 'Single use.');

        $this->submitPassword('jane@example.com', 'another long passphrase');
        self::assertResponseRedirects('/', message: 'Then the second factor is still required.');
        self::assertEmailCount(1);
    }

    #[Test]
    public function it_should_keep_the_form_on_a_weak_password_without_echoing_it(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->ask('jane@example.com');
        $this->client->request('GET', $this->lastLink());
        $crawler = $this->client->clickLink('Continue');

        $this->client->submit($crawler->filter('form')->form([
            'new_password[password][first]' => 'jane@example.com!!',
            'new_password[password][second]' => 'jane@example.com!!',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('jane@example.com!!', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function it_should_stop_sending_beyond_three_requests_per_hour_with_the_same_answer(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        for ($i = 0; $i < 3; ++$i) {
            $answer = $this->ask('jane@example.com');
            self::assertEmailCount(1);
        }

        self::assertSame($answer, $this->ask('jane@example.com'));
        self::assertEmailCount(0);
    }

    private function ask(string $email): string
    {
        $crawler = $this->client->request('GET', '/password/forgot');
        $this->client->submit($crawler->filter('form')->form(['email_request[email]' => $email]));
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler()->filter('main')->text();
    }

    private function lastLink(): string
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);
        $body = $email->getTextBody();
        self::assertIsString($body);
        $match = u($body)->match('#(https://localhost/password/reset/[A-Za-z0-9_-]{43})#');
        self::assertIsString($match[1] ?? null);

        return $match[1];
    }
}
