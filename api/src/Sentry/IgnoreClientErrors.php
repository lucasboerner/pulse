<?php

declare(strict_types=1);

namespace App\Sentry;

use ApiPlatform\Metadata\Exception\HttpExceptionInterface as ApiPlatformHttpExceptionInterface;
use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Sentry `before_send` callback (config/packages/sentry.yaml): drops an event whose
 * exception the API answers with a 4xx — an unknown route, a failed login, a
 * validation error. Those are the client's mistake, not a bug, and would bury the
 * 5xx that are.
 *
 * The bundle's error listener captures on kernel.exception, before API Platform and
 * the security firewall turn the exception into a response, so the status is
 * resolved here the way they will: API Platform's global exception_to_status map
 * first, then the exception's own status, then the security exceptions the firewall
 * maps to 401/403. Only events raised inside a request are filtered — the same
 * exception in the worker or scheduler is a failed message, and is reported.
 */
final readonly class IgnoreClientErrors
{
    /**
     * @param array<string, int> $exceptionToStatus exception class => HTTP status
     */
    public function __construct(
        private RequestStack $requestStack,
        #[Autowire('%api_platform.exception_to_status%')]
        private array $exceptionToStatus,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        if (null === $exception || null === $this->requestStack->getCurrentRequest()) {
            return $event;
        }

        $status = $this->statusCode($exception);

        return (null !== $status && 400 <= $status && 500 > $status) ? null : $event;
    }

    private function statusCode(\Throwable $exception): ?int
    {
        foreach ($this->exceptionToStatus as $class => $status) {
            if (is_a($exception, $class)) {
                return $status;
            }
        }

        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof ProblemExceptionInterface && null !== $exception->getStatus() => $exception->getStatus(),
            $exception instanceof ApiPlatformHttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof RequestExceptionInterface => 400,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AccessDeniedException => 403,
            default => null,
        };
    }
}
