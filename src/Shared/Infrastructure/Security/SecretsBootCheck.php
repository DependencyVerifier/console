<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Security;

use Shared\Infrastructure\Crypto\KeyRing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\String\b;

/**
 * Fail-closed secrets, checked before the first request is served (outside dev and test): APP_SECRET signs the
 * trusted-device cookie that skips the second factor for 30 days, so it is treated like a key (32 bytes at least);
 * APP_ENCRYPTION_KEYS and APP_HASH_PEPPER must be valid key rings. Rotating APP_SECRET invalidates every trusted
 * device, on purpose.
 */
final class SecretsBootCheck
{
    private bool $checked = false;

    public function __construct(
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        #[Autowire(env: 'default::APP_SECRET')] #[\SensitiveParameter]
        private readonly ?string $appSecret = null,
        #[Autowire(env: 'default::APP_ENCRYPTION_KEYS')] #[\SensitiveParameter]
        private readonly ?string $encryptionKeys = null,
        #[Autowire(env: 'default::APP_HASH_PEPPER')] #[\SensitiveParameter]
        private readonly ?string $hashPepper = null,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8192)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->checked || !$event->isMainRequest() || \in_array($this->environment, ['dev', 'test'], true)) {
            return;
        }

        self::validate((string) $this->appSecret, (string) $this->encryptionKeys, (string) $this->hashPepper);
        $this->checked = true;
    }

    public static function validate(#[\SensitiveParameter] string $appSecret, #[\SensitiveParameter] string $encryptionKeys, #[\SensitiveParameter] string $hashPepper): void
    {
        if (b($appSecret)->length() < 32) {
            throw new \LogicException('APP_SECRET must hold at least 32 bytes.');
        }

        KeyRing::fromString($encryptionKeys, 'APP_ENCRYPTION_KEYS');
        KeyRing::fromString($hashPepper, 'APP_HASH_PEPPER');
    }
}
