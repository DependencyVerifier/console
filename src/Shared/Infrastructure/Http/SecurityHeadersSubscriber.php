<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\String\u;

/**
 * Strict CSP with a per-request nonce (never 'unsafe-inline', neither for scripts nor for styles), no framing,
 * no referrer (link tokens never leak), no MIME sniffing, HSTS outside dev. In dev only, the Vite dev server origin
 * is allowed for scripts, styles, HMR and assets.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
final readonly class SecurityHeadersSubscriber
{
    public function __construct(
        private CspNonce $nonce,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
        #[Autowire(env: 'default::VITE_DEV_SERVER_ORIGIN')]
        private ?string $viteDevOrigin = null,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', $this->policy());
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        if ('dev' !== $this->environment) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    private function policy(): string
    {
        $nonce = \sprintf("'nonce-%s'", $this->nonce->value());
        $viteOrigin = $this->viteDevOrigin ?? 'http://localhost:5173';
        $dev = 'dev' === $this->environment ? ' '.$viteOrigin : '';
        // Vite's hot reload socket is on the same port as its origin (9173 with compose.local.yaml).
        $devSocket = 'dev' === $this->environment ? ' '.u($viteOrigin)->replaceMatches('/^http/', 'ws')->toString() : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' {$nonce}{$dev}",
            "style-src 'self' {$nonce}{$dev}",
            "img-src 'self' data:{$dev}",
            "font-src 'self'{$dev}",
            "connect-src 'self'{$dev}{$devSocket}",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
