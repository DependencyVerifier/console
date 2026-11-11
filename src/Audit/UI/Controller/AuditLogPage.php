<?php

declare(strict_types=1);

namespace Audit\UI\Controller;

use Audit\Application\Query\AuditLog\AuditCursor;
use Audit\Application\Query\AuditLog\AuditEntryView;
use Audit\Application\Query\AuditLog\InvalidAuditCursor;
use Audit\Application\Query\AuditLog\ListAuditEntries;
use Shared\Application\Audit\OutcomeKind;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Twig\Environment;

/**
 * An audit page (global or of a team): whitelisted outcome filter, keyset cursor validated before the query (422 when
 * invalid), the actors shown by their email (asked to Identity). ListAuditEntries is the judge of the permission. The
 * "Older entries" link targets the `audit` frame of the same page URL (advance): a reload shows the full page.
 */
final readonly class AuditLogPage
{
    public function __construct(private QueryBus $queries, private Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(Request $request, ?string $teamId, string $template, array $context): Response
    {
        $outcome = OutcomeKind::tryFrom($request->query->getString('outcome'));
        $cursor = $request->query->getString('cursor');
        if ('' !== $cursor) {
            try {
                AuditCursor::decode($cursor);
            } catch (InvalidAuditCursor $invalid) {
                throw new UnprocessableEntityHttpException('', $invalid);
            }
        }

        $page = $this->queries->ask(new ListAuditEntries($teamId, $outcome, '' === $cursor ? null : $cursor));
        $actorIds = array_values(array_unique(array_filter(array_map(static fn (AuditEntryView $entry): ?string => $entry->actorId, $page->entries))));
        $accounts = [] === $actorIds ? [] : $this->queries->ask(new FindAccounts($actorIds));

        $response = new Response($this->twig->render($template, [
            ...$context,
            'entries' => $page->entries,
            'next' => $page->next?->encode(),
            'outcome' => $outcome?->value,
            'outcomes' => array_map(static fn (OutcomeKind $kind): string => $kind->value, OutcomeKind::cases()),
            'actors' => array_map(static fn ($account): string => $account->email, $accounts),
            'cursor' => '' === $cursor ? null : $cursor,
        ]));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
