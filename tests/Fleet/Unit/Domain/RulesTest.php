<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Model\AgentNotApproved;
use Fleet\Domain\Model\AgentNotPending;
use Fleet\Domain\Model\EnrollmentTokenExpired;
use Fleet\Domain\Model\EnrollmentTokenInvalid;
use Fleet\Domain\Model\EnrollmentTokenNotApprovable;
use Fleet\Domain\Model\HostHasAgents;
use Fleet\Domain\Model\InvalidEnrollmentPayload;
use Fleet\Domain\Model\InvalidEnrollmentTokenSettings;
use Fleet\Domain\Model\InvalidHostFacts;
use Fleet\Domain\Model\InvalidOrderPayload;
use Fleet\Domain\Model\InvalidPublicKey;
use Fleet\Domain\Model\RevokedAgent;
use Fleet\Domain\Model\ScanAlreadyRequested;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule keys of Fleet: audit reasons (`rule:<key>`), translation keys, and for some the protocol problem codes.
 */
final class RulesTest extends TestCase
{
    #[Test]
    public function it_should_name_every_rule(): void
    {
        $rules = [
            'agent_not_approved' => new AgentNotApproved(),
            'agent_not_pending' => new AgentNotPending(),
            'token_expired' => new EnrollmentTokenExpired(),
            'token_invalid' => new EnrollmentTokenInvalid(),
            'enrollment_token_not_approvable' => new EnrollmentTokenNotApprovable(),
            'host_has_agents' => new HostHasAgents(),
            'invalid_payload' => new InvalidEnrollmentPayload(),
            'invalid_enrollment_token_settings' => new InvalidEnrollmentTokenSettings(),
            'invalid_order_payload' => new InvalidOrderPayload(),
            'invalid_public_key' => new InvalidPublicKey(),
            'agent_revoked' => new RevokedAgent(),
            'scan_now_rate' => new ScanAlreadyRequested(),
        ];
        foreach ($rules as $key => $rule) {
            self::assertSame($key, $rule->ruleKey());
            self::assertSame($key, $rule->getMessage());
        }

        $facts = new InvalidHostFacts('hostname');
        self::assertSame(['invalid_payload', 'invalid_payload', 'hostname'], [$facts->ruleKey(), $facts->getMessage(), $facts->field], 'The same answer to the agent as the other enrollment facts.');
    }
}
