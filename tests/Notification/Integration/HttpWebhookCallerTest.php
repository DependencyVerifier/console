<?php

declare(strict_types=1);

namespace Tests\Notification\Integration;

use Notification\Application\Webhook\WebhookRequest;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryChannel;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\Notice;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RuleThresholds;
use Notification\Infrastructure\Http\HttpWebhookCaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * The webhook call: one POST of the signed request, a 2xx is a success, nothing else is (a redirect is not followed),
 * and the private network is out of reach unless allowed.
 */
final class HttpWebhookCallerTest extends TestCase
{
    #[Test]
    public function it_should_post_the_signed_request_once(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options['body'], $options['max_redirects'], $options['timeout'], \is_array($options['headers'] ?? null) ? $options['headers'] : []];

            return new MockResponse('', ['http_code' => 204]);
        });
        $request = $this->request('https://8.8.8.8/hooks/in');

        self::assertNull(new HttpWebhookCaller($client, false)->call($request));
        self::assertCount(1, $seen);
        [$method, $url, $body, $redirects, $timeout, $headers] = $seen[0];
        self::assertSame(['POST', 'https://8.8.8.8/hooks/in', $request->body, 0, 10.0], [$method, $url, $body, $redirects, $timeout]);
        self::assertContains('X-DepVerify-Signature: '.$request->headers['X-DepVerify-Signature'], $headers);
        self::assertContains('Content-Type: application/json', $headers);
    }

    #[Test]
    public function it_should_fail_on_anything_but_a_2xx(): void
    {
        foreach ([500 => 'HTTP 500', 302 => 'HTTP 302', 404 => 'HTTP 404', 199 => 'HTTP 199', 200 => null, 299 => null, 300 => 'HTTP 300'] as $status => $expected) {
            $client = new MockHttpClient(new MockResponse('{"secret":"never kept"}', ['http_code' => $status, 'response_headers' => ['Location' => 'https://8.8.4.4/']]));
            self::assertSame($expected, new HttpWebhookCaller($client, false)->call($this->request('https://8.8.8.8/in')), (string) $status);
        }

        $down = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Connection refused to secret.internal'));
        self::assertSame('Unreachable (TransportException)', new HttpWebhookCaller($down, false)->call($this->request('https://8.8.8.8/in')));
    }

    #[Test]
    public function it_should_keep_out_of_the_private_network_unless_allowed(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $client = new MockHttpClient(static function (string $method, string $url) use ($calls): MockResponse {
            $calls->append($url);

            return new MockResponse('', ['http_code' => 200]);
        });

        foreach (['https://127.0.0.1/in', 'https://10.0.0.5/in', 'https://[::1]/in', 'https://169.254.169.254/latest/meta-data'] as $url) {
            self::assertSame('Unreachable (TransportException)', new HttpWebhookCaller($client, false)->call($this->request($url)), $url);
        }
        self::assertCount(0, $calls);

        self::assertNull(new HttpWebhookCaller($client, true)->call($this->request('https://127.0.0.1/in')));
        self::assertCount(1, $calls, 'Allowed in dev.');
    }

    private function request(string $url): WebhookRequest
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00');
        $rule = NotificationRule::define(Uuid::v7(), Uuid::v7(), null, null, 'Rule', [NotificationEvent::AgentPending], new RuleThresholds(), [], true, $now);
        $notice = Notice::of(Uuid::v7(), $rule, NotificationEvent::AgentPending, 'k', ['agent' => ['hostname' => 'web-01']], $now);

        return WebhookRequest::for($url, 'whsec_test', $notice, Delivery::schedule(Uuid::v7(), $notice, DeliveryChannel::Webhook, 'w', Frequency::Immediate, $now), $now);
    }
}
