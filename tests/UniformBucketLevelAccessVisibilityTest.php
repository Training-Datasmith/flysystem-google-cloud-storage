<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests;

use League\Flysystem\GoogleCloudStorage\Tests\Doubles\MemoryBucket;
use League\Flysystem\GoogleCloudStorage\Tests\Doubles\MemoryObject;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

final class UniformBucketLevelAccessVisibilityTest extends TestCase
{
    public function test_sentinel_visibility_and_predefined_acl(): void
    {
        $handler = new UniformBucketLevelAccessVisibility();

        $this->assertSame(
            UniformBucketLevelAccessVisibility::NO_PREDEFINED_VISIBILITY,
            $handler->determineVisibility(new MemoryObject(new MemoryBucket(), 'x')),
        );
        $this->assertSame(
            UniformBucketLevelAccessVisibility::NO_PREDEFINED_VISIBILITY,
            $handler->visibilityToPredefinedAcl(Visibility::PUBLIC),
        );
        $this->assertSame(
            UniformBucketLevelAccessVisibility::NO_PREDEFINED_VISIBILITY,
            $handler->visibilityToPredefinedAcl(Visibility::PRIVATE),
        );
    }

    public function test_set_visibility_is_a_noop(): void
    {
        $object = new MemoryObject(new MemoryBucket(), 'file.txt');
        $handler = new UniformBucketLevelAccessVisibility();

        $handler->setVisibility($object, Visibility::PUBLIC);
        $handler->setVisibility($object, Visibility::PRIVATE);

        $this->assertSame(
            UniformBucketLevelAccessVisibility::NO_PREDEFINED_VISIBILITY,
            $handler->determineVisibility($object),
        );
    }
}
