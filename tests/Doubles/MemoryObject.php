<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests\Doubles;

use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

class MemoryObject extends StorageObject
{
    private MemoryAcl $acl;

    /** @var array<string, mixed> */
    private array $info;

    /** @var array<int, array<string, mixed>> */
    public array $downloadStreamOptions = [];

    public ?Throwable $existsThrowable = null;

    public ?Throwable $downloadThrowable = null;

    public ?Throwable $infoThrowable = null;

    public ?Throwable $signedUrlThrowable = null;

    public bool $detachReturnsNull = false;

    public ?\DateTimeInterface $lastSignedExpiry = null;

    /** @var array<string, mixed> */
    public array $lastSignedOptions = [];

    public function __construct(
        private MemoryBucket $bucket,
        private string $objectName,
        private string $data = '',
        array $info = [],
    ) {
        $this->acl = new MemoryAcl();
        $this->info = $info + [
            'name' => $objectName,
            'size' => (string) strlen($data),
            'updated' => '2020-01-02T03:04:05Z',
        ];
    }

    public function name(): string
    {
        return $this->objectName;
    }

    public function acl(): MemoryAcl
    {
        return $this->acl;
    }

    public function exists(array $options = []): bool
    {
        if ($this->existsThrowable instanceof Throwable) {
            throw $this->existsThrowable;
        }

        return $this->bucket->hasObject($this->objectName);
    }

    public function info(array $options = []): array
    {
        if ($this->infoThrowable instanceof Throwable) {
            throw $this->infoThrowable;
        }

        return $this->info;
    }

    public function setInfo(array $info): void
    {
        $this->info = $info + $this->info;
    }

    public function downloadAsString(array $options = []): string
    {
        if ($this->downloadThrowable instanceof Throwable) {
            throw $this->downloadThrowable;
        }

        return $this->data;
    }

    public function downloadAsStream(array $options = []): StreamInterface
    {
        $this->downloadStreamOptions[] = $options;

        if ($this->downloadThrowable instanceof Throwable) {
            throw $this->downloadThrowable;
        }

        $stream = Utils::streamFor($this->data);

        if ($this->detachReturnsNull) {
            return new class ($stream) implements StreamInterface {
                use \GuzzleHttp\Psr7\StreamDecoratorTrait;

                public function __construct(StreamInterface $stream)
                {
                    $this->stream = $stream;
                }

                public function detach()
                {
                    return null;
                }
            };
        }

        return $stream;
    }

    public function delete(array $options = []): void
    {
        if (! $this->bucket->hasObject($this->objectName)) {
            throw new NotFoundException('Object not found');
        }

        $this->bucket->removeObject($this->objectName);
    }

    public function copy($destination, array $options = []): StorageObject
    {
        if (! $destination instanceof MemoryBucket) {
            throw new RuntimeException('Unexpected copy destination');
        }

        $name = $options['name'] ?? $this->objectName;
        $destination->seedObject($name, $this->data, $this->info);

        return $destination->object($name);
    }

    public function signedUrl($expires, array $options = []): string
    {
        if ($this->signedUrlThrowable instanceof Throwable) {
            throw $this->signedUrlThrowable;
        }

        $this->lastSignedExpiry = $expires instanceof \DateTimeInterface ? $expires : null;
        $this->lastSignedOptions = $options;

        return 'https://signed.example/' . $this->objectName;
    }
}
