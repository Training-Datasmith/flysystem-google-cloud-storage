<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests;

use Google\Cloud\Storage\Acl;
use League\Flysystem\GoogleCloudStorage\PortableVisibilityHandler;
use League\Flysystem\GoogleCloudStorage\Tests\Doubles\MemoryBucket;
use League\Flysystem\GoogleCloudStorage\Tests\Doubles\MemoryObject;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortableVisibilityHandlerTest extends TestCase
{
    public function test_predefined_acl_defaults(): void
    {
        $handler = new PortableVisibilityHandler();

        $this->assertSame(PortableVisibilityHandler::ACL_PUBLIC_READ, $handler->visibilityToPredefinedAcl(Visibility::PUBLIC));
        $this->assertSame(PortableVisibilityHandler::ACL_PROJECT_PRIVATE, $handler->visibilityToPredefinedAcl(Visibility::PRIVATE));
        $this->assertSame(PortableVisibilityHandler::ACL_PROJECT_PRIVATE, $handler->visibilityToPredefinedAcl('inherit'));
        $this->assertSame(
            PortableVisibilityHandler::NO_PREDEFINED_VISIBILITY,
            $handler->visibilityToPredefinedAcl(PortableVisibilityHandler::NO_PREDEFINED_VISIBILITY),
        );
    }

    public function test_set_and_determine_use_configured_entity(): void
    {
        $bucket = new MemoryBucket();
        $object = new MemoryObject($bucket, 'file.txt', 'x');
        $handler = new PortableVisibilityHandler('group-editors@example.com');

        $handler->setVisibility($object, Visibility::PUBLIC);
        $this->assertSame(Visibility::PUBLIC, $handler->determineVisibility($object));

        $handler->setVisibility($object, Visibility::PRIVATE);
        $this->assertSame(Visibility::PRIVATE, $handler->determineVisibility($object));
    }

    public function test_not_found_acl_is_private(): void
    {
        $bucket = new MemoryBucket();
        $object = new MemoryObject($bucket, 'file.txt', 'x');
        $handler = new PortableVisibilityHandler();

        $this->assertSame(Visibility::PRIVATE, $handler->determineVisibility($object));
    }

    public function test_non_reader_role_is_private(): void
    {
        $bucket = new MemoryBucket();
        $object = new MemoryObject($bucket, 'file.txt', 'x');
        $object->acl()->update('allUsers', Acl::ROLE_OWNER);
        $handler = new PortableVisibilityHandler();

        $this->assertSame(Visibility::PRIVATE, $handler->determineVisibility($object));
    }

    public function test_other_acl_exceptions_propagate(): void
    {
        $bucket = new MemoryBucket();
        $object = new MemoryObject($bucket, 'file.txt', 'x');
        $object->acl()->getThrowable = new RuntimeException('acl down');
        $handler = new PortableVisibilityHandler();

        $this->expectException(RuntimeException::class);
        $handler->determineVisibility($object);
    }
}
