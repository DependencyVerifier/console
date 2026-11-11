<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Agent;

use Fleet\Application\Query\View\PollAnswer;
use Shared\Application\Bus\Query;

/**
 * The answer to a poll, read after RecordAgentPoll: status, the signed orders still deliverable, next_poll_in.
 *
 * @implements Query<PollAnswer>
 */
final readonly class GetPollAnswer implements Query
{
    public function __construct(public string $agentId)
    {
    }
}
