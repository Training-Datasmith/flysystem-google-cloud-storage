# Architecture: flysystem-google-cloud-storage

## Purpose

A Flysystem adapter for Google Cloud Storage (GCS). It wraps the `google/cloud-storage` SDK and maps Flysystem visibility to GCS object ACLs, supporting both per-object ACLs and Uniform Bucket-Level Access (UBLA).

## Directory Structure

```
GoogleCloudStorageAdapter.php              — Primary adapter: CRUD and metadata via GCS Bucket/StorageObject
PortableVisibilityHandler.php              — Maps public/private to GCS ACL predicates (allUsers entity)
UniformBucketLevelAccessVisibility.php     — Visibility handler for buckets with UBLA enabled (no per-object ACLs)
VisibilityHandler.php                      — Interface for custom visibility strategies
StubStorageClient.php / StubRiggedBucket   — Test doubles for integration tests
GoogleCloudStorageAdapterTest.php          — Adapter contract tests
GoogleCloudStorageAdapterWithoutAclTest.php — Tests for UBLA (no-ACL) mode
```

## Key Design Decisions

- **ACL vs UBLA** — GCS supports two visibility models: per-object ACLs (default) and Uniform Bucket-Level Access. Inject `UniformBucketLevelAccessVisibility` when UBLA is enabled; the adapter then skips all ACL operations.
- **Streaming uploads** — `writeStream()` pipes content directly to GCS without buffering to memory, supporting large uploads.
- **Prefix-based virtual directories** — like S3, GCS has no native directories; the adapter uses key prefixes and `delimiter` parameters to emulate directory listing.
- **Metadata on upload** — content type, visibility ACL, and custom metadata are set atomically during the upload request.

## Extension Points

- Implement `VisibilityHandler` to customise public/private → ACL mapping.
- Pass a pre-configured `Bucket` instance with a custom HTTP client for proxy/retry scenarios.

## Dependency Flow

```
GoogleCloudStorageAdapter
  ├── Bucket (google/cloud-storage SDK)
  ├── VisibilityHandler (ACL or UBLA)
  └── PathPrefixer (key prefix management)
```
