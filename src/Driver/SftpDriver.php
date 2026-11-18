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

use function array_key_exists;
use function clearstatcache;
use function closedir;
use function extension_loaded;
use function fclose;
use function fopen;
use function get_resource_id;
use function hash_equals;
use function is_file;
use function is_int;
use function is_string;
use function lstat;
use function mkdir;
use function opendir;
use function readdir;
use function rmdir;
use function rtrim;
use function sprintf;
use function ssh2_auth_password;
use function ssh2_auth_pubkey_file;
use function ssh2_connect;
use function ssh2_disconnect;
use function ssh2_fingerprint;
use function ssh2_sftp;
use function stat;
use function str_replace;
use function str_starts_with;
use function stream_copy_to_stream;
use function strlen;
use function strtoupper;
use function unlink;

/**
 * SFTP driver built on the ext-ssh2 stream wrapper.
 *
 * Every filesystem and payload operation is routed through `ssh2.sftp://`
 * URLs of the form `ssh2.sftp://<sftp-resource-id><absolute-path>`, so the
 * transport reuses PHP's native stream toolkit (fopen, opendir, stat, ...)
 * while presenting the unified library contract to the consumer.
 *
 * Runtime requirement: the `ext-ssh2` extension must be loaded.
 *
 * Recognised configuration keys:
 *  - `host`        (string, required)
 *  - `port`        (int, optional, default 22)
 *  - `username`    (string, required)
 *  - `password`    (string, optional) — password authentication
 *  - `privateKey`  (string, optional) — path to the private key file
 *  - `publicKey`   (string, optional) — path to the public key file
 *  - `passphrase`  (string, optional) — passphrase protecting the private key
 *  - `fingerprint` (string, optional) — expected host key fingerprint, hex
 *                    (MD5, or SHA-1 when 40 digits long; colons ignored).
 *                    Without it the host key is NOT verified.
 *
 * Provide either `password`, or both `privateKey` and `publicKey`.
 */
final class SftpDriver implements UniversalTransferAdapterInterface
{
    private const DEFAULT_PORT = 22;

    private const SFTP_MODE = 0o7777;
    private const TYPE_MASK = 0o170000;
    private const TYPE_DIRECTORY = 0o040000;
    private const TYPE_FILE = 0o100000;
    private const TYPE_LINK = 0o120000;

    private const SHA1_HEX_LENGTH = 40;

    private const CURRENT_DIR = '.';
    private const PARENT_DIR = '..';

    private readonly string $host;
    private readonly int $port;
    private readonly string $username;
    private readonly ?string $password;
    private readonly ?string $privateKey;
    private readonly ?string $publicKey;
    private readonly ?string $passphrase;
    private readonly ?string $fingerprint;

    /** @var resource|null */
    private $session = null;

