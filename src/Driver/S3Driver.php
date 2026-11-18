<?php

declare(strict_types=1);

namespace G\FileTransfer\Driver;

use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\Exception\ConnectionException;
use G\FileTransfer\Exception\FileNotFoundException;
use G\FileTransfer\Exception\IOException;
use G\FileTransfer\UniversalTransferAdapterInterface;
use G\FileTransfer\ValueObject\Permissions;
use G\FileTransfer\ValueObject\RemoteResource;
use G\FileTransfer\ValueObject\RemoteResourceInterface;
use Generator;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function is_array;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function ltrim;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

/**
 * Amazon S3 (and S3-compatible) driver built on `aws/aws-sdk-php`.
 *
 * The driver projects the unified library contract onto S3's flat, stateless
 * object model. Because S3 keeps no persistent connection, the lifecycle
 * methods are mapped pragmatically: {@see connect()} performs a `HeadBucket`
 * probe to validate the bucket and credentials up front (failing with a
 * {@see ConnectionException}), while {@see disconnect()} simply clears the
 * ready flag. "Directories" are emulated through the `/` key delimiter and
 * optional zero-byte placeholder objects, exactly as the AWS console does.
 *
 * The underlying {@see S3ClientInterface} is injected rather than built from
 * configuration: the SDK already models regions, endpoints, credentials and
 * signature versions far better than a flat array could, and injection keeps
 * the driver trivially mockable. This mirrors the "richer wiring" factory
 * pattern documented for drivers with collaborators.
 *
 * Object timestamps reported by S3 are anchored to UTC; synthetic directory
 * entries (which carry no modification time) report the Unix epoch. S3 does
 * not expose POSIX permissions, so every entry reports {@see Permissions::none()}
 * ("0000").
 *
 * Recognised configuration keys:
 *  - `bucket` (string, required) — the target bucket name
 *  - `prefix` (string, optional) — a base key prefix scoping every operation
 */
final class S3Driver implements UniversalTransferAdapterInterface
{
    private const SLASH = '/';
    private const UTC = 'UTC';
    private const UNKNOWN_EPOCH = '@0';
    private const PROBE_LIMIT = 1;
    private const EMPTY = '';

    private const CMD_HEAD_BUCKET = 'HeadBucket';
    private const CMD_PUT_OBJECT = 'PutObject';
    private const CMD_GET_OBJECT = 'GetObject';
    private const CMD_DELETE_OBJECT = 'DeleteObject';
    private const CMD_LIST_OBJECTS = 'ListObjectsV2';

    private const PARAM_BUCKET = 'Bucket';
    private const PARAM_KEY = 'Key';
    private const PARAM_PREFIX = 'Prefix';
    private const PARAM_DELIMITER = 'Delimiter';
    private const PARAM_BODY = 'Body';
    private const PARAM_SOURCE_FILE = 'SourceFile';
    private const PARAM_SAVE_AS = 'SaveAs';
    private const PARAM_MAX_KEYS = 'MaxKeys';
    private const PARAM_TOKEN = 'ContinuationToken';

    private const FIELD_CONTENTS = 'Contents';
    private const FIELD_COMMON_PREFIXES = 'CommonPrefixes';
    private const FIELD_KEY = 'Key';
    private const FIELD_PREFIX = 'Prefix';
    private const FIELD_SIZE = 'Size';
    private const FIELD_LAST_MODIFIED = 'LastModified';
    private const FIELD_IS_TRUNCATED = 'IsTruncated';
    private const FIELD_NEXT_TOKEN = 'NextContinuationToken';
    private const FIELD_BODY = 'Body';

    private readonly string $bucket;
    private readonly string $prefix;
    private readonly S3ClientInterface $client;

