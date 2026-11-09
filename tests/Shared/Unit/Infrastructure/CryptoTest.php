<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Infrastructure\Crypto\AesGcmSecretBox;
use Shared\Infrastructure\Crypto\HmacTokenHasher;
use Shared\Infrastructure\Crypto\KeyRing;
use Shared\Infrastructure\Crypto\RandomTokenGenerator;
use Shared\Infrastructure\Security\SecretsBootCheck;
use Shared\Infrastructure\Text\SluggerSlugGenerator;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function Symfony\Component\String\u;

final class CryptoTest extends TestCase
{
    #[Test]
    public function it_should_encrypt_bound_to_its_additional_data_and_rotate_keys(): void
    {
        $old = 'old='.base64_encode(random_bytes(32));
        $box = new AesGcmSecretBox($old);
        $ciphertext = $box->encrypt('JBSWY3DPEHPK3PXP', 'totp:user-1');

        self::assertStringStartsWith('v1:old:', $ciphertext);
        self::assertSame('JBSWY3DPEHPK3PXP', $box->decrypt($ciphertext, 'totp:user-1'));
        self::assertNotSame($ciphertext, $box->encrypt('JBSWY3DPEHPK3PXP', 'totp:user-1'), 'Random nonce.');

        $rotated = new AesGcmSecretBox('new='.base64_encode(random_bytes(32)).','.$old);
        self::assertSame('JBSWY3DPEHPK3PXP', $rotated->decrypt($ciphertext, 'totp:user-1'));
        self::assertStringStartsWith('v1:new:', $rotated->encrypt('x', 'a'));

        $this->expectException(\RuntimeException::class);
        $box->decrypt($ciphertext, 'totp:user-2');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidRings(): iterable
    {
        yield 'empty' => [''];
        yield 'no kid' => [base64_encode(random_bytes(32))];
        yield 'short key' => ['k1='.base64_encode(random_bytes(16))];
        yield 'not base64' => ['k1=@@@'];
        yield 'bad kid' => ['K-1='.base64_encode(random_bytes(32))];
    }

    #[Test]
    #[DataProvider('provideInvalidRings')]
    public function it_should_fail_closed_on_a_bad_key_ring(string $ring): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KeyRing::fromString($ring, 'APP_ENCRYPTION_KEYS');
    }

    #[Test]
    public function it_should_hash_with_a_rotatable_pepper(): void
    {
        $old = 'p1='.base64_encode(random_bytes(32));
        $hash = new HmacTokenHasher($old)->hash('123456');

        self::assertMatchesRegularExpression('/^p1:[0-9a-f]{64}$/', $hash);
        self::assertTrue(new HmacTokenHasher('p2='.base64_encode(random_bytes(32)).','.$old)->verify('123456', $hash));
        self::assertFalse(new HmacTokenHasher($old)->verify('654321', $hash));
        self::assertFalse(new HmacTokenHasher($old)->verify('123456', 'garbage'));
        self::assertFalse(new HmacTokenHasher('p2='.base64_encode(random_bytes(32)))->verify('123456', $hash));
    }

    #[Test]
    public function it_should_generate_one_format_per_use(): void
    {
        $generator = new RandomTokenGenerator();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $generator->urlToken());
        self::assertMatchesRegularExpression('/^\d{6}$/', $generator->numericCode());
        self::assertMatchesRegularExpression('/^[a-hj-km-np-z2-9]{5}-[a-hj-km-np-z2-9]{5}$/', $generator->backupCode());
        self::assertNotSame($generator->urlToken(), $generator->urlToken());
    }

    #[Test]
    public function it_should_suffix_taken_slugs(): void
    {
        $slugs = new SluggerSlugGenerator(new AsciiSlugger());
        $taken = ['acme-corp', 'acme-corp-2'];

        self::assertSame('acme-corp-3', $slugs->unique('Acme Corp', static fn (string $slug): bool => \in_array($slug, $taken, true)));
        self::assertSame('item', $slugs->unique('!!!', static fn (): bool => false));
    }

    #[Test]
    public function it_should_refuse_a_short_app_secret(): void
    {
        $ring = 'k1='.base64_encode(random_bytes(32));
        SecretsBootCheck::validate(u('x')->repeat(32)->toString(), $ring, $ring);

        $this->expectException(\LogicException::class);
        SecretsBootCheck::validate('short', $ring, $ring);
    }
}
