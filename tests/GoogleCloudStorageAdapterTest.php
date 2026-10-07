<?php

declare(strict_types=1);

namespace League\Flysystem\GoogleCloudStorage\Tests;

use DateTimeImmutable;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\Acl;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\PortableVisibilityHandler;
use League\Flysystem\GoogleCloudStorage\Tests\Doubles\MemoryBucket;
use League\Flysystem\GoogleCloudStorage\Tests\Doubles\MemoryObject;
use League\Flysystem\GoogleCloudStorage\Tests\Doubles\ObjectListing;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\MimeTypeDetector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GoogleCloudStorageAdapterTest extends TestCase
{
    use CreatesAdapter;

    public function test_default_private_visibility_sets_project_private_acl(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket);

        $adapter->write('a.txt', 'hello', new Config());

        $this->assertSame('hello', $adapter->read('a.txt'));
        $this->assertSame('projectPrivate', $bucket->uploads[0]['options']['predefinedAcl']);
        $this->assertSame('a.txt', $bucket->uploads[0]['options']['name']);
    }

    public function test_default_visibility_public_sets_public_read_acl(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket, defaultVisibility: Visibility::PUBLIC);

        $adapter->write('a.txt', 'hello', new Config());

        $this->assertSame('publicRead', $bucket->uploads[0]['options']['predefinedAcl']);
    }

    public function test_prefix_is_applied_to_object_name(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket, prefix: 'ci');

        $adapter->write('dir/a.txt', 'payload', new Config());

        $this->assertSame('ci/dir/a.txt', $bucket->uploads[0]['options']['name']);
        $this->assertSame('payload', $adapter->read('dir/a.txt'));
    }

    public function test_explicit_metadata_content_type_is_kept(): void
    {
        $bucket = new MemoryBucket();
        $spy = new MimeTypeSpy();
        $adapter = $this->adapter($bucket, mimeTypeDetector: $spy);

        $adapter->write('note.txt', 'abc', new Config(['metadata' => ['contentType' => 'text/plain+special']]));

        $this->assertSame('text/plain+special', $bucket->uploads[0]['options']['metadata']['contentType']);
        $this->assertFalse($spy->wasCalled);
    }

    public function test_mime_detector_result_is_stored(): void
    {
        $bucket = new MemoryBucket();
        $spy = new MimeTypeSpy('application/x-test');
        $adapter = $this->adapter($bucket, mimeTypeDetector: $spy);

        $adapter->write('note.txt', 'abc', new Config());

        $this->assertSame('application/x-test', $bucket->uploads[0]['options']['metadata']['contentType']);
        $this->assertSame(['note.txt', 'abc'], $spy->lastCall);
    }

    public function test_empty_string_skips_mime_detection(): void
    {
        $bucket = new MemoryBucket();
        $spy = new MimeTypeSpy('application/x-test');
        $adapter = $this->adapter($bucket, mimeTypeDetector: $spy);

        $adapter->write('empty.txt', '', new Config());

        $this->assertFalse($spy->wasCalled);
        $this->assertArrayNotHasKey('contentType', $bucket->uploads[0]['options']['metadata']);
    }

    public function test_uniform_bucket_handler_omits_predefined_acl(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket, visibilityHandler: new UniformBucketLevelAccessVisibility());

        $adapter->write('a.txt', 'x', new Config());

        $this->assertArrayNotHasKey('predefinedAcl', $bucket->uploads[0]['options']);
    }

    public function test_write_stream_uploads_resource_bytes(): void
    {
        $bucket = new MemoryBucket();
        $spy = new MimeTypeSpy('text/plain');
        $adapter = $this->adapter($bucket, mimeTypeDetector: $spy);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'xyz');
        rewind($stream);

        $adapter->writeStream('stream.txt', $stream, new Config());

        $this->assertSame('xyz', $adapter->read('stream.txt'));
        $this->assertSame(['stream.txt', $stream], $spy->lastCall);
    }

    public function test_write_failure_throws_unable_to_write_file(): void
    {
        $bucket = new MemoryBucket();
        $bucket->failUpload('fail.txt');
        $adapter = $this->adapter($bucket);

        $this->expectException(UnableToWriteFile::class);
        $adapter->write('fail.txt', 'x', new Config());
    }

    public function test_public_url_uses_bucket_and_prefixed_path(): void
    {
        $bucket = new MemoryBucket('my-bucket');
        $adapter = $this->adapter($bucket, prefix: 'ci');

        $url = $adapter->publicUrl('dir/file.txt', new Config());

        $this->assertSame('https://storage.googleapis.com/my-bucket/ci/dir/file.txt', $url);
    }

    public function test_file_exists_true_false_and_prefixed_name(): void
    {
        $bucket = new MemoryBucket();
        $bucket->seedObject('ci/a.txt', 'data');
        $adapter = $this->adapter($bucket, prefix: 'ci');

        $this->assertTrue($adapter->fileExists('a.txt'));
        $this->assertFalse($adapter->fileExists('missing.txt'));
    }

    public function test_file_exists_wraps_client_errors(): void
    {
        $bucket = new MemoryBucket();
        $object = $bucket->seedObject('a.txt', 'data');
        $object->existsThrowable = new RuntimeException('boom');
        $adapter = $this->adapter($bucket);

        try {
            $adapter->fileExists('a.txt');
            $this->fail('Expected exception');
        } catch (UnableToCheckFileExistence $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_directory_exists_sends_delimiter_and_prefix(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket);

        $adapter->directoryExists('');
        $this->assertSame(
            ['delimiter' => '/', 'includeTrailingDelimiter' => true],
            $bucket->objectListCalls[0],
        );

        $adapter->directoryExists('dir');
        $this->assertSame(
            ['delimiter' => '/', 'includeTrailingDelimiter' => true, 'prefix' => 'dir/'],
            $bucket->objectListCalls[1],
        );

        $adapter = $this->adapter($bucket, prefix: 'ci');
        $adapter->directoryExists('');
        $this->assertSame(
            ['delimiter' => '/', 'includeTrailingDelimiter' => true, 'prefix' => 'ci/'],
            $bucket->objectListCalls[2],
        );

        $adapter->directoryExists('sub');
        $this->assertSame(
            ['delimiter' => '/', 'includeTrailingDelimiter' => true, 'prefix' => 'ci/sub/'],
            $bucket->objectListCalls[3],
        );
    }

    public function test_directory_exists_true_for_object_or_prefix(): void
    {
        $bucket = new MemoryBucket();
        $object = new MemoryObject($bucket, 'dir/file.txt', 'x');
        $bucket->setNextListing(new ObjectListing([$object]));
        $adapter = $this->adapter($bucket);
        $this->assertTrue($adapter->directoryExists('dir'));

        $bucket->setNextListing(new ObjectListing([], ['dir/sub/']));
        $this->assertTrue($adapter->directoryExists('dir'));
    }

    public function test_directory_exists_false_when_empty(): void
    {
        $bucket = new MemoryBucket();
        $bucket->setNextListing(new ObjectListing([]));
        $adapter = $this->adapter($bucket);

        $this->assertFalse($adapter->directoryExists('dir'));
    }

    public function test_directory_exists_wraps_client_errors(): void
    {
        $bucket = new MemoryBucket();
        $bucket->setObjectsThrowable(new RuntimeException('list failed'));
        $adapter = $this->adapter($bucket);

        $this->expectException(UnableToCheckDirectoryExistence::class);
        $adapter->directoryExists('dir');
    }

    public function test_read_stream_requests_http_stream_when_enabled(): void
    {
        $bucket = new MemoryBucket();
        $bucket->seedObject('a.txt', 'data');
        $adapter = $this->adapter($bucket, streamReads: true);

        $resource = $adapter->readStream('a.txt');
        $this->assertIsResource($resource);
        $this->assertSame('data', stream_get_contents($resource));

        $object = $bucket->object('a.txt');
        $this->assertSame([['restOptions' => ['stream' => true]]], $object->downloadStreamOptions);
    }

    public function test_read_stream_rejects_non_resource_detach(): void
    {
        $bucket = new MemoryBucket();
        $object = $bucket->seedObject('a.txt', 'data');
        $object->detachReturnsNull = true;
        $adapter = $this->adapter($bucket);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('Downloaded object does not contain a file resource.');
        $adapter->readStream('a.txt');
    }

    public function test_delete_missing_object_is_idempotent(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket);

        $adapter->delete('missing.txt');
        $adapter->delete('missing.txt');
        $this->addToAssertionCount(1);
    }

    public function test_delete_wraps_other_errors(): void
    {
        $bucket = new MemoryBucket();
        $bucket->failObject('a.txt', new RuntimeException('nope'));
        $adapter = $this->adapter($bucket);

        $this->expectException(UnableToDeleteFile::class);
        $adapter->delete('a.txt');
    }

    public function test_delete_directory_removes_nested_marker_with_trailing_slash(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket);

        $file = new MemoryObject($bucket, 'dir/a.txt', 'a');
        $marker = new MemoryObject($bucket, 'dir/sub/', '');
        $nested = new MemoryObject($bucket, 'dir/sub/b.txt', 'b');
        $bucket->seedObject('dir/', '');
        $bucket->seedObject('dir/a.txt', 'a');
        $bucket->seedObject('dir/sub/', '');
        $bucket->seedObject('dir/sub/b.txt', 'b');
        $bucket->setNextListing(new ObjectListing([$file, $marker, $nested]));

        $adapter->deleteDirectory('dir');

        $this->assertContains('dir/a.txt', $bucket->deletedObjectNames);
        $this->assertContains('dir/sub/b.txt', $bucket->deletedObjectNames);
        $this->assertContains('dir/sub/', $bucket->deletedObjectNames);
        $this->assertContains('dir/', $bucket->deletedObjectNames);
    }

    public function test_create_directory_uploads_empty_placeholder(): void
    {
        $bucket = new MemoryBucket();
        $adapter = $this->adapter($bucket);

        $adapter->createDirectory('foo', new Config());

        $this->assertSame('', $bucket->uploads[0]['data']);
        $this->assertSame('foo/', $bucket->uploads[0]['options']['name']);
        $this->assertArrayNotHasKey('predefinedAcl', $bucket->uploads[0]['options']);
    }

    public function test_create_directory_wraps_upload_failure(): void
    {
        $bucket = new MemoryBucket();
        $bucket->failUpload('foo/');
        $adapter = $this->adapter($bucket);

        $this->expectException(UnableToCreateDirectory::class);
        $adapter->createDirectory('foo', new Config());
    }

    public function test_set_visibility_public_then_private_round_trip(): void
    {
        $bucket = new MemoryBucket();
        $object = $bucket->seedObject('file.txt', 'x');
        $adapter = $this->adapter($bucket);

        $adapter->setVisibility('file.txt', Visibility::PUBLIC);
        $this->assertSame(Visibility::PUBLIC, $adapter->visibility('file.txt')->visibility());

        $adapter->setVisibility('file.txt', Visibility::PRIVATE);
        $this->assertSame(Visibility::PRIVATE, $adapter->visibility('file.txt')->visibility());
    }

    public function test_missing_acl_is_private(): void
    {
        $bucket = new MemoryBucket();
        $bucket->seedObject('file.txt', 'x');
        $adapter = $this->adapter($bucket);

        $attributes = $adapter->visibility('file.txt');
        $this->assertSame(Visibility::PRIVATE, $attributes->visibility());
        $this->assertNull($attributes->fileSize());
    }

    public function test_file_metadata_maps_info(): void
    {
        $bucket = new MemoryBucket();
        $bucket->seedObject('file.txt', 'hello', [
            'size' => '15',
            'contentType' => 'text/plain',
            'updated' => '2020-01-02T03:04:05Z',
            'custom' => 'value',
        ]);
        $adapter = $this->adapter($bucket);

        $size = $adapter->fileSize('file.txt');
        $this->assertSame(15, $size->fileSize());
        $this->assertSame('text/plain', $adapter->mimeType('file.txt')->mimeType());
        $this->assertSame(strtotime('2020-01-02T03:04:05Z'), $adapter->lastModified('file.txt')->lastModified());
        $this->assertSame('value', $size->extraMetadata()['custom']);
    }

    public function test_shallow_list_yields_root_relative_paths(): void
    {
        $bucket = new MemoryBucket();
        $file = new MemoryObject($bucket, 'dir/file.txt', 'x', ['size' => '1', 'contentType' => 'text/plain', 'updated' => '2020-01-02T03:04:05Z']);
        $bucket->setNextListing(new ObjectListing([$file], ['dir/sub/']));
        $adapter = $this->adapter($bucket);

        $items = iterator_to_array($adapter->listContents('dir', false), false);

        $this->assertSame(
            ['prefix' => 'dir/', 'delimiter' => '/', 'includeTrailingDelimiter' => true],
            $bucket->objectListCalls[0],
        );
        $this->assertInstanceOf(FileAttributes::class, $items[0]);
        $this->assertSame('dir/file.txt', $items[0]->path());
        $this->assertInstanceOf(DirectoryAttributes::class, $items[1]);
        $this->assertSame('dir/sub', $items[1]->path());
    }

    public function test_deep_list_yields_nested_file_path(): void
    {
        $bucket = new MemoryBucket();
        $nested = new MemoryObject($bucket, 'dir/sub/b.txt', 'b', ['size' => '1', 'contentType' => 'text/plain', 'updated' => '2020-01-02T03:04:05Z']);
        $bucket->setNextListing(new ObjectListing([$nested]));
        $adapter = $this->adapter($bucket);

        $items = iterator_to_array($adapter->listContents('dir', true), false);

        $this->assertSame(['prefix' => 'dir/'], $bucket->objectListCalls[0]);
        $this->assertSame('dir/sub/b.txt', $items[0]->path());
    }

    public function test_copy_retains_public_acl(): void
    {
        $bucket = new MemoryBucket();
        $source = $bucket->seedObject('a.txt', 'payload');
        $source->acl()->grantReader('allUsers');
        $adapter = $this->adapter($bucket);

        $adapter->copy('a.txt', 'b.txt', new Config());

        $this->assertTrue($bucket->hasObject('b.txt'));
        $this->assertTrue($bucket->hasObject('a.txt'));
    }

    public function test_copy_without_retain_visibility_omits_acl(): void
    {
        $bucket = new MemoryBucket();
        $source = $bucket->seedObject('a.txt', 'payload');
        $source->acl()->grantReader('allUsers');
        $adapter = $this->adapter($bucket);

        $adapter->copy('a.txt', 'b.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        $this->assertTrue($bucket->hasObject('b.txt'));
    }

    public function test_move_removes_source_after_copy(): void
    {
        $bucket = new MemoryBucket();
        $bucket->seedObject('a.txt', 'payload');
        $adapter = $this->adapter($bucket);

        $adapter->move('a.txt', 'b.txt', new Config());

        $this->assertFalse($bucket->hasObject('a.txt'));
        $this->assertTrue($bucket->hasObject('b.txt'));
        $this->assertSame('payload', $adapter->read('b.txt'));
    }

    public function test_checksum_returns_hard_coded_md5_hex(): void
    {
        $bucket = new MemoryBucket();
        $object = $bucket->seedObject('file.bin', '');
        $object->setInfo(['md5Hash' => '1B2M2Y8AsgTpgAmY7PhCfg==']);
        $adapter = $this->adapter($bucket);

        $this->assertSame(
            'd41d8cd98f00b204e9800998ecf8427e',
            $adapter->checksum('file.bin', new Config()),
        );
    }

    public function test_checksum_crc32c_and_etag_hex_literals(): void
    {
        $bucket = new MemoryBucket();
        $object = $bucket->seedObject('one.bin', 'x');
        $object->setInfo(['crc32c' => 'AAAAAA==', 'etag' => 'YWJj==']);
        $adapter = $this->adapter($bucket);

        $this->assertSame('00000000', $adapter->checksum('one.bin', new Config(['checksum_algo' => 'crc32c'])));
        $this->assertSame('616263', $adapter->checksum('one.bin', new Config(['checksum_algo' => 'etag'])));
    }

    public function test_checksum_unsupported_algo(): void
    {
        $bucket = new MemoryBucket();
        $bucket->seedObject('a.txt', 'x');
        $adapter = $this->adapter($bucket);

        $this->expectException(ChecksumAlgoIsNotSupported::class);
        $adapter->checksum('a.txt', new Config(['checksum_algo' => 'sha1']));
    }

    public function test_checksum_wraps_client_error(): void
    {
        $bucket = new MemoryBucket();
        $object = $bucket->seedObject('a.txt', 'x');
        $object->infoThrowable = new RuntimeException('info failed');
        $adapter = $this->adapter($bucket);

        $this->expectException(UnableToProvideChecksum::class);
        $adapter->checksum('a.txt', new Config());
    }

    public function test_temporary_url_forwards_signing_options(): void
    {
        $bucket = new MemoryBucket();
        $object = $bucket->seedObject('a.txt', 'x');
        $adapter = $this->adapter($bucket);
        $expires = new DateTimeImmutable('2030-01-01T00:00:00Z');

        $url = $adapter->temporaryUrl('a.txt', $expires, new Config(['gcp_signing_options' => ['version' => 'v4']]));

        $this->assertSame('https://signed.example/a.txt', $url);
        $this->assertSame(['version' => 'v4'], $object->lastSignedOptions);
    }

    public function test_filesystem_wraps_list_contents_errors(): void
    {
        $bucket = new MemoryBucket();
        $bucket->setObjectsThrowable(new RuntimeException('list failed'));
        $filesystem = new Filesystem(new GoogleCloudStorageAdapter($bucket));

        $this->expectException(UnableToListContents::class);
        iterator_to_array($filesystem->listContents('dir', false));
    }
}

final class MimeTypeSpy implements MimeTypeDetector
{
    public bool $wasCalled = false;

    /** @var array{0: string, 1: string}|null */
    public ?array $lastCall = null;

    public function __construct(private ?string $return = null)
    {
    }

    public function detectMimeType(string $path, $contents): ?string
    {
        $this->wasCalled = true;
        $this->lastCall = [$path, $contents];

        return $this->return;
    }

    public function detectMimeTypeFromPath(string $path): ?string
    {
        return null;
    }

    public function detectMimeTypeFromFile(string $path): ?string
    {
        return null;
    }

    public function detectMimeTypeFromBuffer(string $contents): ?string
    {
        return null;
    }
}
