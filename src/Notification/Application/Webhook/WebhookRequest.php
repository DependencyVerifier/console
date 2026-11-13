<?php

declare(strict_types=1);

namespace Notification\Application\Webhook;

use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\Notice;

/**
 * The request of a webhook (docs/webhook.md): a versioned JSON, signed with the secret of the webhook over
 * `timestamp.body`, so the receiver checks both its origin and its freshness.
 */
final readonly class WebhookRequest
{
    public const int VERSION = 1;
    public const string TEST = 'test';

    /**
     * @param array<string, string> $headers
     */
    private function __construct(public string $url, public array $headers, public string $body)
    {
    }

    public static function for(string $url, #[\SensitiveParameter] string $secret, Notice $notice, Delivery $delivery, \DateTimeImmutable $now): self
    {
        return self::build($url, $secret, [
            'version' => self::VERSION,
            'id' => $notice->id()->toRfc4122(),
            'delivery' => $delivery->id()->toRfc4122(),
            'event' => $notice->event()->value,
            'occurred_at' => $notice->createdAt()->format(\DATE_ATOM),
            'team_id' => $notice->teamId()->toRfc4122(),
            'project_id' => $notice->projectId()?->toRfc4122(),
            'data' => $notice->content(),
        ], $now);
    }

    /**
     * The "Test" button of a webhook: the same envelope, event `test`, no data.
     */
    public static function test(string $url, #[\SensitiveParameter] string $secret, string $teamId, string $id, \DateTimeImmutable $now): self
    {
        return self::build($url, $secret, [
            'version' => self::VERSION,
            'id' => $id,
            'delivery' => $id,
            'event' => self::TEST,
            'occurred_at' => $now->format(\DATE_ATOM),
            'team_id' => $teamId,
            'project_id' => null,
            'data' => new \stdClass(),
        ], $now);
    }

    /**
     * @param array{version: int, id: string, delivery: string, event: string, occurred_at: string, team_id: string, project_id: ?string, data: mixed} $envelope
     */
    private static function build(string $url, #[\SensitiveParameter] string $secret, array $envelope, \DateTimeImmutable $now): self
    {
        $body = json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $timestamp = (string) $now->getTimestamp();

        return new self($url, [
            'Content-Type' => 'application/json',
            'X-DepVerify-Event' => $envelope['event'],
            'X-DepVerify-Delivery' => $envelope['delivery'],
            'X-DepVerify-Timestamp' => $timestamp,
            'X-DepVerify-Signature' => self::signature($secret, $timestamp, $body),
        ], $body);
    }

    /**
     * `sha256=` and the hexadecimal HMAC-SHA256 of `timestamp.body`.
     */
    public static function signature(#[\SensitiveParameter] string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
