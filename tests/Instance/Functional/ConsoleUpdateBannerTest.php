<?php

declare(strict_types=1);

namespace Tests\Instance\Functional;

use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Shared\UI\Frame\FrameExceptionRenderer;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\IdentityWebTestCase;
use Tests\Vulnerability\Support\RecordedFeeds;

/**
 * The "new version available" banner of the super admin, the version in the footer of the signed-in pages, and the
 * silence of the frame whatever happens upstream. The recorded latest release is 1.1.2 (RecordedFeeds).
 */
final class ConsoleUpdateBannerTest extends IdentityWebTestCase
{
    private const string FRAME = '/admin/frames/console-update';

    protected function tearDown(): void
    {
        unset($_SERVER['APP_VERSION'], $_ENV['APP_VERSION']);
        parent::tearDown();
    }

    #[Test]
    public function it_should_announce_a_newer_release_to_the_super_admin(): void
    {
        $this->running('1.1.0');
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $page = $this->client->request('GET', '/admin/instance');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('turbo-frame#console-update[src="'.self::FRAME.'"][loading="lazy"]'));
        self::assertCount(0, $page->filter('turbo-frame#console-update [role="progressbar"], turbo-frame#console-update template'), 'Quiet: no skeleton, no error template.');
        self::assertSame('Version 1.1.0', $page->filter('footer [data-build-label]')->text());
        self::assertSame('1.1.0', $page->filter('[data-instance-version]')->text());
        self::assertNotCount(0, $page->filter('a[aria-current="page"][href="/admin/instance"]'), 'Administration › Instance in the sidebar.');

        $frame = $this->client->request('GET', self::FRAME, server: ['HTTP_TURBO_FRAME' => 'console-update']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $banner = $frame->filter('turbo-frame#console-update [data-controller="dismissible-update"][data-dismissible-update-version-value="1.1.2"] [role="alert"]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('Version 1.1.2 of the console is available', $banner->text());
        self::assertStringContainsString('You are running 1.1.0.', $banner->text());
        self::assertSame('https://github.com/DependencyVerifier/console/releases/tag/v1.1.2', $banner->filter('a')->eq(0)->attr('href'));
        self::assertSame('https://github.com/DependencyVerifier/console/blob/main/INSTALL.md#5-upgrading', $banner->filter('a')->eq(1)->attr('href'));
        self::assertSame('noopener noreferrer', $banner->filter('a')->eq(0)->attr('rel'));
        self::assertSame(['GET https://api.github.com/repos/DependencyVerifier/console/releases/latest'], $this->feeds()->requests);
    }

    #[Test]
    public function it_should_show_nothing_when_up_to_date_or_without_a_version(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        foreach (['1.1.2' => 1, '1.2.0' => 1, 'dev' => 0] as $running => $calls) {
            $this->running($running);
            $frame = $this->client->request('GET', self::FRAME, server: ['HTTP_TURBO_FRAME' => 'console-update']);

            self::assertResponseIsSuccessful();
            self::assertCount(1, $frame->filter('turbo-frame#console-update'), $running);
            self::assertCount(0, $frame->filter('[role="alert"]'), $running);
            self::assertCount($calls, $this->feeds()->requests, 'A build without a version never asks upstream.');
        }
    }

    #[Test]
    public function it_should_keep_the_frame_silent_when_upstream_fails(): void
    {
        $this->running('1.1.0');
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');
        $this->client->disableReboot();
        $this->feeds()->consoleReleaseStatus = 503;

        $frame = $this->client->request('GET', self::FRAME, server: ['HTTP_TURBO_FRAME' => 'console-update']);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $frame->filter('[role="alert"]'));
    }

    #[Test]
    public function it_should_render_a_failure_of_the_frame_empty_with_its_status_and_log_it(): void
    {
        $this->running('1.1.0');
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');
        $this->client->disableReboot();
        $this->feeds()->consoleReleaseFailure = new \LogicException('A bug, not an unavailable upstream.');

        $frame = $this->client->request('GET', self::FRAME, server: ['HTTP_TURBO_FRAME' => 'console-update']);

        self::assertResponseStatusCodeSame(500);
        self::assertCount(1, $frame->filter('turbo-frame#console-update'));
        self::assertCount(0, $frame->filter('[role="alert"]'), 'No red alert on the page.');
        self::assertSame('', trim($frame->filter('turbo-frame#console-update')->html()));

        $order = array_values(array_filter(array_map(
            static fn (mixed $listener): ?string => match (true) {
                \is_array($listener) && $listener[0] instanceof ErrorListener && 'logKernelException' === $listener[1] => 'log',
                \is_object($listener) && $listener instanceof FrameExceptionRenderer, \is_array($listener) && $listener[0] instanceof FrameExceptionRenderer => 'frame',
                default => null,
            },
            self::getContainer()->get('event_dispatcher')->getListeners(KernelEvents::EXCEPTION),
        )));
        self::assertSame(['log', 'frame'], $order, 'The error is logged before the frame response stops the event.');
    }

    #[Test]
    public function it_should_never_show_the_banner_nor_ask_upstream_for_anyone_else(): void
    {
        $this->running('1.1.0');
        $jane = Accounts::create(self::getContainer(), 'jane@example.com');
        $team = Seed::team(self::getContainer(), 'Platform', [[$jane, TeamRole::Admin]]);
        $this->logIn('jane@example.com');

        $page = $this->client->request('GET', '/teams/'.$team->id()->toString());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('turbo-frame#console-update'));
        self::assertSame('Version 1.1.0', $page->filter('footer [data-build-label]')->text(), 'The version is shown to every signed-in user.');

        $this->client->request('GET', self::FRAME, server: ['HTTP_TURBO_FRAME' => 'console-update']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->feeds()->requests);

        $this->client->request('GET', '/admin/instance');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function it_should_answer_only_inside_its_turbo_frame(): void
    {
        $this->running('1.1.0');
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        foreach (['no header' => [], 'empty header' => ['HTTP_TURBO_FRAME' => ' ']] as $case => $server) {
            $page = $this->client->request('GET', self::FRAME, server: $server);

            self::assertResponseStatusCodeSame(404, $case);
            self::assertCount(0, $page->filter('turbo-frame#console-update'), $case);
        }
        self::assertSame([], $this->feeds()->requests, 'Refused before the controller: nothing asked upstream.');
    }

    #[Test]
    public function it_should_still_refuse_the_frame_to_anyone_else_before_looking_at_the_header(): void
    {
        $jane = Accounts::create(self::getContainer(), 'jane@example.com');
        Seed::team(self::getContainer(), 'Platform', [[$jane, TeamRole::Admin]]);
        $this->logIn('jane@example.com');

        $this->client->request('GET', self::FRAME);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function it_should_never_show_the_version_on_a_public_page(): void
    {
        $this->running('1.1.0');

        $page = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-build-label]'));
        self::assertStringNotContainsString('1.1.0', (string) $this->client->getResponse()->getContent());
    }

    private function running(string $version): void
    {
        $_SERVER['APP_VERSION'] = $_ENV['APP_VERSION'] = $version;
    }

    private function feeds(): RecordedFeeds
    {
        $feeds = $this->client->getContainer()->get(RecordedFeeds::class);
        self::assertInstanceOf(RecordedFeeds::class, $feeds);

        return $feeds;
    }
}
