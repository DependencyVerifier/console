<?php

declare(strict_types=1);

namespace Instance\Infrastructure\Release;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

/**
 * The transport of the anticorruption layer: one anonymous GET of the latest GitHub Release of the console, typed
 * and checked. It sends nothing about the instance (no query string, no Authorization, the global User-Agent).
 */
final readonly class GitHubReleaseFeed
{
    public function __construct(
        #[Target('console_release.client')]
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default:console_release_default_url:CONSOLE_RELEASE_URL)%')]
        private string $releaseUrl,
    ) {
    }

    /**
     * @throws ConsoleReleaseUnavailable
     */
    public function fetchTag(): string
    {
        if (!u($this->releaseUrl)->startsWith('https://')) {
            throw new ConsoleReleaseUnavailable('Cannot ask a release URL that is not https.');
        }

        try {
            $release = $this->httpClient->request('GET', $this->releaseUrl, ['headers' => ['Accept' => 'application/vnd.github+json']])->toArray();
        } catch (ExceptionInterface $e) {
            throw new ConsoleReleaseUnavailable('There is no readable latest release.', 0, $e);
        }

        $tag = $release['tag_name'] ?? null;
        $draft = $release['draft'] ?? null;
        $prerelease = $release['prerelease'] ?? null;
        if (!\is_string($tag) || !\is_bool($draft) || !\is_bool($prerelease)) {
            throw new ConsoleReleaseUnavailable('There is no well-typed latest release.');
        }

        if ($draft || $prerelease) {
            throw new ConsoleReleaseUnavailable('There is no final latest release.');
        }

        return $tag;
    }
}
