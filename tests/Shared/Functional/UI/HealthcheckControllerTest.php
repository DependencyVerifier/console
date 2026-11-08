<?php

declare(strict_types=1);

namespace Tests\Shared\Functional\UI;

use PHPUnit\Framework\Attributes\Test;
use Shared\Application\Health\HealthProbe;
use Shared\Domain\Health\ProbeResult;
use Shared\Infrastructure\Health\DatabaseHealthProbe;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Needs the test database (`make db-test-prepare`, run by `make test`).
 */
final class HealthcheckControllerTest extends WebTestCase
{
    #[Test]
    public function it_should_render_the_healthcheck_page_when_dependencies_are_up(): void
    {
        $client = self::createClient();

        $client->request('GET', '/_healthcheck');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.rmh-healthcheck__title', 'Application up');
        self::assertSelectorExists('.rmh-healthcheck--up');
        self::assertSelectorExists('.rmh-healthcheck__tag svg.rm-tag-logo');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    public function it_should_not_load_any_external_resource(): void
    {
        $client = self::createClient();

        $client->request('GET', '/_healthcheck');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('fonts.bunny.net', $html);
        self::assertDoesNotMatchRegularExpression('/<(link|script)[^>]+(href|src)="https?:/i', $html);
    }

    #[Test]
    public function it_should_answer_503_without_failure_details_when_the_database_is_down(): void
    {
        $client = self::createClient();
        self::getContainer()->set(DatabaseHealthProbe::class, new class implements HealthProbe {
            public function check(): ProbeResult
            {
                return ProbeResult::down(DatabaseHealthProbe::NAME, 'SQLSTATE[08006] secret connection detail');
            }
        });

        $client->request('GET', '/_healthcheck');

        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString(
            '{"status":"down","checks":{"database":"down"}}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
