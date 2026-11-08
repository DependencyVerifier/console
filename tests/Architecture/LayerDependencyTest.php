<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

use function Symfony\Component\String\u;

/**
 * Guards the DDD layering of src/<Context>/{Domain,Application,Infrastructure,UI}:
 * - Domain depends only on its own Domain and Shared\Domain (plus allowed vendor namespaces);
 * - Application depends only on Domain/Application of its own context and of Shared;
 * - a context never reaches into another business context (only Shared is shared).
 */
final class LayerDependencyTest extends TestCase
{
    private const array CONTEXTS = ['Shared', 'Identity', 'Organization', 'Fleet', 'Inventory', 'Vulnerability', 'Exposure', 'Notification', 'Audit'];

    /** Vendor namespaces the Domain layer may use (mapping metadata, identifiers). */
    private const array DOMAIN_ALLOWED_VENDORS = ['Doctrine\ORM\Mapping', Types::class, 'Symfony\Component\Uid'];

    /** Vendor namespaces the Application layer may use (DI metadata, contracts). */
    private const array APPLICATION_ALLOWED_VENDORS = ['Symfony\Component\DependencyInjection\Attribute', 'Symfony\Component\Uid', 'Psr\\'];

    /**
     * @return iterable<string, array{string, string, string, list<string>}>
     */
    public static function provideImports(): iterable
    {
        $src = \dirname(__DIR__, 2).'/src';
        $files = new Finder()->files()->in($src)->name('*.php')->path('#^[^/]+/(Domain|Application)/#');

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            [$context, $layer] = u($file->getRelativePathname())->split('/', 3);
            preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;\s]+)/m', $file->getContents(), $matches);

            yield $file->getRelativePathname() => [$context->toString(), $layer->toString(), $file->getRelativePathname(), $matches[1]];
        }
    }

    /**
     * @param list<string> $imports
     */
    #[Test]
    #[DataProvider('provideImports')]
    public function it_should_respect_layer_and_context_boundaries(string $context, string $layer, string $file, array $imports): void
    {
        $violations = array_values(array_filter(
            $imports,
            fn (string $import): bool => !$this->isAllowed($context, $layer, $import),
        ));

        self::assertSame([], $violations, \sprintf('%s (%s layer of %s) has forbidden dependencies.', $file, $layer, $context));
    }

    private function isAllowed(string $context, string $layer, string $import): bool
    {
        $namespace = u($import)->before('\\')->toString();

        if (\in_array($namespace, self::CONTEXTS, true)) {
            if ($namespace !== $context && 'Shared' !== $namespace) {
                return false;
            }

            $targetLayer = u($import)->after($namespace.'\\')->before('\\')->toString();

            return 'Domain' === $layer
                ? 'Domain' === $targetLayer
                : \in_array($targetLayer, ['Domain', 'Application'], true);
        }

        if (!u($import)->containsAny('\\')) {
            return true; // Global PHP class (\Throwable, \DateTimeImmutable…).
        }

        $allowed = 'Domain' === $layer ? self::DOMAIN_ALLOWED_VENDORS : self::APPLICATION_ALLOWED_VENDORS;

        return array_any($allowed, static fn ($prefix) => u($import)->startsWith($prefix));
    }
}
