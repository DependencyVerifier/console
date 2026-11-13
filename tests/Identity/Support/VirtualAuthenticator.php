<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use function Symfony\Component\String\u;

/**
 * A WebAuthn authenticator in PHP for the tests (like Chrome's virtual authenticator): an ES256 key, `none`
 * attestation, user present and verified. It answers the options the console gives exactly as a browser would
 * (PublicKeyCredential.toJSON()), so the real ceremony (web-auth/webauthn-lib) checks them.
 */
final class VirtualAuthenticator
{
    public const string ORIGIN = 'https://console.depverify.localhost';

    private readonly \OpenSSLAsymmetricKey $key;
    public readonly string $credentialId;
    public int $signCount = 0;
    private string $userHandle = '';

    public function __construct(public string $origin = self::ORIGIN, public int $flags = 0x05)
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        $this->key = false === $key ? throw new \RuntimeException('No EC key.') : $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * The answer to creation options (JSON).
     */
    public function create(string $optionsJson): string
    {
        $options = self::json($optionsJson);
        $rp = self::map($options['rp'] ?? null);
        $user = self::map($options['user'] ?? null);
        $this->userHandle = self::decode(self::string($user['id'] ?? null));
        $clientData = $this->clientData('webauthn.create', self::string($options['challenge'] ?? null));
        $details = openssl_pkey_get_details($this->key);
        $ec = \is_array($details) && \is_array($details['ec'] ?? null) ? $details['ec'] : [];
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new Bytes(str_pad(self::string($ec['x'] ?? null), 32, "\0", \STR_PAD_LEFT)), -3 => new Bytes(str_pad(self::string($ec['y'] ?? null), 32, "\0", \STR_PAD_LEFT))]);
        $authData = hash('sha256', self::string($rp['id'] ?? null), true).pack('C', $this->flags | 0x40).pack('N', $this->signCount)
            .str_repeat("\0", 16).pack('n', \strlen($this->credentialId)).$this->credentialId.$cose;

        return self::encodeJson([
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'clientExtensionResults' => new \stdClass(),
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'attestationObject' => self::encode(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new Bytes($authData)])),
                'transports' => ['internal'],
            ],
        ]);
    }

    /**
     * The answer to request options (JSON), with the next counter.
     */
    public function get(string $optionsJson, ?int $signCount = null): string
    {
        $options = self::json($optionsJson);
        $this->signCount = $signCount ?? $this->signCount + 1;
        $clientData = $this->clientData('webauthn.get', self::string($options['challenge'] ?? null));
        $authData = hash('sha256', self::string($options['rpId'] ?? null), true).pack('C', $this->flags).pack('N', $this->signCount);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, \OPENSSL_ALGO_SHA256);

        return self::encodeJson([
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'clientExtensionResults' => new \stdClass(),
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'authenticatorData' => self::encode($authData),
                'signature' => self::encode(\is_string($signature) ? $signature : ''),
                'userHandle' => self::encode($this->userHandle),
            ],
        ]);
    }

    public function claiming(string $userId): self
    {
        $this->userHandle = $userId;

        return $this;
    }

    private function clientData(string $type, string $challenge): string
    {
        return self::encodeJson(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false]);
    }

    public static function encode(string $bytes): string
    {
        return u(base64_encode($bytes))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString();
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode(u($value)->replace('-', '+')->replace('_', '/')->toString(), true);

        return false === $decoded ? '' : $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $json): array
    {
        return self::map(json_decode($json, true, 16, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function encodeJson(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * The CBOR this needs: maps, text, byte strings, small integers (RFC 8949).
     */
    private static function cbor(mixed $value): string
    {
        return match (true) {
            $value instanceof Bytes => self::head(2, \strlen($value->bytes)).$value->bytes,
            \is_string($value) => self::head(3, \strlen($value)).$value,
            \is_int($value) && $value >= 0 => self::head(0, $value),
            \is_int($value) => self::head(1, -1 - $value),
            \is_array($value) => self::head(5, \count($value)).implode('', array_map(static fn (int|string $k, mixed $v): string => self::cbor($k).self::cbor($v), array_keys($value), $value)),
            default => throw new \InvalidArgumentException('Not encodable.'),
        };
    }

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => pack('C', $major << 5 | $length),
            $length < 256 => pack('C2', $major << 5 | 24, $length),
            default => pack('Cn', $major << 5 | 25, $length),
        };
    }
}
