<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests\Doubles;

use Google\Cloud\Storage\StorageObject;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, StorageObject>
 */
final class ObjectListing implements IteratorAggregate
{
    /** @param array<int, StorageObject> $items */
    public function __construct(
        private array $items,
        private array $prefixes = [],
    ) {
    }

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    public function prefixes(): array
    {
        return $this->prefixes;
    }
}
