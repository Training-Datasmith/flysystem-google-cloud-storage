<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests\Doubles;

use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\Acl;
use Throwable;

final class MemoryAcl
{
    /** @var array<string, string> */
    private array $roles = [];

    public ?Throwable $getThrowable = null;

    public function get(array $options = []): array
    {
        if ($this->getThrowable instanceof Throwable) {
            throw $this->getThrowable;
        }

        $entity = $options['entity'] ?? 'allUsers';

        if (! array_key_exists($entity, $this->roles)) {
            throw new NotFoundException('ACL not found');
        }

        return ['role' => $this->roles[$entity]];
    }

    public function update(string $entity, string $role): void
    {
        $this->roles[$entity] = $role;
    }

    public function delete(string $entity): void
    {
        unset($this->roles[$entity]);
    }

    public function grantReader(string $entity): void
    {
        $this->roles[$entity] = Acl::ROLE_READER;
    }
}
