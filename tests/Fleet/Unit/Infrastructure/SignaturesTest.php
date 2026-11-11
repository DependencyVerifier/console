<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Infrastructure;

use Fleet\Application\Protocol\CanonicalJson;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Infrastructure\Crypto\SodiumConsoleSigner;
use Fleet\Infrastructure\Crypto\SodiumSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fleet\Support\ProtocolVectors;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * The protocol test vectors, reproduced byte for byte: the agent's request signature verifies, the console signs the
 * canonical order to the very same signature.
 */
final class SignaturesTest extends TestCase
{
    #[Test]
    public function it_should_verify_the_signed_request_of_the_vector(): void
    {
        $vector = ProtocolVectors::request();
        $signature = base64_decode(u(ProtocolVectors::string($vector, 'request.headers.Signature'))->after('sig1=:')->beforeLast(':')->toString(), true);
        self::assertIsString($signature);
        $key = AgentPublicKey::fromText(ProtocolVectors::string($vector, 'agent_public_key'));
        $base = ProtocolVectors::string($vector, 'signature_base');
        $verifier = new SodiumSignatureVerifier();

        self::assertTrue($verifier->verify($signature, $base, $key));
        self::assertFalse($verifier->verify($signature, $base.' ', $key), 'Any other base fails.');
        self::assertFalse($verifier->verify(b($signature)->slice(0, 63)->toString(), $base, $key), 'A truncated signature fails, never throws.');
        self::assertFalse($verifier->verify('', $base, $key), 'An empty signature fails, never throws.');
    }

    #[Test]
    public function it_should_sign_the_canonical_order_of_the_vector(): void
    {
        $vector = ProtocolVectors::order();
        $signer = new SodiumConsoleSigner(base64_encode((string) hex2bin(ProtocolVectors::string($vector, 'console_private_key_seed_hex'))));

        $canonical = CanonicalJson::encode(ProtocolVectors::map($vector, 'signed_order.order'));
        self::assertSame($vector['canonical_order'], $canonical);
        self::assertSame($vector['console_public_key'], $signer->publicKey()->text());
        self::assertSame(
            ProtocolVectors::string($vector, 'signed_order.signature.value'),
            u(base64_encode($signer->sign($canonical)))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString(),
        );
    }

    #[Test]
    public function it_should_keep_slashes_and_unicode_and_refuse_floats(): void
    {
        self::assertSame('{"a":"/x","b":[{"c":1,"d":"é"}],"z":null}', CanonicalJson::encode(['z' => null, 'b' => [['d' => 'é', 'c' => 1]], 'a' => '/x']));
        self::assertSame('[]', CanonicalJson::encode([]));
        self::assertSame('{"0":"a","1":"b"}', CanonicalJson::encode([1 => 'b', 0 => 'a']), 'A map stays a map once sorted.');

        $this->expectException(\InvalidArgumentException::class);
        CanonicalJson::encode(['a' => 1.5]);
    }

    #[Test]
    public function it_should_refuse_a_malformed_console_seed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SodiumConsoleSigner('not base64 of 32 bytes');
    }
}
