<?php

declare(strict_types=1);

namespace Shared\Application\Security;

/**
 * Marker of the commands whose success shows a secret once (TOTP confirmation, backup code regeneration): the UI
 * must answer with SecretRevealingResponse (200, no-store, never a redirect nor a flash). ActionResponder refuses
 * any mismatch before dispatching.
 */
interface RevealsSecret
{
}
