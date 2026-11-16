<?php

declare(strict_types=1);

namespace G\FileTransfer\Driver;

use DateTimeImmutable;
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

use function clearstatcache;
use function closedir;
use function fclose;
use function file_exists;
use function fopen;
use function implode;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function opendir;
use function preg_split;
use function readdir;
use function realpath;
use function rmdir;
use function rtrim;
use function sprintf;
use function stat;
use function str_contains;
use function stream_copy_to_stream;
use function unlink;

/**
 * Local filesystem driver: the "remote" endpoint is a directory of the machine
 * (or of a mounted volume).
 *
 * It lets an application store its files locally in development and on FTP,
 * SFTP or S3 in production without changing a line of the calling code, and
 * makes code written against the library testable without any server.
 *
 * Every remote path is resolved inside the configured root directory: a path
 * that tries to climb out of it ("../") is rejected. Symbolic links found
 * inside the root are followed as the operating system does.
 *
 * Recognised configuration keys:
 *  - `root` (string, required) — existing directory holding the files
 */
final class LocalDriver implements UniversalTransferAdapterInterface
{
    private const PERMISSION_MASK = 0o7777;

    private const ROOT_PATH = '/';
    private const CURRENT_DIR = '.';
    private const PARENT_DIR = '..';
    private const PATH_SEPARATORS = '#[/\\\\]+#';

    private readonly string $configuredRoot;

    /** Absolute root directory, null while disconnected. */
    private ?string $root = null;

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException When required settings are missing or malformed.
     */
    public function __construct(array $config)
    {
        $root = $config['root'] ?? null;
        if (!is_string($root) || $root === '') {
            throw new ConfigurationException('Local configuration requires a non-empty "root" directory.');
        }

        $this->configuredRoot = $root;
    }

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $root = realpath($this->configuredRoot);
        if ($root === false || !is_dir($root)) {
            throw new ConnectionException(sprintf(
                'Local root directory "%s" does not exist or is not a directory.',
                $this->configuredRoot,
            ));
        }

