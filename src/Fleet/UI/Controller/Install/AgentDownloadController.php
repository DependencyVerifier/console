<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Install;

use Fleet\Application\Port\AgentReleaseStore;
use Fleet\UI\Controller\RouteCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The mirror of the verified binaries (install.sh checks their SHA-256 again on the host). Only the files of a stored
 * release: anything else is a 404.
 */
#[Route(path: '/downloads/agent/{version}/{name}', name: RouteCollection::AGENT_DOWNLOAD->value, requirements: ['version' => '\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?', 'name' => 'depverify-agent-[a-z0-9_]+-linux'], methods: ['GET'])]
final readonly class AgentDownloadController
{
    public function __construct(private AgentReleaseStore $releases)
    {
    }

    public function __invoke(string $version, string $name): BinaryFileResponse
    {
        $path = $this->releases->path($version, $name) ?? throw new NotFoundHttpException();
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'public, max-age=3600']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);

        return $response;
    }
}
