<?php

declare(strict_types=1);

namespace Inventory\Application\Query;

use function Symfony\Component\String\u;

/**
 * A human name for the `distro` qualifier of OS packages (`alpine-3.21` => `Alpine 3.21`).
 */
final class OsName
{
    private const array NAMES = [
        'alpine' => 'Alpine',
        'debian' => 'Debian',
        'ubuntu' => 'Ubuntu',
        'rhel' => 'RHEL',
        'centos' => 'CentOS',
        'rocky' => 'Rocky Linux',
        'almalinux' => 'AlmaLinux',
        'amzn' => 'Amazon Linux',
        'fedora' => 'Fedora',
        'ol' => 'Oracle Linux',
        'opensuse-leap' => 'openSUSE Leap',
        'sles' => 'SUSE Linux Enterprise',
        'wolfi' => 'Wolfi',
    ];

    public static function of(string $distro): string
    {
        /** @var array{}|array{string, string, string} $match both groups always set on a match */
        $match = u($distro)->match('/^(.+?)-(\d[^-]*)$/');
        if ([] === $match) {
            return self::name($distro);
        }

        return self::name($match[1]).' '.$match[2];
    }

    private static function name(string $id): string
    {
        return self::NAMES[$id] ?? u($id)->title()->toString();
    }
}
