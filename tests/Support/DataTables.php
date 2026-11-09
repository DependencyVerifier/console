<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A server-side DataTable draw, as datatable_controller.ts posts it.
 */
final class DataTables
{
    /**
     * @return array{draw: int, recordsTotal: int, recordsFiltered: int, data: list<array<string, string>>}
     */
    public static function draw(KernelBrowser $client, string $url, string $table, string $search = '', int $start = 0, int $length = 25): array
    {
        $client->request('POST', $url, ['_dt' => $table, 'draw' => 1, 'start' => $start, 'length' => $length, 'search' => ['value' => $search]]);
        $response = $client->getResponse();
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('Draw answered %d.', $response->getStatusCode()));
        }

        /** @var array{draw: int, recordsTotal: int, recordsFiltered: int, data: list<array<string, string>>} */
        return json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }

    /**
     * The HTML of one cell, as a crawler (to find the forms of the actions column).
     */
    public static function cell(string $html, string $baseUri = 'https://localhost/'): Crawler
    {
        return new Crawler('<div>'.$html.'</div>', $baseUri);
    }
}
