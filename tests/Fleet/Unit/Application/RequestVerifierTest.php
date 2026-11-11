<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Protocol\SignatureInput;
use Fleet\Application\Protocol\SignedRequest;
use Fleet\Domain\Model\AgentPublicKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\FleetWorld;
use Tests\Fleet\Support\ProtocolVectors;

use function Symfony\Component\String\u;

/**
 * Protocol §4.3, in order, stopping at the first failure.
 */
final class RequestVerifierTest extends TestCase
{
    private const string URI = 'https://console.example.com/agent/v1/poll';

    #[Test]
    public function it_should_rebuild_the_signature_base_of_the_vector(): void
    {
        $vector = ProtocolVectors::request();
        $headers = ProtocolVectors::map($vector, 'request.headers');
        /** @var array<string, string> $headers */
        $request = new SignedRequest(ProtocolVectors::string($vector, 'request.method'), ProtocolVectors::string($vector, 'request.target_uri'), $headers, ProtocolVectors::string($vector, 'request.body'));
        $input = SignatureInput::of($request);

        self::assertSame(ProtocolVectors::string($vector, 'signature_base'), $input->base($request));
        self::assertSame([1794211200, 1794211500, ProtocolVectors::string($vector, 'keyid'), 'aw89HJouS3-MXR4vOktcbQ', true, 64], [$input->created, $input->expires, $input->keyId, $input->nonce, $input->coversDigest, \strlen($input->signature)]);
        self::assertSame($headers['Content-Type'], $request->header('content-type'), 'Header names are case-insensitive.');
    }

    #[Test]
    public function it_should_accept_a_well_signed_request_once(): void
    {
        $world = new FleetWorld();
        $agent = AgentClient::generate();
        $request = $agent->sign(self::URI, ['protocol_version' => 1], $world->now(), ['nonce' => 'aw89HJouS3-MXR4vOktcbQ']);

        $input = $world->verifier()->verify($request, $this->keyOf($agent));
        self::assertSame($agent->key->fingerprint(), $input->keyId);
        self::assertEquals([$agent->key->fingerprint().' aw89HJouS3-MXR4vOktcbQ' => $world->clock->now()], $world->nonces->seen);
        $this->assertRefused(Problem::ReplayedRequest, static fn () => $world->verifier()->verify($request, static fn (string $k): AgentPublicKey => $agent->key));

        $bodyless = $agent->signBodyless(self::URI, $world->now());
        self::assertFalse($world->verifier()->verify($bodyless, $this->keyOf($agent))->coversDigest);
    }

    #[Test]
    public function it_should_accept_the_edges_of_the_clock_window(): void
    {
        $world = new FleetWorld();
        $agent = AgentClient::generate();
        foreach ([300, -299] as $offset) {
            self::assertSame($world->now() + $offset, $world->verifier()->verify($agent->sign(self::URI, ['a' => 1], $world->now(), ['created' => $offset]), $this->keyOf($agent))->created);
        }

        self::assertSame($world->now() - 300, $world->verifier()->verify($agent->sign(self::URI, ['a' => 1], $world->now(), ['created' => -300, 'expires' => 300]), $this->keyOf($agent))->created, 'Expires now: still valid.');
    }

