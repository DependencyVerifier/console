<?php

declare(strict_types=1);

namespace Tests\Instance\Unit\Infrastructure;

use Instance\Domain\Model\InvalidReleaseNotesUrl;
use Instance\Infrastructure\Release\CachedConsoleReleaseSource;
use Instance\Infrastructure\Release\ConsoleReleaseTranslator;
use Instance\Infrastructure\Release\ConsoleReleaseUnavailable;
use Instance\Infrastructure\Release\GitHubReleaseFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Instance\Support\ExpiryRecordingCache;

/**
 * The anticorruption layer in front of the GitHub Releases API: transport and types, the single translator, the
 * cache that validates before storing and never throws a transport error.
 */
final class ConsoleReleaseTest extends TestCase
{
    private const string URL = 'https://api.github.com/repos/DependencyVerifier/console/releases/latest';
    private const string NOTES = 'https://github.com/DependencyVerifier/console/releases/tag/';

    #[Test]
    public function it_should_ask_one_anonymous_get_and_read_the_tag(): void
    {
        $asked = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$asked): MockResponse {
            $asked = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'] ?? []];

            return new MockResponse('{"tag_name":"v1.2.0","draft":false,"prerelease":false}');
        });

        self::assertSame('v1.2.0', new GitHubReleaseFeed($http, self::URL)->fetchTag());
        self::assertSame('GET', $asked['method']);
        self::assertSame(self::URL, $asked['url'], 'No query string.');
        self::assertIsArray($asked['headers']);
        self::assertArrayNotHasKey('authorization', $asked['headers']);
        self::assertSame(['Accept: application/vnd.github+json'], $asked['headers']['accept'] ?? null);
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function provideUnavailableAnswers(): iterable
    {
        yield 'not found' => [new MockResponse('{}', ['http_code' => 404])];
        yield 'forbidden (rate limit)' => [new MockResponse('{}', ['http_code' => 403])];
        yield 'too many requests' => [new MockResponse('{}', ['http_code' => 429])];
        yield 'server error' => [new MockResponse('{}', ['http_code' => 502])];
        yield 'redirect' => [new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://evil.test/']])];
        yield 'timeout' => [new MockResponse('', ['error' => 'Idle timeout reached'])];
        yield 'malformed JSON' => [new MockResponse('{"tag_name":')];
        yield 'numeric tag' => [new MockResponse('{"tag_name":120,"draft":false,"prerelease":false}')];
        yield 'array tag' => [new MockResponse('{"tag_name":["v1.2.0"],"draft":false,"prerelease":false}')];
        yield 'no tag' => [new MockResponse('{"draft":false,"prerelease":false}')];
        yield 'draft not a boolean' => [new MockResponse('{"tag_name":"v1.2.0","draft":"false","prerelease":false}')];
        yield 'prerelease missing' => [new MockResponse('{"tag_name":"v1.2.0","draft":false}')];
        yield 'draft' => [new MockResponse('{"tag_name":"v1.2.0","draft":true,"prerelease":false}')];
        yield 'prerelease' => [new MockResponse('{"tag_name":"v1.2.0","draft":false,"prerelease":true}')];
    }

    #[Test]
    #[DataProvider('provideUnavailableAnswers')]
    public function it_should_say_when_no_final_release_can_be_read(MockResponse $answer): void
    {
        $this->expectException(ConsoleReleaseUnavailable::class);

        new GitHubReleaseFeed(new MockHttpClient($answer), self::URL)->fetchTag();
    }

    #[Test]
    public function it_should_never_ask_a_url_that_is_not_https(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => self::fail('No request.'));

        $this->expectException(ConsoleReleaseUnavailable::class);
        $this->expectExceptionMessageIsOrContains('Cannot ask a release URL that is not https.');

        new GitHubReleaseFeed($http, 'http://api.github.com/repos/DependencyVerifier/console/releases/latest')->fetchTag();
    }

    #[Test]
    public function it_should_build_the_notes_url_from_the_validated_version(): void
    {
        $release = new ConsoleReleaseTranslator(self::NOTES)->fromTag('v1.2.0');

        self::assertSame('1.2.0', $release->version->toString());
        self::assertSame(self::NOTES.'v1.2.0', $release->notesUrl->toString());
        self::assertSame(self::NOTES.'v1.2.0', new ConsoleReleaseTranslator(self::NOTES)->fromTag('1.2.0')->notesUrl->toString());
    }

    #[Test]
    public function it_should_refuse_a_tag_that_is_not_a_version(): void
    {
        $this->expectException(ConsoleReleaseUnavailable::class);
        $this->expectExceptionMessageIsOrContains('There is no semantic version in the latest release tag.');

        new ConsoleReleaseTranslator(self::NOTES)->fromTag('nightly');
    }

    #[Test]
    public function it_should_refuse_a_misconfigured_notes_base_url(): void
    {
        $this->expectException(InvalidReleaseNotesUrl::class);

        new ConsoleReleaseTranslator('http://github.com/')->fromTag('v1.2.0');
    }

    #[Test]
    public function it_should_ask_upstream_once_a_day_and_rebuild_the_same_release_from_the_cache(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"tag_name":"v1.2.0","draft":false,"prerelease":false,"html_url":"https://evil.test/"}');
        });
        $cache = new ExpiryRecordingCache();
        $source = $this->source($http, $cache);

        $first = $source->latest();
        $second = $source->latest();

        self::assertSame(1, $calls, 'The second read is served by the cache.');
        self::assertEqualsWithDelta(86400, $cache->expiresInSeconds(), 5.0, 'One day.');
        self::assertEquals($first, $second);
        self::assertSame(self::NOTES.'v1.2.0', $second?->notesUrl->toString(), 'html_url is never read.');
        self::assertSame('1.2.0', $cache->getItem(CachedConsoleReleaseSource::CACHE_KEY)->get(), 'Only the normalized version is stored.');
    }

    #[Test]
    public function it_should_cache_a_failure_for_an_hour_and_log_a_fixed_warning(): void
    {
        foreach (['{}' => 503, '{"tag_name":"nightly","draft":false,"prerelease":false}' => 200] as $body => $status) {
            $calls = 0;
            $http = new MockHttpClient(static function () use (&$calls, $body, $status): MockResponse {
                ++$calls;

                return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['X-Secret: body']]);
            });
            $logger = new class extends AbstractLogger {
                /** @var list<array{mixed, string, array<mixed>}> */
                public array $records = [];

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    $this->records[] = [$level, (string) $message, $context];
                }
            };
            $cache = new ExpiryRecordingCache();
            $source = new CachedConsoleReleaseSource(new GitHubReleaseFeed($http, self::URL), new ConsoleReleaseTranslator(self::NOTES), $cache, $logger);

            self::assertNull($source->latest(), (string) $status);
            self::assertNull($source->latest(), 'Served by the cache, without an exception.');
            self::assertSame(1, $calls, 'A failure is cached too.');
            self::assertEqualsWithDelta(3600, $cache->expiresInSeconds(), 5.0, 'One hour.');
            self::assertCount(1, $logger->records);
            self::assertSame('warning', $logger->records[0][0]);
            self::assertSame('The latest console release is unavailable.', $logger->records[0][1]);
            self::assertSame(['exception_class', 'reason'], array_keys($logger->records[0][2]));
        }
    }

    #[Test]
    public function it_should_never_swallow_anything_but_an_unavailable_upstream(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('A bug.'));

        $this->expectException(\LogicException::class);

        $this->source($http, new ArrayAdapter())->latest();
    }

    private function source(MockHttpClient $http, ArrayAdapter $cache): CachedConsoleReleaseSource
    {
        return new CachedConsoleReleaseSource(new GitHubReleaseFeed($http, self::URL), new ConsoleReleaseTranslator(self::NOTES), $cache, new NullLogger());
    }
}
