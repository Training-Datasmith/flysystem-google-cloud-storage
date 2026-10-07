<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests\Doubles;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use LogicException;
use Throwable;

class MemoryBucket extends Bucket
{
    /** @var array<string, MemoryObject> */
    private array $objects = [];

    /** @var array<int, array<string, mixed>> */
    public array $objectListCalls = [];

    /** @var array<int, array{data: mixed, options: array<string, mixed>}> */
    public array $uploads = [];

    /** @var array<int, string> */
    public array $deletedObjectNames = [];

    /** @var array<string, Throwable> */
    private array $uploadFailures = [];

    /** @var array<string, Throwable> */
    private array $objectFailures = [];

    private ?ObjectListing $nextListing = null;

    private ?Throwable $objectsThrowable = null;

    public function __construct(private string $bucketName = 'my-bucket')
    {
        // Intentionally skip Google\Cloud\Storage\Bucket::__construct.
    }

    public function name(): string
    {
        return $this->bucketName;
    }

    public function seedObject(string $name, string $data = '', array $info = []): MemoryObject
    {
        $object = new MemoryObject($this, $name, $data, $info);
        $this->objects[$name] = $object;

        return $object;
    }

    public function hasObject(string $name): bool
    {
        return array_key_exists($name, $this->objects);
    }

    public function removeObject(string $name): void
    {
        unset($this->objects[$name]);
        $this->deletedObjectNames[] = $name;
    }

    public function failUpload(string $name, ?Throwable $throwable = null): void
    {
        $this->uploadFailures[$name] = $throwable ?? new LogicException('upload failed');
    }

    public function failObject(string $name, ?Throwable $throwable = null): void
    {
        $this->objectFailures[$name] = $throwable ?? new LogicException('object failed');
    }

    public function setNextListing(ObjectListing $listing): void
    {
        $this->nextListing = $listing;
    }

    public function setObjectsThrowable(Throwable $throwable): void
    {
        $this->objectsThrowable = $throwable;
    }

    public function upload($data, array $options = []): StorageObject
    {
        $name = $options['name'] ?? 'unknown-object-name';

        if (isset($this->uploadFailures[$name])) {
            $failure = $this->uploadFailures[$name];
            unset($this->uploadFailures[$name]);
            throw $failure;
        }

        $payload = is_resource($data) ? stream_get_contents($data) : (string) $data;
        $this->uploads[] = ['data' => $payload, 'options' => $options];

        $metadata = $options['metadata'] ?? [];
        $info = ['contentType' => $metadata['contentType'] ?? null];

        return $this->seedObject($name, $payload, array_filter($info, static fn ($value) => $value !== null));
    }

    public function object($name, array $options = []): StorageObject
    {
        if (isset($this->objectFailures[$name])) {
            $failure = $this->objectFailures[$name];
            unset($this->objectFailures[$name]);
            throw $failure;
        }

        if (! isset($this->objects[$name])) {
            return new MemoryObject($this, $name);
        }

        return $this->objects[$name];
    }

    public function objects(array $options = []): ObjectListing
    {
        $this->objectListCalls[] = $options;

        if ($this->objectsThrowable instanceof Throwable) {
            throw $this->objectsThrowable;
        }

        if ($this->nextListing instanceof ObjectListing) {
            $listing = $this->nextListing;
            $this->nextListing = null;

            return $listing;
        }

        return new ObjectListing([]);
    }
}
