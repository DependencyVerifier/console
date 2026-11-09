<?php

declare(strict_types=1);

namespace Tests\Shared\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    #[Test]
    public function it_should_send_a_strict_policy_with_a_fresh_nonce(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_healthcheck', server: ['HTTP_X_REQUEST_ID' => 'forged-by-the-client']);
        $first = $client->getResponse()->headers;
        $client->request('GET', '/_healthcheck');
        $second = $client->getResponse()->headers;

        $policy = (string) $first->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/]{24}'/", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("base-uri 'none'", $policy);
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertNotSame($policy, $second->get('Content-Security-Policy'), 'One nonce per request.');
        self::assertSame('no-referrer', $first->get('Referrer-Policy'));
        self::assertSame('nosniff', $first->get('X-Content-Type-Options'));
        self::assertSame('DENY', $first->get('X-Frame-Options'));
        self::assertStringStartsWith('max-age=', (string) $first->get('Strict-Transport-Security'));
        self::assertNotSame('forged-by-the-client', $first->get('X-Request-Id'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $first->get('X-Request-Id'));
    }

    #[Test]
    public function it_should_never_expose_the_owner_credentials_to_the_web_kernel(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_healthcheck');

        self::assertFalse(getenv('DATABASE_MIGRATIONS_URL'));
        self::assertArrayNotHasKey('DATABASE_MIGRATIONS_URL', $_SERVER);
        self::assertArrayNotHasKey('DATABASE_MIGRATIONS_URL', $_ENV);
    }
}
