<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Records published updates in memory so a test can assert what the pipeline sent
 * without the hub being reachable over the network. Bound in place of the real hub
 * in the test environment.
 */
final class CollectingHub implements HubInterface
{
    /**
     * @var list<Update>
     */
    private array $updates = [];

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
        $this->updates[] = $update;

        return '';
    }

    /**
     * @return list<Update>
     */
    public function getUpdates(): array
    {
        return $this->updates;
    }
}
