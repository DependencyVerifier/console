<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Symfony\Component\Form\FormInterface;

/**
 * A form (or a field-less ActionButtonType) with the frame it lives in and its template: ActionResponder knows
 * where and how to render it again, with its submitted values, on a refusal.
 */
final readonly class ActionForm
{
    /** Forms posted from a list (DataTable rows): no frame of their own, refusals go to the page alerts as a stream. */
    public const string PAGE_ALERTS = 'action-alerts';

    /**
     * @param FormInterface<covariant mixed> $form
     * @param array<string, mixed>           $templateContext
     */
    public function __construct(
        public FormInterface $form,
        public string $frameId,
        public string $template,
        public array $templateContext = [],
    ) {
    }
}
