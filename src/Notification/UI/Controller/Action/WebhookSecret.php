<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Shared\Application\Crypto\TokenGenerator;

/**
 * The secret of a webhook, generated here and shown once (`whsec_` and 43 URL-safe characters, 256 bits).
 */
final class WebhookSecret
{
    public static function generate(TokenGenerator $tokens): string
    {
        return 'whsec_'.$tokens->urlToken();
    }
}
