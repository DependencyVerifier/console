<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Shared\Application\Crypto\TokenHasher;
use Shared\Infrastructure\RateLimiting\RateLimiterStorageFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

use function Symfony\Component\String\u;

/**
 * login_throttling: account + IP (5 / 15 min) and IP (50 / 15 min), keys hashed (HMAC) before the limiter is
 * created, so neither the id nor the state holds an email or an IP in clear. A successful login resets the account +
 * IP window only, never the IP one (otherwise an attacker owning one valid account could empty the IP counter).
 * Storage failures are fail-closed.
 */
final class LoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.login_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.login_account_ip')]
        private readonly RateLimiterFactoryInterface $accountIpLimiter,
        private readonly TokenHasher $hasher,
    ) {
    }

    public function consume(Request $request): RateLimit
    {
        try {
            return parent::consume($request);
        } catch (\Throwable $failure) {
            throw RateLimiterStorageFailure::translate($failure);
        }
    }

    public function peek(Request $request): RateLimit
    {
        try {
            return parent::peek($request);
        } catch (\Throwable $failure) {
            throw RateLimiterStorageFailure::translate($failure);
        }
    }

    public function reset(Request $request): void
    {
        try {
            $this->accountIpLimiter->create($this->accountKey($request))->reset();
        } catch (\Throwable $failure) {
            throw RateLimiterStorageFailure::translate($failure);
        }
    }

    protected function getLimiters(Request $request): array
    {
        return [
            $this->ipLimiter->create($this->hasher->hash('login-ip:'.$this->ip($request))),
            $this->accountIpLimiter->create($this->accountKey($request)),
        ];
    }

    private function accountKey(Request $request): string
    {
        $username = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        $username = u(\is_string($username) ? $username : '')->trim()->lower()->toString();

        return $this->hasher->hash('login-account:'.$username.'|'.$this->ip($request));
    }

    private function ip(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
