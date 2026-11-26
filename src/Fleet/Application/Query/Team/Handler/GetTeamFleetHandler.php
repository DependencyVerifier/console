<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team\Handler;

use Fleet\Application\Port\ConsoleSigner;
use Fleet\Application\Query\Team\GetTeamFleet;
use Fleet\Application\Query\View\FleetView;
use Fleet\Application\Security\FleetAccess;
use Shared\Application\Bus\QueryHandler;
use Shared\Domain\Authorization\Permission;

final readonly class GetTeamFleetHandler implements QueryHandler
{
    private const array GESTURES = [Permission::HostEnroll, Permission::HostApprove, Permission::HostRevoke, Permission::HostDelete, Permission::HostOrder, Permission::HostEdit];

    public function __construct(private FleetAccess $access, private ConsoleSigner $signer)
    {
    }

    public function __invoke(GetTeamFleet $query): FleetView
    {
        $team = $this->access->view($query->teamId);
        $can = [];
        foreach (self::GESTURES as $gesture) {
            $can[$gesture->value] = $this->access->allows($gesture, $query->teamId);
        }

        return new FleetView($team->teamId, $team->teamName, $can, $this->signer->publicKey()->text(), $this->signer->publicKey()->fingerprint());
    }
}
