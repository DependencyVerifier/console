<?php

declare(strict_types=1);

namespace Shared\UI\Controller\Preview;

use Shared\UI\Controller\RouteCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static design preview of an object page (docs/design.md). Remove once real screens exist.
 */
#[Route(path: '/', name: RouteCollection::PREVIEW_HOST->value, methods: ['GET'])]
final class HostPreviewController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('shared/preview/host.html.twig');
    }
}