    /** @var resource|null */
    private $sftp = null;

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException When required settings are missing or malformed.
     */
    public function __construct(array $config)
    {
        $host = $config['host'] ?? null;
        if (!is_string($host) || $host === '') {
            throw new ConfigurationException('SFTP configuration requires a non-empty "host".');
        }
        $this->host = $host;

        $port = $config['port'] ?? self::DEFAULT_PORT;
        if (!is_int($port)) {
            throw new ConfigurationException('SFTP "port" must be an integer.');
        }
        $this->port = $port;

        $username = $config['username'] ?? null;
        if (!is_string($username) || $username === '') {
            throw new ConfigurationException('SFTP configuration requires a non-empty "username".');
        }
        $this->username = $username;

        $this->password = self::optionalString($config, 'password');
        $this->privateKey = self::optionalString($config, 'privateKey');
        $this->publicKey = self::optionalString($config, 'publicKey');
        $this->passphrase = self::optionalString($config, 'passphrase');
        $this->fingerprint = self::optionalString($config, 'fingerprint');

        $hasKeyPair = $this->privateKey !== null && $this->publicKey !== null;
        if ($this->password === null && !$hasKeyPair) {
            throw new ConfigurationException(
                'SFTP authentication requires a "password", or both "privateKey" and "publicKey".',
            );
        }

        if ($this->privateKey !== null && !is_file($this->privateKey)) {
            throw new ConfigurationException(sprintf('Private key file "%s" was not found.', $this->privateKey));
        }
        if ($this->publicKey !== null && !is_file($this->publicKey)) {
            throw new ConfigurationException(sprintf('Public key file "%s" was not found.', $this->publicKey));
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        if (!extension_loaded('ssh2')) {
            throw new ConfigurationException(
                'The "ext-ssh2" extension is required to use the SftpDriver but is not loaded.',
            );
        }

        $session = @ssh2_connect($this->host, $this->port);
        if ($session === false) {
            throw new ConnectionException(sprintf('Unable to reach SFTP host "%s:%d".', $this->host, $this->port));
        }

        try {
            $this->verifyFingerprint($session);
            $this->authenticate($session);

            $sftp = @ssh2_sftp($session);
            if ($sftp === false) {
                throw new ConnectionException('Unable to initialise the SFTP subsystem on the established session.');
            }
        } catch (ConnectionException | ConfigurationException $e) {
            @ssh2_disconnect($session);

            throw $e;
        }

        $this->session = $session;
        $this->sftp = $sftp;
    }

    public function disconnect(): void
    {
        if ($this->session !== null) {
            @ssh2_disconnect($this->session);
        }

        $this->sftp = null;
        $this->session = null;
    }

    public function isConnected(): bool
    {
        return $this->sftp !== null;
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
            $remote = $this->openRemote($remotePath, 'wb');
            try {
                $this->copyStream($local, $remote, $remotePath);
            } finally {
                fclose($remote);
            }
        } finally {
            fclose($local);
        }
    }

    public function uploadStream(mixed $sourceStream, string $remotePath): void
    {
        $remote = $this->openRemote($remotePath, 'wb');
        try {
            $this->copyStream($sourceStream, $remote, $remotePath);
        } finally {
            fclose($remote);
        }
    }

    public function downloadToFile(string $remotePath, string $localPath): void
    {
        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        $remote = $this->openRemote($remotePath, 'rb');
        try {
            $local = @fopen($localPath, 'wb');
            if ($local === false) {
                throw new IOException(sprintf('Unable to open local file "%s" for writing.', $localPath));
            }
            try {
                $this->copyStream($remote, $local, $remotePath);
            } finally {
                fclose($local);
            }
        } finally {
            fclose($remote);
        }
    }

    public function downloadToStream(string $remotePath, mixed $targetStream): void
    {
        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        $remote = $this->openRemote($remotePath, 'rb');
        try {
            $this->copyStream($remote, $targetStream, $remotePath);
        } finally {
            fclose($remote);
        }
    }

    public function delete(string $remotePath): void
    {
        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        if (!@unlink($this->url($remotePath))) {
            throw new IOException(sprintf('Unable to delete remote file "%s".', $remotePath));
        }
    }

    public function createDirectory(string $remotePath): void
    {
        if (!@mkdir($this->url($remotePath)) && !$this->exists($remotePath)) {
            throw new IOException(sprintf('Unable to create remote directory "%s".', $remotePath));
        }
    }

    public function deleteDirectory(string $remotePath, bool $recursive = false): void
    {
        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
        }

        $info = @lstat($this->url($remotePath));
        if ($info !== false && ($info['mode'] & self::TYPE_MASK) === self::TYPE_LINK) {
            throw new IOException(sprintf('Remote path "%s" is a symbolic link, not a directory.', $remotePath));
        }

        if ($recursive) {
            foreach ($this->listDirectory($remotePath) as $child) {
                if ($child->isDirectory()) {
                    $this->deleteDirectory($child->getPath(), true);
                } elseif (!@unlink($this->url($child->getPath()))) {
                    // Files and symbolic links alike: a link is removed, never followed.
                    throw new IOException(sprintf('Unable to delete remote entry "%s".', $child->getPath()));
                }
            }
        }

