<?php

declare(strict_types=1);

namespace App\Tests\Sentry;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Sentry\IgnoreClientErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The before_send filter drops what the API answers with a 4xx and keeps everything
 * else. It runs against the container's own service, so API Platform's real
 * exception_to_status map is the one under test.
 */
final class IgnoreClientErrorsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function clientErrors(): iterable
    {
        yield 'unknown route' => [new NotFoundHttpException()];
        yield 'wrong method' => [new MethodNotAllowedHttpException(['GET'])];
        yield 'rate limited' => [new TooManyRequestsHttpException()];
        yield 'failed login' => [new BadCredentialsException()];
        yield 'security expression denies' => [new AccessDeniedException()];
        yield 'validation error' => [new ValidationException(new ConstraintViolationList())];
        yield 'malformed payload (exception_to_status)' => [NotNormalizableValueException::createForUnexpectedDataType('bad', 'x', ['int'])];
        yield 'unknown IRI (exception_to_status)' => [new ItemNotFoundException()];
    }

    #[DataProvider('clientErrors')]
    public function testAClientErrorInsideARequestIsDropped(\Throwable $exception): void
    {
        $this->insideARequest();

        self::assertNull($this->filter($exception));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function serverErrors(): iterable
    {
        yield 'plain exception' => [new \RuntimeException('boom')];
        yield '5xx http exception' => [new HttpException(503)];
    }

    #[DataProvider('serverErrors')]
    public function testAServerErrorInsideARequestIsKept(\Throwable $exception): void
    {
        $this->insideARequest();

        self::assertNotNull($this->filter($exception));
    }

    public function testAClientErrorOutsideARequestIsKept(): void
    {
        // A worker or scheduler message that throws is a failed message, whatever
        // status the same exception would map to over HTTP.
        self::bootKernel();

        self::assertNotNull($this->filter(new NotFoundHttpException()));
    }

    public function testAnEventWithoutAnExceptionIsKept(): void
    {
        $this->insideARequest();
        $event = Event::createEvent();

        self::assertSame($event, $this->service()($event, null));
    }

    private function insideARequest(): void
    {
        self::bootKernel();
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/api/monitors'));
    }

    private function filter(\Throwable $exception): ?Event
    {
        return $this->service()(Event::createEvent(), EventHint::fromArray(['exception' => $exception]));
    }

    private function service(): IgnoreClientErrors
    {
        $service = self::getContainer()->get(IgnoreClientErrors::class);
        self::assertInstanceOf(IgnoreClientErrors::class, $service);

        return $service;
    }
}
