<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * A hub that is unreachable: every publish raises the transport exception a real,
 * HTTP-backed hub raises when it cannot be reached, so a test can prove a failed
 * publish never fails an already-committed check.
 */
final class ThrowingHub implements HubInterface
{
    public function getPublicUrl(): string
    {
        return 'https://mercure.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::Legacy;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }

    public function publish(Update $update): string
    {
        throw new TransportException('The Mercure hub is unreachable.');
    }
}