        $this->root = rtrim($root, '/\\');
    }

    public function disconnect(): void
    {
        $this->root = null;
    }

    public function isConnected(): bool
    {
        return $this->root !== null;
    }

    public function uploadFile(string $localPath, string $remotePath): void
    {
        if (!is_file($localPath)) {
            throw new FileNotFoundException(sprintf('Local file "%s" does not exist.', $localPath));
        }

        $local = @fopen($localPath, 'rb');
        if ($local === false) {
            throw new IOException(sprintf('Unable to read local file "%s".', $localPath));
        }

        try {
            $this->uploadStream($local, $remotePath);
        } finally {
            fclose($local);
        }
    }

    public function uploadStream(mixed $sourceStream, string $remotePath): void
    {
        $remote = $this->open($remotePath, 'wb');
        try {
            $this->copyStream($sourceStream, $remote, $remotePath);
        } finally {
            fclose($remote);
        }
    }

    public function downloadToFile(string $remotePath, string $localPath): void
    {
        $this->requireFile($remotePath);

        $local = @fopen($localPath, 'wb');
        if ($local === false) {
            throw new IOException(sprintf('Unable to open local file "%s" for writing.', $localPath));
        }

        try {
            $this->downloadToStream($remotePath, $local);
        } finally {
            fclose($local);
        }
    }

    public function downloadToStream(string $remotePath, mixed $targetStream): void
    {
        $this->requireFile($remotePath);

        $remote = $this->open($remotePath, 'rb');
        try {
            $this->copyStream($remote, $targetStream, $remotePath);
        } finally {
            fclose($remote);
        }
    }

    public function delete(string $remotePath): void
    {
        $this->requireFile($remotePath);

        if (!@unlink($this->resolve($remotePath))) {
            throw new IOException(sprintf('Unable to delete remote file "%s".', $remotePath));
        }
    }

    public function createDirectory(string $remotePath): void
    {
        $path = $this->resolve($remotePath);

        if (!@mkdir($path) && !is_dir($path)) {
            throw new IOException(sprintf('Unable to create remote directory "%s".', $remotePath));
        }
    }

    public function deleteDirectory(string $remotePath, bool $recursive = false): void
    {
        $path = $this->resolve($remotePath);

        if (!is_dir($path)) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
        }

        if ($path === $this->root) {
            throw new IOException('The root directory itself cannot be removed.');
        }

        if ($recursive) {
            foreach ($this->listDirectory($remotePath) as $child) {
                if ($child->isDirectory()) {
                    $this->deleteDirectory($child->getPath(), true);
                } else {
                    $this->delete($child->getPath());
                }
            }
        }

        if (!@rmdir($path)) {
            throw new IOException(sprintf(
                'Unable to remove remote directory "%s"%s.',
                $remotePath,
                $recursive ? '' : ' (it may be non-empty; pass $recursive = true)',
            ));
        }
    }

    public function exists(string $remotePath): bool
    {
        $path = $this->resolve($remotePath);
        clearstatcache(true, $path);

        return file_exists($path);
    }

    /**
     * @return Generator<int, RemoteResourceInterface>
     */
    public function listDirectory(string $remotePath = '/'): Generator
    {
        $path = $this->resolve($remotePath);

        if (!is_dir($path)) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
        }

        $handle = @opendir($path);
        if ($handle === false) {
            throw new IOException(sprintf('Unable to open remote directory "%s".', $remotePath));
        }

        $base = rtrim($this->normalize($remotePath), '/');

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === self::CURRENT_DIR || $entry === self::PARENT_DIR) {
                    continue;
                }

                yield $this->describe($base . '/' . $entry, $entry);
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * Build a {@see RemoteResource} from a path's stat record.
     */
    private function describe(string $remotePath, string $name): RemoteResourceInterface
    {
        $path = $this->resolve($remotePath);
        clearstatcache(true, $path);

        $info = @stat($path);
        if ($info === false) {
            throw new IOException(sprintf('Unable to stat remote path "%s".', $remotePath));
        }

        $lastModified = (new DateTimeImmutable('@' . $info['mtime']))
            ->setTimezone(new DateTimeZone('UTC'));

        return new RemoteResource(
            $remotePath,
            $name,
            $info['size'],
            $lastModified,
            is_dir($path),
            is_file($path),
            new Permissions($info['mode'] & self::PERMISSION_MASK),
        );
    }

    /**
     * @throws FileNotFoundException When the remote path is not an existing file.
     */
    private function requireFile(string $remotePath): void
    {
        $path = $this->resolve($remotePath);
        clearstatcache(true, $path);

        if (!is_file($path)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }
    }

    /**
     * @return resource
     */
    private function open(string $remotePath, string $mode)
    {
        $handle = @fopen($this->resolve($remotePath), $mode);
        if ($handle === false) {
            throw new IOException(sprintf('Unable to open remote stream "%s".', $remotePath));
        }

        return $handle;
    }

    /**
     * @param resource $source
     * @param resource $destination
     */
    private function copyStream($source, $destination, string $context): void
    {
        if (@stream_copy_to_stream($source, $destination) === false) {
            throw new IOException(sprintf('Stream transfer failed for "%s".', $context));
        }
    }

    /**
     * Absolute path of a remote path inside the root directory.
     *
     * @throws ConnectionException When the driver is not connected.
     * @throws IOException         When the path tries to leave the root directory.
     */
    private function resolve(string $remotePath): string
    {
        if ($this->root === null) {
            throw new ConnectionException('The local driver is not connected; call connect() first.');
        }

        $normalized = $this->normalize($remotePath);

        return $normalized === self::ROOT_PATH ? $this->root : $this->root . $normalized;
    }

    /**
     * Canonical remote path: leading slash, single forward slashes, no "." segment.
     *
     * @throws IOException When the path contains a ".." segment or a null byte.
     */
    private function normalize(string $remotePath): string
    {
        $segments = [];

        foreach (preg_split(self::PATH_SEPARATORS, $remotePath) ?: [] as $segment) {
            if ($segment === '' || $segment === self::CURRENT_DIR) {
                continue;
            }

            if ($segment === self::PARENT_DIR || str_contains($segment, "\0")) {
                throw new IOException(sprintf('Remote path "%s" is outside of the root directory.', $remotePath));
            }

            $segments[] = $segment;
        }

        return self::ROOT_PATH . implode('/', $segments);
    }
}
