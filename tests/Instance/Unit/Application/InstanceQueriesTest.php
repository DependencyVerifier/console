<?php

declare(strict_types=1);

namespace Tests\Instance\Unit\Application;

use Instance\Application\Query\Update\FindAvailableUpdate;
use Instance\Application\Query\Update\Handler\FindAvailableUpdateHandler;
use Instance\Application\Query\Version\GetBuildLabel;
use Instance\Application\Query\Version\Handler\GetBuildLabelHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Tests\Instance\Support\FixedActor;
use Tests\Instance\Support\FixedCurrentBuild;
use Tests\Instance\Support\InMemoryLatestConsoleReleaseSource;

final class InstanceQueriesTest extends TestCase
{
    #[Test]
    public function it_should_tell_the_super_admin_about_a_newer_release(): void
    {
        $source = InMemoryLatestConsoleReleaseSource::publishing('1.2.0');

        $update = new FindAvailableUpdateHandler(FixedActor::superAdmin(), new FixedCurrentBuild('1.1.2'), $source)(new FindAvailableUpdate());

        self::assertSame('1.2.0', $update?->latestVersion());
        self::assertSame('1.1.2', $update->currentVersion());
        self::assertSame(1, $source->calls);
    }

    #[Test]
    public function it_should_tell_nothing_when_up_to_date_or_without_a_known_release(): void
    {
        $handler = static fn (string $running, InMemoryLatestConsoleReleaseSource $source): FindAvailableUpdateHandler => new FindAvailableUpdateHandler(FixedActor::superAdmin(), new FixedCurrentBuild($running), $source);

        self::assertNull($handler('1.2.0', InMemoryLatestConsoleReleaseSource::publishing('1.2.0'))(new FindAvailableUpdate()));
        self::assertNull($handler('1.3.0', InMemoryLatestConsoleReleaseSource::publishing('1.2.0'))(new FindAvailableUpdate()));
        self::assertNull($handler('1.1.2', InMemoryLatestConsoleReleaseSource::none())(new FindAvailableUpdate()));
    }

    #[Test]
    public function it_should_never_ask_upstream_for_a_build_without_a_version(): void
    {
        $source = InMemoryLatestConsoleReleaseSource::publishing('1.2.0');

        $update = new FindAvailableUpdateHandler(FixedActor::superAdmin(), new FixedCurrentBuild('dev'), $source)(new FindAvailableUpdate());

        self::assertNull($update);
        self::assertSame(0, $source->calls, 'A super admin, so only the missing version stops the call.');
    }

    #[Test]
    public function it_should_refuse_anyone_but_the_super_admin_before_asking_upstream(): void
    {
        $source = InMemoryLatestConsoleReleaseSource::publishing('1.2.0');
        $handler = new FindAvailableUpdateHandler(FixedActor::user(), new FixedCurrentBuild('1.1.2'), $source);

        try {
            $handler(new FindAvailableUpdate());
            self::fail('A user who is not the super admin, even holding delegated instance permissions, is refused.');
        } catch (PermissionDenied $e) {
            self::assertSame('Permission "instance.super_admin" required.', $e->getMessage());
        }

        self::assertSame(0, $source->calls);
    }

    #[Test]
    public function it_should_give_the_build_label_to_anyone(): void
    {
        self::assertSame('v1.1.0-3-gabc123', new GetBuildLabelHandler(new FixedCurrentBuild('v1.1.0-3-gabc123'))(new GetBuildLabel())->label());
    }
}
