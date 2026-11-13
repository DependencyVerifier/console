<?php

declare(strict_types=1);

namespace Fleet\Application\Release;

use Fleet\Domain\Model\InvalidAgentRelease;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * Verifies a minisign signature (the format of the project's releases): `Ed` signs the file itself, `ED` its
 * BLAKE2b-512 hash; the global signature then covers the signature and the trusted comment, which is returned.
 */
final class Minisign
{
    /**
     * @param string $publicKey the key as `minisign -G` prints it (`RWQ…`), with or without its comment line
     *
     * @throws InvalidAgentRelease
     */
    public static function verify(string $publicKey, string $data, string $signatureFile): string
    {
        $key = self::decode(self::lastLine($publicKey), 42);
        if ('Ed' !== b($key)->slice(0, 2)->toString()) {
            throw new InvalidAgentRelease('Not a minisign Ed25519 public key.');
        }

        $lines = u($signatureFile)->trim()->split("\n");
        if (4 !== \count($lines) || !$lines[2]->startsWith('trusted comment: ')) {
            throw new InvalidAgentRelease('Not a minisign signature.');
        }

        $signature = self::decode($lines[1]->trim()->toString(), 74);
        $algorithm = b($signature)->slice(0, 2)->toString();
        if (b($signature)->slice(2, 8)->toString() !== b($key)->slice(2, 8)->toString()) {
            throw new InvalidAgentRelease('Signed with another key.');
        }

        $message = match ($algorithm) {
            'Ed' => $data,
            'ED' => sodium_crypto_generichash($data, '', 64),
            default => throw new InvalidAgentRelease('Unknown minisign algorithm.'),
        };
        // Never empty: the lengths were checked when decoding.
        /** @var non-empty-string $raw */
        $raw = b($key)->slice(10)->toString();
        /** @var non-empty-string $sig */
        $sig = b($signature)->slice(10)->toString();
        if (!sodium_crypto_sign_verify_detached($sig, $message, $raw)) {
            throw new InvalidAgentRelease('Bad signature.');
        }

        $comment = $lines[2]->trimEnd()->after('trusted comment: ')->toString();
        $global = self::decode($lines[3]->trim()->toString(), 64);
        if (!sodium_crypto_sign_verify_detached($global, $sig.$comment, $raw)) {
            throw new InvalidAgentRelease('Bad trusted comment.');
        }

        return $comment;
    }

    private static function lastLine(string $text): string
    {
        $lines = u($text)->trim()->split("\n");

        return $lines[\count($lines) - 1]->trim()->toString();
    }

    /**
     * @return non-empty-string
     */
    private static function decode(string $base64, int $length): string
    {
        $raw = base64_decode($base64, true);
        if (false === $raw || '' === $raw || $length !== b($raw)->length()) {
            throw new InvalidAgentRelease('Malformed minisign data.');
        }

        return $raw;
    }
}
