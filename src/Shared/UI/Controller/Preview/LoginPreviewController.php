<?php

declare(strict_types=1);

namespace Shared\UI\Controller\Preview;

use Shared\UI\Controller\RouteCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static design preview of the sign-in screen (docs/design.md §6). Remove once authentication exists.
 */
#[Route(path: '/_preview/login', name: RouteCollection::PREVIEW_LOGIN->value, methods: ['GET'])]
final class LoginPreviewController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('shared/preview/login.html.twig');
    }
}