        if (!@rmdir($this->url($remotePath))) {
            throw new IOException(sprintf(
                'Unable to remove remote directory "%s"%s.',
                $remotePath,
                $recursive ? '' : ' (it may be non-empty; pass $recursive = true)',
            ));
        }
    }

    public function exists(string $remotePath): bool
    {
        $url = $this->url($remotePath);
        clearstatcache(true, $url);

        return @stat($url) !== false;
    }

    /**
     * @return Generator<int, RemoteResourceInterface>
     */
    public function listDirectory(string $remotePath = '/'): Generator
    {
        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
        }

        $base = rtrim($this->normalize($remotePath), '/');
        $handle = @opendir($this->url($remotePath));
        if ($handle === false) {
            throw new IOException(sprintf('Unable to open remote directory "%s".', $remotePath));
        }

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
     * Build a {@see RemoteResource} from a remote path's stat record.
     */
    private function describe(string $remotePath, string $name): RemoteResourceInterface
    {
        // lstat(): a symbolic link is described as itself (neither file nor
        // directory), so a recursive delete never follows it out of the tree.
        $info = @lstat($this->url($remotePath));
        if ($info === false) {
            throw new IOException(sprintf('Unable to stat remote path "%s".', $remotePath));
        }

        $mode = $info['mode'];
        $type = $mode & self::TYPE_MASK;

        $lastModified = (new DateTimeImmutable('@' . $info['mtime']))
            ->setTimezone(new DateTimeZone('UTC'));

        return new RemoteResource(
            $remotePath,
            $name,
            $info['size'],
            $lastModified,
            $type === self::TYPE_DIRECTORY,
            $type === self::TYPE_FILE,
            new Permissions($mode & self::SFTP_MODE),
        );
    }

    /**
     * @param resource $session
     */
    private function verifyFingerprint($session): void
    {
        if ($this->fingerprint === null) {
            return;
        }

        // The expected value selects the hash: 40 hex digits = SHA-1, otherwise MD5.
        $expected = $this->canonicalFingerprint($this->fingerprint);
        $hash = strlen($expected) === self::SHA1_HEX_LENGTH ? SSH2_FINGERPRINT_SHA1 : SSH2_FINGERPRINT_MD5;
        $actual = @ssh2_fingerprint($session, $hash | SSH2_FINGERPRINT_HEX);
        if ($actual === false) {
            throw new ConnectionException('Unable to read the remote host key fingerprint.');
        }

        if (
            !hash_equals($expected, $this->canonicalFingerprint($actual))
        ) {
            throw new ConnectionException('Remote host fingerprint mismatch; aborting connection for safety.');
        }
    }

    /**
     * @param resource $session
     */
    private function authenticate($session): void
    {
        if ($this->password !== null) {
            $authenticated = @ssh2_auth_password($session, $this->username, $this->password);
        } elseif ($this->privateKey !== null && $this->publicKey !== null) {
            $authenticated = @ssh2_auth_pubkey_file(
                $session,
                $this->username,
                $this->publicKey,
                $this->privateKey,
                $this->passphrase ?? '',
            );
        } else {
            throw new ConfigurationException('No usable SFTP authentication method was configured.');
        }

        if ($authenticated !== true) {
            throw new ConnectionException(sprintf('SFTP authentication failed for user "%s".', $this->username));
        }
    }

    /**
     * Open a remote stream through the ssh2.sftp wrapper.
     *
     * @return resource
     */
    private function openRemote(string $remotePath, string $mode)
    {
        $handle = @fopen($this->url($remotePath), $mode);
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
     * Compose the ssh2.sftp wrapper URL for a remote path.
     */
    private function url(string $remotePath): string
    {
        return 'ssh2.sftp://' . get_resource_id($this->requireSftp()) . $this->normalize($remotePath);
    }

    private function normalize(string $remotePath): string
    {
        return str_starts_with($remotePath, '/') ? $remotePath : '/' . $remotePath;
    }

    private function canonicalFingerprint(string $fingerprint): string
    {
        return strtoupper(str_replace(':', '', $fingerprint));
    }

    /**
     * @return resource
     */
    private function requireSftp()
    {
        if ($this->sftp === null) {
            throw new ConnectionException('No active SFTP session; call connect() first.');
        }

        return $this->sftp;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function optionalString(array $config, string $key): ?string
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new ConfigurationException(sprintf('SFTP "%s" must be a string when provided.', $key));
        }

        return $value;
    }
}
