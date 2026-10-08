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
    private bool $iterationStarted = false;

    /** @param array<int, StorageObject> $items */
    public function __construct(
        private array $items,
        private array $prefixes = [],
    ) {
    }

    public function getIterator(): Traversable
    {
        $this->iterationStarted = true;

        yield from $this->items;
    }

    public function prefixes(): array
    {
        if (! $this->iterationStarted) {
            return [];
        }

        return $this->prefixes;
    }
}
