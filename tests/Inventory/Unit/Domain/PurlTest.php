<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Domain;

use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\Package;
use Inventory\Domain\Model\Purl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class PurlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string, string, ?string}>
     */
    public static function purls(): iterable
    {
        yield 'composer' => ['pkg:composer/symfony/http-kernel@6.4.3', 'composer', 'symfony', 'http-kernel', '6.4.3'];
        yield 'scoped npm' => ['pkg:npm/%40hotwired/turbo@8.0.23', 'npm', '@hotwired', 'turbo', '8.0.23'];
        yield 'npm without scope' => ['pkg:npm/lodash@4.17.21', 'npm', null, 'lodash', '4.17.21'];
        yield 'debian with qualifiers' => ['pkg:deb/debian/openssl@3.5.1-1?arch=amd64&distro=debian-13', 'deb', 'debian', 'openssl', '3.5.1-1'];
        yield 'epoch, encoded' => ['pkg:rpm/fedora/curl@1%3A8.6.0-1?arch=x86_64', 'rpm', 'fedora', 'curl', '1:8.6.0-1'];
        yield 'subpath' => ['pkg:golang/github.com/gorilla/mux@1.8.0#context', 'golang', 'github.com/gorilla', 'mux', '1.8.0'];
        yield 'no version' => ['pkg:apk/alpine/busybox', 'apk', 'alpine', 'busybox', null];
        yield 'empty version' => ['pkg:apk/alpine/busybox@', 'apk', 'alpine', 'busybox', null];
        yield 'type in upper case, slashes' => ['pkg://NPM/left-pad@1.3.0', 'npm', null, 'left-pad', '1.3.0'];
    }

    #[Test]
    #[DataProvider('purls')]
    public function it_should_read_a_package_url(string $purl, string $type, ?string $namespace, string $name, ?string $version): void
    {
        $parsed = Purl::parse($purl);
        self::assertSame([$purl, $type, $namespace, $name, $version], [$parsed->value, $parsed->type, $parsed->namespace, $parsed->name, $parsed->version]);
    }

    #[Test]
    public function it_should_refuse_what_is_not_a_package_url(): void
    {
        foreach (['composer/symfony/x@1', 'pkg:', 'pkg:npm', 'pkg:9npm/x@1', 'pkg:'.u('a/b')->repeat(1024)->toString()] as $notAPurl) {
            try {
                Purl::parse($notAPurl);
                self::fail($notAPurl);
            } catch (InvalidInventory) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(2042, u(Purl::parse('pkg:t/'.u('a')->repeat(2042)->toString())->name)->length(), '2 048 characters is the limit.');
    }

    #[Test]
    public function it_should_keep_what_the_agent_says_of_a_package_bounded(): void
    {
        $purl = Purl::parse('pkg:composer/a/b@1');
        $package = new Package($purl, 'direct', 'composer.lock', 'composer');
        self::assertSame([$purl, 'direct', 'composer.lock', 'composer'], [$package->purl, $package->dependency, $package->source, $package->manager]);
        self::assertSame('transitive', new Package($purl, 'transitive')->dependency);
        $odd = new Package($purl, 'optional', '', u('x')->repeat(300)->toString());
        self::assertSame([null, null, 255], [$odd->dependency, $odd->source, u((string) $odd->manager)->length()]);
        self::assertSame([null, null, null], [new Package($purl)->dependency, new Package($purl)->source, new Package($purl)->manager]);
    }
}
