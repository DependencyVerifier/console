<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Http;

use Notification\Application\Port\WebhookCaller;
use Notification\Application\Webhook\WebhookRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A webhook URL is typed by a team: it never reaches the private network of the console (SSRF), unless
 * NOTIFICATION_WEBHOOK_ALLOW_PRIVATE=1 (dev, a local receiver). No redirect is followed (it could lead inside), no
 * retry here (the delivery counts its attempts), and the answer is never read nor kept.
 */
final readonly class HttpWebhookCaller implements WebhookCaller
{
    private HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $client,
        #[Autowire(env: 'bool:NOTIFICATION_WEBHOOK_ALLOW_PRIVATE')]
        bool $allowPrivateNetwork,
    ) {
        $this->client = $allowPrivateNetwork ? $client : new NoPrivateNetworkHttpClient($client);
    }

    public function call(WebhookRequest $request): ?string
    {
        try {
            $status = $this->client->request('POST', $request->url, [
                'headers' => $request->headers,
                'body' => $request->body,
                'timeout' => 10,
                'max_duration' => 15,
                'max_redirects' => 0,
            ])->getStatusCode();
        } catch (ExceptionInterface $failure) {
            return 'Unreachable ('.new \ReflectionClass($failure)->getShortName().')';
        }

        return $status >= 200 && $status < 300 ? null : 'HTTP '.$status;
    }
}
