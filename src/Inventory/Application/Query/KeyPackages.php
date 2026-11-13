<?php

declare(strict_types=1);

namespace Inventory\Application\Query;

use Inventory\Application\Query\View\KeyPackage;
use Inventory\Application\Query\View\PackageRow;

/**
 * The frameworks named in the header of an application: a fixed list (`type:namespace/name` => label), in display
 * order. Anything else stays in the package list.
 */
final class KeyPackages
{
    /** @var array<string, string> */
    public const array LABELS = [
        'composer:symfony/framework-bundle' => 'Symfony',
        'composer:laravel/framework' => 'Laravel',
        'composer:drupal/core' => 'Drupal',
        'composer:shopware/core' => 'Shopware',
        'composer:sylius/sylius' => 'Sylius',
        'composer:magento/framework' => 'Magento',
        'composer:api-platform/core' => 'API Platform',
        'composer:doctrine/orm' => 'Doctrine ORM',
        'npm:next' => 'Next.js',
        'npm:nuxt' => 'Nuxt',
        'npm:@angular/core' => 'Angular',
        'npm:@sveltejs/kit' => 'SvelteKit',
        'npm:@nestjs/core' => 'NestJS',
        'npm:react' => 'React',
        'npm:vue' => 'Vue',
        'npm:svelte' => 'Svelte',
        'npm:express' => 'Express',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * The key packages among those given, in the order of the list, each once (the first version found).
     *
     * @param list<PackageRow> $packages
     *
     * @return list<KeyPackage>
     */
    public static function of(array $packages): array
    {
        $versions = [];
        foreach ($packages as $package) {
            $versions[$package->type.':'.$package->fullName()] ??= $package->version;
        }

        $found = [];
        foreach (self::LABELS as $name => $label) {
            if (\array_key_exists($name, $versions)) {
                $found[] = new KeyPackage($label, $versions[$name]);
            }
        }

        return $found;
    }
}
