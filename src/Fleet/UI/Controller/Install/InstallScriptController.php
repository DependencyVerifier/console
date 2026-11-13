<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Install;

use Fleet\Application\Port\AgentReleaseStore;
use Fleet\Application\Port\ConsoleSigner;
use Fleet\UI\Controller\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

use function Symfony\Component\String\u;

/**
 * `curl -fsSL https://console/install.sh | sudo AGENT_TOKEN=… sh` (cadrage §6): generated here, it holds the console
 * URL, its public key (pinned by the agent at once) and the SHA-256 of the binary of each architecture, from a
 * release verified with the project release key. Public, no secret inside: the token stays on the host.
 */
#[Route(path: '/install.sh', name: RouteCollection::INSTALL_SCRIPT->value, methods: ['GET'])]
final readonly class InstallScriptController
{
    public function __construct(private AgentReleaseStore $releases, private ConsoleSigner $signer, private Environment $twig)
    {
    }

    public function __invoke(Request $request): Response
    {
        $release = $this->releases->latest();
        $console = $request->getSchemeAndHttpHost();
        // Only what a shell single-quoted string holds safely ever enters the script.
        if (null === $release || [] === u($console)->match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?$#')) {
            return new Response("#!/bin/sh\necho 'No verified release of the agent is available on this console yet.' >&2\nexit 1\n", Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/x-shellscript; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }

        return new Response($this->twig->render('fleet/install.sh.twig', [
            'console_url' => $console,
            'console_key' => $this->signer->publicKey()->text(),
            'release' => $release,
        ]), Response::HTTP_OK, ['Content-Type' => 'text/x-shellscript; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
