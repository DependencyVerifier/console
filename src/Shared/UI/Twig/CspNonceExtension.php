<?php

declare(strict_types=1);

namespace Shared\UI\Twig;

use Shared\Infrastructure\Http\CspNonce;
use Twig\Attribute\AsTwigFunction;

final readonly class CspNonceExtension
{
    public function __construct(private CspNonce $nonce)
    {
    }

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        return $this->nonce->value();
    }
}