    private bool $connected = false;

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException When required settings are missing or malformed.
     */
    public function __construct(array $config, S3ClientInterface $client)
    {
        $bucket = $config['bucket'] ?? null;
        if (!is_string($bucket) || $bucket === self::EMPTY) {
            throw new ConfigurationException('S3 configuration requires a non-empty "bucket".');
        }
        $this->bucket = $bucket;

        $prefix = $config['prefix'] ?? self::EMPTY;
        if (!is_string($prefix)) {
            throw new ConfigurationException('S3 "prefix" must be a string when provided.');
        }
        $this->prefix = $prefix === self::EMPTY ? self::EMPTY : rtrim($prefix, self::SLASH) . self::SLASH;

        $this->client = $client;
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        try {
            $this->client->execute(
                $this->client->getCommand(self::CMD_HEAD_BUCKET, [self::PARAM_BUCKET => $this->bucket]),
            );
        } catch (AwsException $e) {
            throw new ConnectionException(
                sprintf('Unable to reach S3 bucket "%s": %s', $this->bucket, $this->reason($e)),
                0,
                $e,
            );
        }

        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function uploadFile(string $localPath, string $remotePath): void
    {
        if (!is_file($localPath)) {
            throw new FileNotFoundException(sprintf('Local file "%s" does not exist.', $localPath));
        }

        $this->requireConnection();

        $this->runCommand(self::CMD_PUT_OBJECT, [
            self::PARAM_BUCKET => $this->bucket,
            self::PARAM_KEY => $this->keyFor($remotePath),
            self::PARAM_SOURCE_FILE => $localPath,
        ]);
    }

    public function uploadStream(mixed $sourceStream, string $remotePath): void
    {
        $this->requireConnection();

        if (!is_resource($sourceStream)) {
            throw new IOException('The upload source must be an open, readable stream resource.');
        }

        // The SDK would wrap a raw resource in a stream that closes it once
        // destroyed: wrap it here, start at the current position, and detach
        // afterwards so the caller keeps an open stream.
        $source = Utils::streamFor($sourceStream);
        $position = $source->isSeekable() ? $source->tell() : 0;
        $body = $position > 0 ? new LimitStream($source, -1, $position) : $source;

        try {
            $this->runCommand(self::CMD_PUT_OBJECT, [
                self::PARAM_BUCKET => $this->bucket,
                self::PARAM_KEY => $this->keyFor($remotePath),
                self::PARAM_BODY => $body,
            ]);
        } finally {
            $source->detach();
        }
    }

    public function downloadToFile(string $remotePath, string $localPath): void
    {
        $this->requireConnection();

        if (!$this->objectExists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        $this->runCommand(self::CMD_GET_OBJECT, [
            self::PARAM_BUCKET => $this->bucket,
            self::PARAM_KEY => $this->keyFor($remotePath),
            self::PARAM_SAVE_AS => $localPath,
        ]);
    }

    public function downloadToStream(string $remotePath, mixed $targetStream): void
    {
        $this->requireConnection();

        if (!$this->objectExists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        if (!is_resource($targetStream)) {
            throw new IOException('The download target must be an open, writable stream resource.');
        }

        $result = $this->runCommand(self::CMD_GET_OBJECT, [
            self::PARAM_BUCKET => $this->bucket,
            self::PARAM_KEY => $this->keyFor($remotePath),
        ]);

        $body = $result->get(self::FIELD_BODY);
        if (!$body instanceof StreamInterface) {
            throw new IOException(sprintf('S3 returned no readable body for "%s".', $remotePath));
        }

        // Wrap the caller's resource only to copy into it, then detach so the
        // wrapper's destructor never closes a stream the caller still owns.
        $destination = Utils::streamFor($targetStream);
        try {
            Utils::copyToStream($body, $destination);
        } catch (RuntimeException $e) {
            throw new IOException(sprintf('Unable to write "%s" into the target stream.', $remotePath), 0, $e);
        } finally {
            $destination->detach();
        }
    }

    public function delete(string $remotePath): void
    {
        $this->requireConnection();

        if (!$this->objectExists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        $this->runCommand(self::CMD_DELETE_OBJECT, [
            self::PARAM_BUCKET => $this->bucket,
            self::PARAM_KEY => $this->keyFor($remotePath),
        ]);
    }

    public function createDirectory(string $remotePath): void
    {
        $this->requireConnection();

        $this->runCommand(self::CMD_PUT_OBJECT, [
            self::PARAM_BUCKET => $this->bucket,
            self::PARAM_KEY => $this->directoryKey($remotePath),
            self::PARAM_BODY => self::EMPTY,
        ]);
    }

    public function deleteDirectory(string $remotePath, bool $recursive = false): void
    {
        $this->requireConnection();

        // A directory is a key prefix: a file at this exact key is not one.
        if (!$this->prefixExists($this->directoryKey($remotePath))) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
        }

        if ($recursive) {
            $this->deleteByPrefix($this->directoryKey($remotePath));

            return;
        }

        if ($this->listDirectory($remotePath)->valid()) {
            throw new IOException(
                sprintf('Remote directory "%s" is not empty; pass $recursive = true.', $remotePath),
            );
        }

        // Drop the (now empty) directory placeholder, if one exists.
        $this->runCommand(self::CMD_DELETE_OBJECT, [
            self::PARAM_BUCKET => $this->bucket,
            self::PARAM_KEY => $this->directoryKey($remotePath),
        ]);
    }

    public function exists(string $remotePath): bool
    {
        $this->requireConnection();

        return $this->objectExists($remotePath) || $this->prefixExists($this->directoryKey($remotePath));
    }

    /**
     * @return Generator<int, RemoteResourceInterface>
     */
    public function listDirectory(string $remotePath = '/'): Generator
    {
        $this->requireConnection();

        if ($remotePath !== self::SLASH && !$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
        }

        $prefix = $this->listingPrefix($remotePath);
        $token = null;

        do {
            $result = $this->listObjects($prefix, true, $token);

            $commonPrefixes = $result->get(self::FIELD_COMMON_PREFIXES);
            if (is_array($commonPrefixes)) {
                foreach ($commonPrefixes as $entry) {
                    $resource = $this->directoryEntry($entry);
                    if ($resource !== null) {
                        yield $resource;
                    }
                }
            }

            $contents = $result->get(self::FIELD_CONTENTS);
            if (is_array($contents)) {
                foreach ($contents as $entry) {
                    $resource = $this->fileEntry($entry, $prefix);
                    if ($resource !== null) {
                        yield $resource;
                    }
                }
            }

            $token = $this->nextToken($result);
        } while ($token !== null);
    }

    /**
     * Whether an object exists at the exact key (a file, not a prefix).
     */
    private function objectExists(string $remotePath): bool
    {
        try {
            return $this->client->doesObjectExist($this->bucket, $this->keyFor($remotePath));
        } catch (AwsException $e) {
            throw new ConnectionException(
                sprintf('Unable to query S3 object "%s": %s', $remotePath, $this->reason($e)),
                0,
                $e,
            );
        }
    }

    /**
     * Whether at least one object lives under the given key prefix.
     */
    private function prefixExists(string $prefix): bool
    {
        try {
            $result = $this->client->execute($this->client->getCommand(self::CMD_LIST_OBJECTS, [
                self::PARAM_BUCKET => $this->bucket,
                self::PARAM_PREFIX => $prefix,
                self::PARAM_MAX_KEYS => self::PROBE_LIMIT,
            ]));
        } catch (AwsException $e) {
            throw new ConnectionException(
                sprintf('Unable to query S3 prefix "%s": %s', $prefix, $this->reason($e)),
                0,
                $e,
            );
        }

        $contents = $result->get(self::FIELD_CONTENTS);

        return is_array($contents) && $contents !== [];
    }

    /**
     * Delete every object that shares the given prefix, one page at a time.
     */
    private function deleteByPrefix(string $prefix): void
    {
        $token = null;

        do {
            $result = $this->listObjects($prefix, false, $token);

            $contents = $result->get(self::FIELD_CONTENTS);
            if (is_array($contents)) {
                foreach ($contents as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $key = $entry[self::FIELD_KEY] ?? null;
                    if (is_string($key)) {
                        $this->runCommand(self::CMD_DELETE_OBJECT, [
                            self::PARAM_BUCKET => $this->bucket,
                            self::PARAM_KEY => $key,
                        ]);
                    }
                }
            }

            $token = $this->nextToken($result);
        } while ($token !== null);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function runCommand(string $name, array $args): ResultInterface
    {
        try {
            return $this->client->execute($this->client->getCommand($name, $args));
        } catch (RuntimeException $e) {
            // AwsException, and the stream errors of SourceFile / SaveAs.
            throw new IOException(
                sprintf('S3 "%s" operation failed: %s', $name, $this->reason($e)),
                0,
                $e,
            );
        }
    }

    private function listObjects(string $prefix, bool $withDelimiter, ?string $token): ResultInterface
    {
        $args = [
            self::PARAM_BUCKET => $this->bucket,
            self::PARAM_PREFIX => $prefix,
        ];

        if ($withDelimiter) {
            $args[self::PARAM_DELIMITER] = self::SLASH;
        }

        if ($token !== null) {
            $args[self::PARAM_TOKEN] = $token;
        }

        return $this->runCommand(self::CMD_LIST_OBJECTS, $args);
    }

    private function nextToken(ResultInterface $result): ?string
    {
        if ($result->get(self::FIELD_IS_TRUNCATED) !== true) {
            return null;
        }

        $token = $result->get(self::FIELD_NEXT_TOKEN);

        return is_string($token) ? $token : null;
    }

    private function fileEntry(mixed $entry, string $prefix): ?RemoteResourceInterface
    {
        if (!is_array($entry)) {
            return null;
        }

        $key = $entry[self::FIELD_KEY] ?? null;
        if (!is_string($key) || $key === $prefix) {
            // Skip the directory placeholder object that represents $prefix itself.
            return null;
        }

        $size = $entry[self::FIELD_SIZE] ?? 0;

        return new RemoteResource(
            $this->pathFor($key),
            $this->basename($key),
            is_int($size) ? $size : 0,
            $this->toUtc($entry[self::FIELD_LAST_MODIFIED] ?? null),
            false,
            true,
            Permissions::none(),
        );
    }

    private function directoryEntry(mixed $entry): ?RemoteResourceInterface
    {
        if (!is_array($entry)) {
            return null;
        }

        $prefix = $entry[self::FIELD_PREFIX] ?? null;
        if (!is_string($prefix)) {
            return null;
        }

        $trimmed = rtrim($prefix, self::SLASH);

        return new RemoteResource(
            $this->pathFor($trimmed),
            $this->basename($trimmed),
            0,
            new DateTimeImmutable(self::UNKNOWN_EPOCH),
            true,
            false,
            Permissions::none(),
        );
    }

    private function toUtc(mixed $value): DateTimeImmutable
    {
        // The SDK returns Aws\Api\DateTimeResult, a mutable \DateTime.
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone(self::UTC));
        }

        return new DateTimeImmutable(self::UNKNOWN_EPOCH);
    }

    private function listingPrefix(string $remotePath): string
    {
        return $remotePath === self::SLASH ? $this->prefix : $this->directoryKey($remotePath);
    }

    private function keyFor(string $remotePath): string
    {
        return $this->prefix . ltrim($remotePath, self::SLASH);
    }

    private function directoryKey(string $remotePath): string
    {
        return rtrim($this->keyFor($remotePath), self::SLASH) . self::SLASH;
    }

    private function pathFor(string $key): string
    {
        $relative = ($this->prefix !== self::EMPTY && str_starts_with($key, $this->prefix))
            ? substr($key, strlen($this->prefix))
            : $key;

        return self::SLASH . ltrim($relative, self::SLASH);
    }

    private function basename(string $key): string
    {
        $trimmed = rtrim($key, self::SLASH);
        $position = strrpos($trimmed, self::SLASH);

        return $position === false ? $trimmed : substr($trimmed, $position + 1);
    }

    private function reason(RuntimeException $exception): string
    {
        return ($exception instanceof AwsException ? $exception->getAwsErrorMessage() : null)
            ?? $exception->getMessage();
    }

    private function requireConnection(): S3ClientInterface
    {
        if (!$this->connected) {
            throw new ConnectionException('No active S3 session; call connect() first.');
        }

        return $this->client;
    }
}
