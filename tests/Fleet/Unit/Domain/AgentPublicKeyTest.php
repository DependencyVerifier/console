<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\InvalidPublicKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fleet\Support\ProtocolVectors;

use function Symfony\Component\String\b;

final class AgentPublicKeyTest extends TestCase
{
    #[Test]
    public function it_should_read_the_text_form_and_give_the_protocol_fingerprint(): void
    {
        $vector = ProtocolVectors::request();
        $key = AgentPublicKey::fromText(ProtocolVectors::string($vector, 'agent_public_key'));

        self::assertSame($vector['agent_public_key'], $key->text());
        self::assertSame($vector['keyid'], $key->fingerprint());
        self::assertSame(32, b($key->raw)->length());
        self::assertEquals($key, AgentPublicKey::fromRaw($key->raw));
        $order = ProtocolVectors::order();
        self::assertSame(ProtocolVectors::string($order, 'signed_order.signature.key_id'), AgentPublicKey::fromText(ProtocolVectors::string($order, 'console_public_key'))->fingerprint());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'no prefix' => ['11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo='];
        yield 'other algorithm' => ['rsa:11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo='];
        yield 'too short' => ['ed25519:11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHUR='];
        yield 'trailing data' => ['ed25519:11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo= '];
        yield 'leading data' => [' ed25519:11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo='];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function it_should_refuse_a_malformed_key(string $text): void
    {
        try {
            AgentPublicKey::fromText($text);
            self::fail('A malformed key must be refused.');
        } catch (InvalidPublicKey $refused) {
            self::assertSame('invalid_public_key', $refused->ruleKey());
            self::assertSame('invalid_public_key', $refused->getMessage());
        }
    }

    #[Test]
    public function it_should_refuse_raw_bytes_of_the_wrong_length(): void
    {
        $this->expectException(InvalidPublicKey::class);
        AgentPublicKey::fromRaw(b("\0")->repeat(31)->toString());
    }
}
