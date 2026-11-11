<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

/**
 * The problem codes of protocol v1 (§9): `urn:depverify:problem:<code>`, with their HTTP status.
 */
enum Problem: string
{
    case InvalidPayload = 'invalid_payload';
    case UnsupportedProtocolVersion = 'unsupported_protocol_version';
    case InvalidSignature = 'invalid_signature';
    case InvalidDigest = 'invalid_digest';
    case ReplayedRequest = 'replayed_request';
    case ClockSkew = 'clock_skew';
    case UnknownAgent = 'unknown_agent';
    case AgentRevoked = 'agent_revoked';
    case TokenInvalid = 'token_invalid';
    case TokenExpired = 'token_expired';
    case AgentPending = 'agent_pending';
    case NotFound = 'not_found';
    case PayloadTooLarge = 'payload_too_large';
    case QuotaExceeded = 'quota_exceeded';
    case RateLimited = 'rate_limited';

    public function status(): int
    {
        return match ($this) {
            self::InvalidPayload, self::UnsupportedProtocolVersion => 400,
            self::InvalidSignature, self::InvalidDigest, self::ReplayedRequest, self::ClockSkew, self::UnknownAgent,
            self::AgentRevoked, self::TokenInvalid, self::TokenExpired => 401,
            self::AgentPending => 403,
            self::NotFound => 404,
            self::QuotaExceeded => 409,
            self::PayloadTooLarge => 413,
            self::RateLimited => 429,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::InvalidPayload => 'The request body does not follow the protocol',
            self::UnsupportedProtocolVersion => 'Unsupported protocol version',
            self::InvalidSignature => 'Invalid request signature',
            self::InvalidDigest => 'Content-Digest does not match the request content',
            self::ReplayedRequest => 'Request nonce already used',
            self::ClockSkew => 'Request signature is outside the accepted time window',
            self::UnknownAgent => 'Unknown agent key',
            self::AgentRevoked => 'Agent revoked',
            self::TokenInvalid => 'Unknown, used up or revoked enrollment token',
            self::TokenExpired => 'Enrollment token expired',
            self::AgentPending => 'Agent waiting for approval',
            self::NotFound => 'Not found',
            self::PayloadTooLarge => 'Request body too large',
            self::QuotaExceeded => 'Team agent quota reached',
            self::RateLimited => 'Too many requests',
        };
    }

    public function type(): string
    {
        return 'urn:depverify:problem:'.$this->value;
    }

    /**
     * The problem of a business refusal (its rule key), invalid_payload for any other rule.
     */
    public static function ofRule(string $ruleKey): self
    {
        return 'quota' === $ruleKey ? self::QuotaExceeded : (self::tryFrom($ruleKey) ?? self::InvalidPayload);
    }
}
