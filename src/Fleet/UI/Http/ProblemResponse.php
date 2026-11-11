<?php

declare(strict_types=1);

namespace Fleet\UI\Http;

use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\Problem;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RFC 9457 problem document of the agent API (protocol §9), never cached. `server_time` lets the agent fix its clock
 * offset; `Retry-After` on 429.
 */
final class ProblemResponse
{
    /**
     * @param array<string, mixed> $extra members of the problem (`missing` of missing_snapshots)
     */
    public static function of(Problem $problem, \DateTimeImmutable $now, string $detail = '', ?\DateTimeImmutable $retryAfter = null, array $extra = []): JsonResponse
    {
        $document = ['type' => $problem->type(), 'title' => $problem->title(), 'status' => $problem->status()];
        if ('' !== $detail) {
            $document['detail'] = $detail;
        }

        $document += $extra;

        $document['server_time'] = OrderSigner::time($now);
        $response = new JsonResponse($document, $problem->status(), ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
        if (null !== $retryAfter) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter->getTimestamp() - $now->getTimestamp()));
        }

        return $response;
    }

    public static function generic(int $status, \DateTimeImmutable $now): JsonResponse
    {
        return new JsonResponse(['type' => 'about:blank', 'title' => 'Server error', 'status' => $status, 'server_time' => OrderSigner::time($now)], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