    /**
     * @return iterable<string, array{Problem, \Closure(AgentClient, int): SignedRequest}>
     */
    public static function refusals(): iterable
    {
        $sign = self::signing(...);
        $without = static fn (string $header): \Closure => static function (AgentClient $agent, int $now) use ($header): SignedRequest {
            $request = $agent->sign(self::URI, ['protocol_version' => 1], $now);

            return new SignedRequest('POST', self::URI, array_diff_key($request->headers, [u($header)->lower()->toString() => true]), $request->body);
        };

        yield 'not signed' => [Problem::InvalidSignature, $sign(['unsigned' => true])];
        yield 'no signature value' => [Problem::InvalidSignature, $without('Signature')];
        yield 'no date' => [Problem::InvalidSignature, $without('Date')];
        yield 'body without digest header' => [Problem::InvalidSignature, $without('Content-Digest')];
        yield 'digest covered, no body' => [Problem::InvalidSignature, $sign(['body' => ''])];
        yield 'body, digest not covered' => [Problem::InvalidSignature, static function (AgentClient $agent, int $now): SignedRequest {
            $request = $agent->signBodyless(self::URI, $now);

            return new SignedRequest('POST', self::URI, $request->headers + ['Content-Digest' => 'sha-256=:x:'], '{}');
        }];
        yield 'short nonce' => [Problem::InvalidSignature, $sign(['nonce' => 'abc'])];
        yield 'created ahead' => [Problem::ClockSkew, $sign(['created' => 301])];
        yield 'created behind' => [Problem::ClockSkew, $sign(['created' => -301])];
        yield 'too long' => [Problem::ClockSkew, $sign(['expires' => 301])];
        yield 'expired' => [Problem::ClockSkew, $sign(['created' => -200, 'expires' => 199])];
        yield 'clock before digest' => [Problem::ClockSkew, $sign(['created' => 400, 'digest' => 'sha-256=:x:'])];
        yield 'wrong digest' => [Problem::InvalidDigest, $sign(['digest' => 'sha-256=:x:'])];
        yield 'body changed' => [Problem::InvalidDigest, $sign(['body' => '{"protocol_version":2}'])];
        yield 'digest before key' => [Problem::InvalidDigest, $sign(['digest' => 'sha-256=:x:', 'keyid' => 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'])];
        yield 'other keyid' => [Problem::UnknownAgent, $sign(['keyid' => 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'])];
        yield 'covered header changed' => [Problem::InvalidSignature, static function (AgentClient $agent, int $now): SignedRequest {
            $request = $agent->sign(self::URI, ['protocol_version' => 1], $now);

            return new SignedRequest('POST', self::URI, ['date' => gmdate('D, d M Y H:i:s \G\M\T', $now + 1)] + $request->headers, $request->body);
        }];
        yield 'other target' => [Problem::InvalidSignature, static function (AgentClient $agent, int $now): SignedRequest {
            $request = $agent->sign(self::URI, ['protocol_version' => 1], $now);

            return new SignedRequest('POST', 'https://evil.example.com/agent/v1/poll', $request->headers, $request->body);
        }];
    }

    /**
     * @param array{created?: int, expires?: int, nonce?: string, keyid?: string, digest?: string, body?: string, unsigned?: bool} $tamper
     *
     * @return \Closure(AgentClient, int): SignedRequest
     */
    private static function signing(array $tamper): \Closure
    {
        return static fn (AgentClient $agent, int $now): SignedRequest => $agent->sign(self::URI, ['protocol_version' => 1], $now, $tamper);
    }

    /**
     * @param \Closure(AgentClient, int): SignedRequest $build
     */
    #[Test]
    #[DataProvider('refusals')]
    public function it_should_refuse_in_the_order_of_the_protocol(Problem $problem, \Closure $build): void
    {
        $world = new FleetWorld();
        $agent = AgentClient::generate();
        $this->assertRefused($problem, fn () => $world->verifier()->verify($build($agent, $world->now()), $this->keyOf($agent)));
        self::assertSame([], $world->nonces->seen, 'A refused request never burns its nonce.');
    }

    #[Test]
    public function it_should_refuse_an_unknown_key_before_checking_the_signature(): void
    {
        $world = new FleetWorld();
        $agent = AgentClient::generate();
        $this->assertRefused(Problem::UnknownAgent, static fn () => $world->verifier()->verify($agent->sign(self::URI, ['a' => 1], $world->now(), ['unsigned' => false]), static fn (string $k): ?AgentPublicKey => null));
        $this->assertRefused(Problem::UnknownAgent, static fn () => $world->verifier()->verify($agent->sign(self::URI, ['a' => 1], $world->now()), static fn (string $k): AgentPublicKey => AgentClient::generate()->key), 'A key whose fingerprint is not the keyid.');
    }

    /**
     * @return \Closure(string): ?AgentPublicKey
     */
    private function keyOf(AgentClient $agent): \Closure
    {
        return static fn (string $keyId): ?AgentPublicKey => $keyId === $agent->key->fingerprint() ? $agent->key : null;
    }

    /**
     * @param \Closure(): mixed $gesture
     */
    private function assertRefused(Problem $problem, \Closure $gesture, string $message = ''): void
    {
        try {
            $gesture();
            self::fail('Expected '.$problem->value.'. '.$message);
        } catch (ProtocolRefusal $refusal) {
            self::assertSame($problem, $refusal->problem, $message);
            self::assertSame($problem->value, $refusal->ruleKey());
        }
    }
}
