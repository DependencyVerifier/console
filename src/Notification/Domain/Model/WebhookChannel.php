<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * A URL of the team called in HTTPS with a signed JSON (docs/webhook.md). Its HMAC secret is stored encrypted, bound
 * to the channel, and shown once. The outcome of the last call is kept, for the page of the channel.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_webhook')]
#[ORM\Index(name: 'notification_webhook_team', columns: ['team_id'])]
class WebhookChannel
{
    public const int MAX_NAME = 100;
    public const int MAX_URL = 2048;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(length: 2048)]
        private string $url,
        #[ORM\Column(type: 'text')]
        private string $secret,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $lastCalledAt = null,
        #[ORM\Column(nullable: true)]
        private ?bool $lastSucceeded = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $lastError = null,
    ) {
    }

    /**
     * @param string $secret the HMAC secret, already encrypted
     */
    public static function register(Uuid $id, Uuid $teamId, string $name, string $url, string $secret, \DateTimeImmutable $now): self
    {
        $channel = new self($id, $teamId, '', '', $secret, $now);
        $channel->edit($name, $url);

        return $channel;
    }

    /**
     * An HTTPS URL with a host and nothing to log in (a password in a URL ends in logs).
     */
    public function edit(string $name, string $url): void
    {
        $name = u($name)->trim();
        if ($name->isEmpty() || $name->length() > self::MAX_NAME) {
            throw new InvalidNotificationSetting('A name of 1 to 100 characters.');
        }

        $url = u($url)->trim();
        $parts = $url->length() > self::MAX_URL ? false : parse_url($url->toString());
        if (false === $parts || 'https' !== u($parts['scheme'] ?? '')->lower()->toString() || '' === ($parts['host'] ?? '') || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidNotificationSetting('An HTTPS URL, without user nor password.');
        }

        $this->name = $name->toString();
        $this->url = $url->toString();
    }

    /**
     * @param string $secret the new HMAC secret, already encrypted
     */
    public function rotateSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function called(\DateTimeImmutable $at, ?string $error): void
    {
        $this->lastCalledAt = $at;
        $this->lastSucceeded = null === $error;
        $this->lastError = null === $error ? null : u($error)->truncate(255)->toString();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastCalledAt(): ?\DateTimeImmutable
    {
        return $this->lastCalledAt;
    }

    public function lastSucceeded(): ?bool
    {
        return $this->lastSucceeded;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
