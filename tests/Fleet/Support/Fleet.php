<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\AgentRuntime;
use Fleet\Domain\Model\AgentScope;
use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\Host;
use Fleet\Domain\Model\HostFacts;
use Fleet\Domain\Model\HostId;
use Symfony\Component\Uid\Uuid;

/**
 * Fixed ids and builders of the Fleet tests.
 */
final class Fleet
{
    public const string TEAM = '019a6b2c-2f00-7a11-8b22-3c44d55e6f01';
    public const string OTHER_TEAM = '019a6b2c-2f00-7a11-8b22-3c44d55e6f02';
    public const string ENVIRONMENT = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';
    public const string ADMIN = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    public const string TOKEN = '019a6b2c-6f00-7a11-8b22-3c44d55e6f01';
    public const string HOST = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';
    public const string AGENT = '019a6b2c-8f00-7a11-8b22-3c44d55e6f01';
    public const string ORDER = '019a6b2c-9f00-7a11-8b22-3c44d55e6f01';
    /** The agent key of the protocol request vector. */
    public const string KEY = 'ed25519:11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=';
    public const string FINGERPRINT = 'SHA256:If4x36FUomFia/hUBG/SJxt77UtqvkWqWId+9H+XIbk';
    public const string MACHINE = '5f0c2e6b1a9d8c7e3f4a5b6c7d8e9f0a1b2c3d4e5f60718293a4b5c6d7e8f901';

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-09 08:00:00');
    }

    public static function single(string $teamId = self::TEAM, string $id = self::TOKEN): EnrollmentToken
    {
        return EnrollmentToken::single(EnrollmentTokenId::fromString($id), Uuid::fromString($teamId), Uuid::fromString(self::ENVIRONMENT), 'hash-'.$id, Uuid::fromString(self::ADMIN), self::now());
    }

    public static function reusable(int $maxUses = 5, string $id = self::TOKEN): EnrollmentToken
    {
        return EnrollmentToken::reusable(EnrollmentTokenId::fromString($id), Uuid::fromString(self::TEAM), Uuid::fromString(self::ENVIRONMENT), 'hash-'.$id, Uuid::fromString(self::ADMIN), self::now(), self::now()->modify('+30 days'), $maxUses);
    }

    public static function facts(): HostFacts
    {
        return new HostFacts('web-01', 'debian', '12', 'Debian GNU/Linux 12 (bookworm)', '6.1.0-27-amd64', 'x86_64', 'b1c7a0f2-3e4d-4c5b-9a8f-7e6d5c4b3a21', self::MACHINE);
    }

    public static function host(string $teamId = self::TEAM, string $id = self::HOST): Host
    {
        return Host::register(HostId::fromString($id), Uuid::fromString($teamId), self::facts()->identityFor(AgentMode::Container), self::facts(), self::now());
    }

    public static function runtime(): AgentRuntime
    {
        return new AgentRuntime(AgentMode::Container, AgentScope::Compose, 'shop', '0.1.0');
    }

    public static function agent(?EnrollmentToken $token = null, string $id = self::AGENT): Agent
    {
        $token ??= self::single();
        $agent = Agent::enroll(AgentId::fromString($id), $token, self::host(), AgentPublicKey::fromText(self::KEY), self::runtime(), self::now());
        $agent->releaseEvents();

        return $agent;
    }

    public static function approved(): Agent
    {
        $agent = self::agent();
        $agent->approve(Uuid::fromString(self::ADMIN), self::now());
        $agent->releaseEvents();

        return $agent;
    }
}
