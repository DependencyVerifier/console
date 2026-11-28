<?php

declare(strict_types=1);

namespace Instance\Infrastructure\Release;

use Instance\Application\Port\LatestConsoleReleaseSource;
use Instance\Domain\Model\PublishedRelease;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The only implementation of the port: at most one upstream call a day per instance (an hour after a failure), the
 * stampede protection of CacheInterface::get keeping it to one call when several super admins arrive together.
 * The cache holds the normalized version string only (validated before storing), never a verdict nor a Domain
 * object: after an upgrade the banner disappears at once.
 */
final readonly class CachedConsoleReleaseSource implements LatestConsoleReleaseSource
{
    public const string CACHE_KEY = 'instance.console_release.v1';
    public const int SUCCESS_TTL = 86400;
    public const int FAILURE_TTL = 3600;

    public function __construct(
        private GitHubReleaseFeed $gitHubReleaseFeed,
        private ConsoleReleaseTranslator $consoleReleaseTranslator,
        #[Target('instance.cache')]
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function latest(): ?PublishedRelease
    {
        $version = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?string {
            try {
                $release = $this->consoleReleaseTranslator->fromTag($this->gitHubReleaseFeed->fetchTag());
            } catch (ConsoleReleaseUnavailable $e) {
                $item->expiresAfter(self::FAILURE_TTL);
                $this->logger->warning('The latest console release is unavailable.', ['exception_class' => ($e->getPrevious() ?? $e)::class, 'reason' => $e->getMessage()]);

                return null;
            }

            $item->expiresAfter(self::SUCCESS_TTL);

            return $release->version->toString();
        });

        return \is_string($version) ? $this->consoleReleaseTranslator->fromTag($version) : null;
    }
}
