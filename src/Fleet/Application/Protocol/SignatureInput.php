<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

use function Symfony\Component\String\u;

/**
 * The fixed signature profile `depverify-agent-v1` (protocol §4.2): one label `sig1`, the covered components and the
 * parameters in this exact order. Any deviation is `invalid_signature`.
 */
final readonly class SignatureInput
{
    private const string PROFILE = '#^sig1=(\("@method" "@target-uri"(?<digest> "content-digest")? "date"\);created=(?<created>\d{1,12});expires=(?<expires>\d{1,12});keyid="(?<keyid>SHA256:[A-Za-z0-9+/]{43})";alg="ed25519";nonce="(?<nonce>[A-Za-z0-9_-]{22,128})";tag="depverify-agent-v1")$#';
    private const string SIGNATURE = '#^sig1=:(?<value>[A-Za-z0-9+/]{86}==):$#';

    private function __construct(
        public string $parameters,
        public bool $coversDigest,
        public int $created,
        public int $expires,
        public string $keyId,
        public string $nonce,
        public string $signature,
    ) {
    }

    public static function of(SignedRequest $request): self
    {
        $input = u($request->header('Signature-Input') ?? '')->match(self::PROFILE);
        $signature = u($request->header('Signature') ?? '')->match(self::SIGNATURE);
        [$parameters, $created, $expires, $keyId, $nonce, $value] = [$input[1] ?? null, $input['created'] ?? null, $input['expires'] ?? null, $input['keyid'] ?? null, $input['nonce'] ?? null, $signature['value'] ?? null];
        if (!\is_string($parameters) || !\is_string($created) || !\is_string($expires) || !\is_string($keyId) || !\is_string($nonce) || !\is_string($value)) {
            throw new ProtocolRefusal(Problem::InvalidSignature, 'The signature does not follow the depverify-agent-v1 profile.');
        }

        return new self($parameters, '' !== ($input['digest'] ?? ''), (int) $created, (int) $expires, $keyId, $nonce, (string) base64_decode($value, true));
    }

    /**
     * The signature base of RFC 9421 §2.5 for this profile: one line per component, no trailing newline.
     */
    public function base(SignedRequest $request): string
    {
        $lines = ['"@method": '.$request->method, '"@target-uri": '.$request->targetUri];
        if ($this->coversDigest) {
            $lines[] = '"content-digest": '.$request->header('Content-Digest');
        }

        $lines[] = '"date": '.$request->header('Date');
        $lines[] = '"@signature-params": '.$this->parameters;

        return u("\n")->join($lines)->toString();
    }
}
