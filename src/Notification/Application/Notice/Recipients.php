<?php

declare(strict_types=1);

namespace Notification\Application\Notice;

use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Repository\SubscriptionRepository;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\FilterRecipients;
use Shared\Application\Contract\Organization\FindTeamNames;
use Symfony\Component\Uid\Uuid;

/**
 * Who may still read a notice when it leaves: an active account, still subscribed to its scope, still seeing it. What
 * was true when the notice was written may not be anymore (a role changed, a subscription turned off).
 */
final readonly class Recipients
{
    public function __construct(private SubscriptionRepository $subscriptions, private QueryBus $queries)
    {
    }

    /**
     * The email of an active account, null otherwise.
     */
    public function email(string $userId): ?string
    {
        $account = $this->queries->ask(new FindAccounts([$userId]))[$userId] ?? null;

        return true === $account?->active ? $account->email : null;
    }

    /**
     * Its subscription to the scope, if not off and if it still sees the scope.
     */
    public function subscription(string $userId, Uuid $teamId, ?Uuid $projectId): ?Subscription
    {
        $subscription = $this->subscriptions->of(Uuid::fromString($userId), $teamId, $projectId);
        if (null === $subscription || Frequency::Off === $subscription->frequency()) {
            return null;
        }

        return [] === $this->queries->ask(new FilterRecipients($teamId->toRfc4122(), $projectId?->toRfc4122(), [$userId])) ? null : $subscription;
    }

    /**
     * @param list<string> $teamIds
     *
     * @return array<string, string> by team
     */
    public function teamNames(array $teamIds): array
    {
        return $this->queries->ask(new FindTeamNames($teamIds));
    }
}
