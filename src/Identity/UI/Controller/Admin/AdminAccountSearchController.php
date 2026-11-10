<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\UI\Controller\RouteCollection;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\AccountMatch;
use Shared\Application\Contract\Identity\SearchAccounts;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Remote source of the account pickers (Symfony UX Autocomplete, `?query=`): `{"results": [{value, text}]}`, the
 * value being the email. The query handler is the judge of the permission.
 */
#[Route(path: '/admin/accounts/search', name: RouteCollection::ADMIN_ACCOUNT_SEARCH->value, methods: ['GET'])]
final readonly class AdminAccountSearchController
{
    public function __construct(private QueryBus $queries)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $matches = $this->queries->ask(new SearchAccounts($request->query->getString('query')));

        return new JsonResponse(
            ['results' => array_map(static fn (AccountMatch $match): array => [
                'value' => $match->email,
                'text' => '' === $match->displayName ? $match->email : \sprintf('%s <%s>', $match->displayName, $match->email),
            ], $matches)],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
