<?php

declare(strict_types=1);

namespace Instance\Infrastructure\Build;

use Instance\Application\Port\CurrentBuild;
use Instance\Domain\Model\BuildLabel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `APP_VERSION`, written into the image by the release workflow (`--build-arg`), `dev` everywhere else. It is never
 * declared in a Compose file: it would override the version of the image.
 */
final readonly class EnvCurrentBuild implements CurrentBuild
{
    public function __construct(
        #[Autowire(env: 'APP_VERSION')]
        private string $appVersion,
    ) {
    }

    public function label(): BuildLabel
    {
        return BuildLabel::fromRaw($this->appVersion);
    }
}
