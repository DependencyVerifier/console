<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Shared\Application\Audit\AuditableCommand;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Action\ActionForm;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actions of the team and project pages: a form named like its subject, CSRF token id `<action>:<subject id>`,
 * run by ActionResponder. Success and refusal answer a Turbo Stream on the page alerts; the DataTables reload after
 * any stream and the quota gauges are refreshed through the ActionFrameMap.
 */
final readonly class OrganizationAction
{
    public function __construct(private ActionResponder $responder, private FormFactoryInterface $forms, private StreamOrRedirect $redirect)
    {
    }

    /**
     * @template TData
     *
     * @param class-string<FormTypeInterface<TData>>                $type
     * @param array<string, mixed>                                  $options
     * @param \Closure(FormInterface<TData|null>): AuditableCommand $command
     */
    public function handle(Request $request, string $name, string $tokenId, \Closure $command, string $successKey, RouteReference $fallback, string $type = ActionButtonType::class, array $options = []): Response
    {
        $form = $this->forms->createNamed($name, $type, null, ['csrf_token_id' => $tokenId, ...$options]);

        return $this->responder->handle(
            $request,
            new ActionForm($form, ActionForm::PAGE_ALERTS, 'shared/frame/refusal_stream.html.twig'),
            static fn (): AuditableCommand => $command($form),
            $this->redirect->withSuccessKey($successKey),
            $fallback,
        );
    }
}
