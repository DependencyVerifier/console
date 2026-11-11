<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

use Fleet\Application\Port\NonceStore;
use Fleet\Application\Port\SignatureVerifier;
use Fleet\Domain\Model\AgentPublicKey;
use Psr\Clock\ClockInterface;
use Shared\Application\Protocol\AgentPayload;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * Verification of a signed agent request, in the order of protocol §4.3, stopping at the first failure:
 * profile, clock (±300 s), Content-Digest, key, Ed25519 signature, nonce. The agent state (step 7) is the caller's.
 */
final readonly class RequestVerifier
{
    public const int CLOCK_WINDOW = 300;
    public const int MAX_SNAPSHOT_BYTES = 20971520;

    public function __construct(private SignatureVerifier $signatures, private NonceStore $nonces, private ClockInterface $clock)
    {
    }

    /**
     * @param \Closure(string): ?AgentPublicKey $keyOf the public key of a keyid (null: unknown_agent)
     */
    public function verify(SignedRequest $request, \Closure $keyOf): SignatureInput
    {
        $input = SignatureInput::of($request);
        $hasBody = '' !== $request->body;
        if ($input->coversDigest !== $hasBody || $hasBody !== (null !== $request->header('Content-Digest')) || null === $request->header('Date')) {
            throw new ProtocolRefusal(Problem::InvalidSignature, 'Content-Digest is covered when, and only when, the request has a body; Date is always covered.');
        }

        $now = $this->clock->now()->getTimestamp();
        if (abs($input->created - $now) > self::CLOCK_WINDOW || $input->expires > $input->created + self::CLOCK_WINDOW || $input->expires < $now) {
            throw new ProtocolRefusal(Problem::ClockSkew, \sprintf('created is %d s away from the server clock; the window is %d s.', $input->created - $now, self::CLOCK_WINDOW));
        }

        if ($hasBody && $request->header('Content-Digest') !== self::digestOf($request->body)) {
            throw new ProtocolRefusal(Problem::InvalidDigest);
        }

        $key = $keyOf($input->keyId);
        if (null === $key || $key->fingerprint() !== $input->keyId) {
            throw new ProtocolRefusal(Problem::UnknownAgent);
        }

        if (!$this->signatures->verify($input->signature, $input->base($request), $key)) {
            throw new ProtocolRefusal(Problem::InvalidSignature);
        }

        if (!$this->nonces->remember($input->keyId, $input->nonce, $this->clock->now())) {
            throw new ProtocolRefusal(Problem::ReplayedRequest);
        }

        return $input;
    }

    /**
     * RFC 9530, the only algorithm of the profile.
     */
    public static function digestOf(string $body): string
    {
        return 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
    }

    /**
     * 1 MiB of JSON, 20 MiB for a compressed snapshot upload (protocol §3, §8.3).
     */
    public static function maxBytesOf(string $method, string $path): int
    {
        return 'PUT' === $method && [] !== u($path)->match('#^/agent/v1/inventories/[^/]+/snapshots/[^/]+$#') ? self::MAX_SNAPSHOT_BYTES : AgentPayload::MAX_BYTES;
    }

    public static function isBodyTooLarge(string $body, int $maxBytes = AgentPayload::MAX_BYTES): bool
    {
        return b($body)->length() > $maxBytes;
    }
}
