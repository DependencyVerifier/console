<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Command\Release\Handler\ImportAgentReleaseHandler;
use Fleet\Application\Command\Release\ImportAgentRelease;
use Fleet\Application\Port\AgentReleaseStore;
use Fleet\Application\Release\AgentRelease;
use Fleet\Application\Release\Minisign;
use Fleet\Domain\Model\InvalidAgentRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first link of the install trust chain: a release is stored only if its checksums carry a valid minisign
 * signature of the project release key, naming its version, and its binaries match them. Fixtures signed by the real
 * minisign with a throwaway key.
 */
final class AgentReleaseTest extends TestCase
{
    private const string DIR = __DIR__.'/../../Fixtures/release/';
    private const string KEY = 'RWQO5gX8SkYbdJ6c+eL4RfME1z9zLA09w1jES07HOvPMzkkQcfnpqBdS';

    private static function file(string $name): string
    {
        return (string) file_get_contents(self::DIR.$name);
    }

    #[Test]
    public function it_should_verify_the_signatures_of_minisign(): void
    {
        self::assertSame('depverify-agent 0.1.0', Minisign::verify(self::file('test.pub'), self::file('SHA256SUMS'), self::file('SHA256SUMS.minisig')), 'Prehashed (ED), with the key file and its comment.');
        self::assertSame('depverify-agent 0.0.9', Minisign::verify(self::KEY, self::file('legacy.txt'), self::file('legacy.txt.minisig')), 'Legacy (Ed), with the bare key.');
    }

    /**
     * @return iterable<string, array{\Closure(): string, string}>
     */
    public static function forgeries(): iterable
    {
        $file = static fn (string $name): string => (string) file_get_contents(self::DIR.$name);
        $signature = $file('SHA256SUMS.minisig');
        yield 'altered data' => [static fn (): string => Minisign::verify(self::KEY, $file('SHA256SUMS').' ', $signature), 'Bad signature.'];
        yield 'altered comment' => [static fn (): string => Minisign::verify(self::KEY, $file('SHA256SUMS'), str_replace('0.1.0', '9.9.9', $signature)), 'Bad trusted comment.'];
        yield 'another key' => [static fn (): string => Minisign::verify('RWQAAAAAAAAAAAD/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $file('SHA256SUMS'), $signature), 'Signed with another key.'];
        yield 'another algorithm' => [static fn (): string => Minisign::verify(base64_encode('Xx'.str_repeat("\0", 40)), $file('SHA256SUMS'), $signature), 'Not a minisign Ed25519 public key.'];
        yield 'not a key' => [static fn (): string => Minisign::verify('nope', $file('SHA256SUMS'), $signature), 'Malformed minisign data.'];
        yield 'not a signature' => [static fn (): string => Minisign::verify(self::KEY, $file('SHA256SUMS'), "one\ntwo"), 'Not a minisign signature.'];
    }

    /**
     * @param \Closure(): string $verify
     */
    #[Test]
    #[DataProvider('forgeries')]
    public function it_should_refuse_a_forgery(\Closure $verify, string $message): void
    {
        $this->expectExceptionObject(new InvalidAgentRelease($message));
        $verify();
    }

    #[Test]
    public function it_should_store_a_verified_release_only(): void
    {
        $store = new class implements AgentReleaseStore {
            public ?AgentRelease $saved = null;
            /** @var array<string, string> */
            public array $binaries = [];

            public function save(AgentRelease $release, array $binaries): void
            {
                $this->saved = $release;
                $this->binaries = $binaries;
            }

            public function latest(): ?AgentRelease
            {
                return $this->saved;
            }

            public function path(string $version, string $name): ?string
            {
                return null;
            }
        };
        $binaries = ['depverify-agent-x86_64-linux' => self::file('depverify-agent-x86_64-linux'), 'depverify-agent-aarch64-linux' => self::file('depverify-agent-aarch64-linux'), 'other' => 'x'];

        self::import($store, $binaries);
        self::assertSame('0.1.0', $store->saved?->version);
        self::assertSame(['x86_64', 'aarch64'], array_keys($store->saved->binaries));
        self::assertSame(['name' => 'depverify-agent-x86_64-linux', 'sha256' => hash('sha256', $binaries['depverify-agent-x86_64-linux']), 'size' => 13], $store->saved->binaries['x86_64']);
        self::assertSame(['depverify-agent-x86_64-linux', 'depverify-agent-aarch64-linux', 'SHA256SUMS', 'SHA256SUMS.minisig'], array_keys($store->binaries), 'The signed sums too: the agents verify an upgrade themselves.');
        self::assertSame([self::file('SHA256SUMS'), self::file('SHA256SUMS.minisig')], [$store->binaries['SHA256SUMS'], $store->binaries['SHA256SUMS.minisig']]);

        self::import($store, ['depverify-agent-aarch64-linux' => $binaries['depverify-agent-aarch64-linux']]);
        self::assertSame(['aarch64'], array_keys($store->saved->binaries ?? []), 'One architecture is enough.');

        $store->saved = null;
        foreach ([
            [['depverify-agent-x86_64-linux' => "tampered\n"], self::KEY, 'depverify-agent-x86_64-linux does not match its signed checksum.'],
            [['other' => 'x'], self::KEY, 'No binary of a supported architecture.'],
            [$binaries, '  ', 'AGENT_RELEASE_PUBKEY is not set: no release can be trusted.'],
        ] as [$given, $key, $message]) {
            try {
                self::import($store, $given, $key);
                self::fail($message);
            } catch (InvalidAgentRelease $refused) {
                self::assertSame($message, $refused->getMessage());
                self::assertSame('invalid_agent_release', $refused->ruleKey());
            }
        }
        self::assertNull($store->saved);

        $this->expectExceptionObject(new InvalidAgentRelease('The signed comment names no version.'));
        new ImportAgentReleaseHandler($store, self::KEY)(new ImportAgentRelease(self::file('noversion.txt'), self::file('noversion.txt.minisig'), $binaries));
    }

    /**
     * @param array<string, string> $binaries
     */
    private static function import(AgentReleaseStore $store, array $binaries, string $key = self::KEY): void
    {
        new ImportAgentReleaseHandler($store, $key)(new ImportAgentRelease(self::file('SHA256SUMS'), self::file('SHA256SUMS.minisig'), $binaries));
    }

    #[Test]
    public function it_should_name_the_binaries_and_audit_the_import(): void
    {
        self::assertSame('depverify-agent-aarch64-linux', AgentRelease::fileOf('aarch64'));
        $command = new ImportAgentRelease('', '', []);
        self::assertSame(['fleet.import_agent_release', '{}'], [$command->auditAction()->name, $command->auditContext()->toJson()]);
    }
}
