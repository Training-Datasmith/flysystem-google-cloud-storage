<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests;

use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\Tests\Doubles\MemoryBucket;
use League\Flysystem\GoogleCloudStorage\VisibilityHandler;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\MimeTypeDetector;

trait CreatesAdapter
{
    protected function adapter(
        MemoryBucket $bucket,
        string $prefix = '',
        ?VisibilityHandler $visibilityHandler = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
        string $defaultVisibility = Visibility::PRIVATE,
        bool $streamReads = false,
    ): GoogleCloudStorageAdapter {
        return new GoogleCloudStorageAdapter(
            $bucket,
            $prefix,
            $visibilityHandler,
            $defaultVisibility,
            $mimeTypeDetector,
            $streamReads,
        );
    }
}
