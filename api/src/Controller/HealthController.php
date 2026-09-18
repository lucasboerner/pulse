<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness probe for an external uptime monitor (public, never behind edge auth).
 * Confirms the web process is up AND can reach the database, so the monitor
 * catches a booted-but-broken API (e.g. DB down), not just a dead container.
 * Exposes no application data — only ok/error.
 */
final readonly class HealthController
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(
                ['status' => 'error', 'database' => 'unreachable'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
