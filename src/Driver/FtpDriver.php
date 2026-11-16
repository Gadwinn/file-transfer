<?php

declare(strict_types=1);

namespace G\FileTransfer\Driver;

use DateTimeImmutable;
use DateTimeZone;
use FTP\Connection;
use G\FileTransfer\Exception\ConfigurationException;
use G\FileTransfer\Exception\ConnectionException;
use G\FileTransfer\Exception\FileNotFoundException;
use G\FileTransfer\Exception\IOException;
use G\FileTransfer\UniversalTransferAdapterInterface;
use G\FileTransfer\ValueObject\Permissions;
use G\FileTransfer\ValueObject\RemoteResource;
use G\FileTransfer\ValueObject\RemoteResourceInterface;
use Generator;
use Psr\Clock\ClockInterface;

use function extension_loaded;
use function ftp_chdir;
use function ftp_close;
use function ftp_connect;
use function ftp_delete;
use function ftp_fget;
use function ftp_fput;
use function ftp_get;
use function ftp_login;
use function ftp_mkdir;
use function ftp_pasv;
use function ftp_put;
use function ftp_pwd;
use function ftp_rawlist;
use function ftp_rmdir;
use function ftp_size;
use function ftp_ssl_connect;
use function function_exists;
use function in_array;
use function is_bool;
use function is_file;
use function is_int;
use function is_string;
use function preg_match;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_split;
use function str_starts_with;
use function strlen;
use function trim;

use const FTP_BINARY;

/**
 * FTP / FTPS driver built on PHP's native `ext-ftp` functions.
 *
 * The driver wraps the procedural `ftp_*` API behind the unified library
 * contract, translating every native warning or `false` return into the
 * strict exception hierarchy so that no transport-level fault leaks. Explicit
 * FTPS is enabled with the `ssl` flag, which routes the handshake through
 * {@see ftp_ssl_connect()}; passive mode is always negotiated after login to
 * stay compatible with NAT and modern firewalls.
 *
 * Timestamps parsed from directory listings are anchored to UTC through the
 * injected {@see ClockInterface}, which also supplies the reference year for
 * the time-only form of the Unix listing date (`MMM DD HH:MM`).
 *
 * Runtime requirement: the `ext-ftp` extension must be loaded; FTPS
 * additionally requires it to be built with OpenSSL support.
 *
 * Recognised configuration keys:
 *  - `host`     (string, required)
 *  - `port`     (int, optional, default 21)
 *  - `username` (string, required)
 *  - `password` (string, required — may be empty for anonymous access)
 *  - `timeout`  (int, optional, default 90 — connection timeout in seconds)
 *  - `ssl`      (bool, optional, default false — enable explicit FTPS)
 */
final class FtpDriver implements UniversalTransferAdapterInterface
{
    private const DEFAULT_PORT = 21;
    private const DEFAULT_TIMEOUT = 90;

    private const CURRENT_DIR = '.';
    private const PARENT_DIR = '..';
    private const TOTAL_PREFIX = 'total';

    private const TYPE_DIRECTORY = 'd';
    private const TYPE_FILE = '-';

    private const UTC = 'UTC';
    private const FORMAT_YEAR = 'Y';
    private const TIME_SEPARATOR = ':';
    private const LISTING_DATETIME_FORMAT = 'M j H:i Y';
    private const LISTING_DATE_FORMAT = 'M j Y H:i';
    private const MIDNIGHT_SUFFIX = ' 00:00';
    private const ONE_YEAR_EARLIER = '-1 year';
    private const WHITESPACE_PATTERN = '/\s+/';
    private const SINGLE_SPACE = ' ';

    /**
     * Captures a single Unix `ls -l` listing line into named groups:
     * file type, the nine permission symbols, byte size, the date column
     * (either `MMM DD YYYY` or `MMM DD HH:MM`) and the trailing entry name.
     */
    private const RAWLIST_PATTERN =
        '/^(?<type>[bcdlps\-])(?<perms>[rwxsStT\-]{9})[.+@]?\s+\d+\s+\S+\s+\S+\s+'
        . '(?<size>\d+)\s+(?<date>[A-Za-z]{3}\s+\d{1,2}\s+(?:\d{4}|\d{1,2}:\d{2}))\s+(?<name>.+)$/';

    private const SYMBOLIC_LENGTH = 9;

    /** @var list<int> Low permission bit carried by each of the nine symbols. */
    private const POSITION_BITS = [0o400, 0o200, 0o100, 0o040, 0o020, 0o010, 0o004, 0o002, 0o001];

    /** @var list<int> Symbol indexes that represent an execute column. */
    private const EXEC_POSITIONS = [2, 5, 8];

    /** @var list<string> Execute-column symbols that imply the execute bit. */
    private const EXEC_ACTIVE_CHARS = ['x', 's', 't'];

    private const SYMBOL_NONE = '-';

    private const SETUID_POSITION = 2;
    private const SETGID_POSITION = 5;
    private const STICKY_POSITION = 8;

    private const SETUID_BIT = 0o4000;
    private const SETGID_BIT = 0o2000;
    private const STICKY_BIT = 0o1000;

    /** @var list<string> Symbols marking a set-user/set-group-id bit. */
    private const SETID_CHARS = ['s', 'S'];

    /** @var list<string> Symbols marking the sticky bit. */
    private const STICKY_CHARS = ['t', 'T'];

    private readonly string $host;
    private readonly int $port;
    private readonly string $username;
    private readonly string $password;
    private readonly int $timeout;
    private readonly bool $ssl;
    private readonly ClockInterface $clock;

    private ?Connection $connection = null;

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException When required settings are missing or malformed.
     */
    public function __construct(array $config, ClockInterface $clock)
    {
        $host = $config['host'] ?? null;
        if (!is_string($host) || $host === '') {
            throw new ConfigurationException('FTP configuration requires a non-empty "host".');
        }
        $this->host = $host;

        $port = $config['port'] ?? self::DEFAULT_PORT;
        if (!is_int($port)) {
            throw new ConfigurationException('FTP "port" must be an integer.');
        }
        $this->port = $port;

        $username = $config['username'] ?? null;
        if (!is_string($username) || $username === '') {
            throw new ConfigurationException('FTP configuration requires a non-empty "username".');
        }
        $this->username = $username;

        $password = $config['password'] ?? null;
        if (!is_string($password)) {
            throw new ConfigurationException('FTP configuration requires a string "password".');
        }
        $this->password = $password;

        $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
        if (!is_int($timeout) || $timeout <= 0) {
            throw new ConfigurationException('FTP "timeout" must be a positive integer.');
        }
        $this->timeout = $timeout;

        $ssl = $config['ssl'] ?? false;
        if (!is_bool($ssl)) {
            throw new ConfigurationException('FTP "ssl" must be a boolean.');
        }
        $this->ssl = $ssl;

        $this->clock = $clock;
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

        if (!extension_loaded('ftp')) {
            throw new ConfigurationException(
                'The "ext-ftp" extension is required to use the FtpDriver but is not loaded.',
            );
        }

        if ($this->ssl && !function_exists('ftp_ssl_connect')) {
            throw new ConfigurationException(
                'FTPS was requested but "ftp_ssl_connect" is unavailable; ext-ftp was built without OpenSSL.',
            );
        }

        $connection = $this->ssl
            ? @ftp_ssl_connect($this->host, $this->port, $this->timeout)
            : @ftp_connect($this->host, $this->port, $this->timeout);

        if ($connection === false) {
            throw new ConnectionException(sprintf(
                'Unable to reach FTP%s host "%s:%d".',
                $this->ssl ? 'S' : '',
                $this->host,
                $this->port,
            ));
        }

        if (@ftp_login($connection, $this->username, $this->password) === false) {
            @ftp_close($connection);

            throw new ConnectionException(sprintf('FTP authentication failed for user "%s".', $this->username));
        }

        // Passive mode keeps data channels reachable through NAT and firewalls.
        @ftp_pasv($connection, true);

        $this->connection = $connection;
    }

    public function disconnect(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
        }

        $this->connection = null;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    public function uploadFile(string $localPath, string $remotePath): void
    {
        if (!is_file($localPath)) {
            throw new FileNotFoundException(sprintf('Local file "%s" does not exist.', $localPath));
        }

        $connection = $this->requireConnection();

        if (@ftp_put($connection, $this->normalize($remotePath), $localPath, FTP_BINARY) === false) {
            throw new IOException(sprintf('Unable to upload "%s" to remote "%s".', $localPath, $remotePath));
        }
    }

    public function uploadStream(mixed $sourceStream, string $remotePath): void
    {
        $this->sendStream($this->requireConnection(), $sourceStream, $this->normalize($remotePath));
    }

    public function downloadToFile(string $remotePath, string $localPath): void
    {
        $connection = $this->requireConnection();

        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        if (@ftp_get($connection, $localPath, $this->normalize($remotePath), FTP_BINARY) === false) {
            throw new IOException(sprintf('Unable to download remote "%s" to "%s".', $remotePath, $localPath));
        }
    }

    public function downloadToStream(string $remotePath, mixed $targetStream): void
    {
        $connection = $this->requireConnection();

        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        $this->receiveStream($connection, $targetStream, $this->normalize($remotePath));
    }

    public function delete(string $remotePath): void
    {
        $connection = $this->requireConnection();

        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote file "%s" does not exist.', $remotePath));
        }

        if (@ftp_delete($connection, $this->normalize($remotePath)) === false) {
            throw new IOException(sprintf('Unable to delete remote file "%s".', $remotePath));
        }
    }

    public function createDirectory(string $remotePath): void
    {
        $connection = $this->requireConnection();

        if (@ftp_mkdir($connection, $this->normalize($remotePath)) === false && !$this->exists($remotePath)) {
            throw new IOException(sprintf('Unable to create remote directory "%s".', $remotePath));
        }
    }

    public function deleteDirectory(string $remotePath, bool $recursive = false): void
    {
        $connection = $this->requireConnection();

        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
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

        if (@ftp_rmdir($connection, $this->normalize($remotePath)) === false) {
            throw new IOException(sprintf(
                'Unable to remove remote directory "%s"%s.',
                $remotePath,
                $recursive ? '' : ' (it may be non-empty; pass $recursive = true)',
            ));
        }
    }

    public function exists(string $remotePath): bool
    {
        $connection = $this->requireConnection();
        $path = $this->normalize($remotePath);

        if (@ftp_size($connection, $path) >= 0) {
            return true;
        }

        $current = @ftp_pwd($connection);
        if ($current === false) {
            return false;
        }

        if (@ftp_chdir($connection, $path)) {
            @ftp_chdir($connection, $current);

            return true;
        }

        return false;
    }

    /**
     * @return Generator<int, RemoteResourceInterface>
     */
    public function listDirectory(string $remotePath = '/'): Generator
    {
        $connection = $this->requireConnection();

        if (!$this->exists($remotePath)) {
            throw new FileNotFoundException(sprintf('Remote directory "%s" does not exist.', $remotePath));
        }

        $path = $this->normalize($remotePath);
        $base = rtrim($path, '/');

        $lines = @ftp_rawlist($connection, $path);
        if ($lines === false) {
            throw new IOException(sprintf('Unable to list remote directory "%s".', $remotePath));
        }

        foreach ($lines as $line) {
            $resource = $this->describe($line, $base);
            if ($resource !== null) {
                yield $resource;
            }
        }
    }

    /**
     * Parse a single raw Unix listing line into an immutable resource.
     *
     * Returns null for non-entry lines (the `total` header, `.`/`..`, or any
     * line whose format is not recognised), so the caller can skip them.
     */
    private function describe(string $line, string $basePath): ?RemoteResourceInterface
    {
        if (str_starts_with($line, self::TOTAL_PREFIX)) {
            return null;
        }

        if (preg_match(self::RAWLIST_PATTERN, $line, $matches) !== 1) {
            return null;
        }

        $name = $matches['name'];
        if ($name === self::CURRENT_DIR || $name === self::PARENT_DIR) {
            return null;
        }

        $type = $matches['type'];

        return new RemoteResource(
            $basePath . '/' . $name,
            $name,
            (int) $matches['size'],
            $this->parseListingDate($matches['date']),
            $type === self::TYPE_DIRECTORY,
            $type === self::TYPE_FILE,
            $this->permissionsFromSymbolic($matches['perms']),
        );
    }

    /**
     * Convert the listing date column into a UTC-anchored timestamp.
     *
     * The time-only form (`MMM DD HH:MM`) omits the year; it is resolved
     * against the injected clock, stepping back a year when the result would
     * otherwise fall in the future.
     */
    private function parseListingDate(string $rawDate): DateTimeImmutable
    {
        $utc = new DateTimeZone(self::UTC);
        $now = $this->clock->now()->setTimezone($utc);
        $normalized = preg_replace(self::WHITESPACE_PATTERN, self::SINGLE_SPACE, trim($rawDate)) ?? $rawDate;

        if (str_contains($normalized, self::TIME_SEPARATOR)) {
            $candidate = DateTimeImmutable::createFromFormat(
                self::LISTING_DATETIME_FORMAT,
                $normalized . self::SINGLE_SPACE . $now->format(self::FORMAT_YEAR),
                $utc,
            );

            if ($candidate === false) {
                return $now;
            }

            if ($candidate > $now) {
                $candidate = $candidate->modify(self::ONE_YEAR_EARLIER);
            }

            return $candidate;
        }

        $candidate = DateTimeImmutable::createFromFormat(
            self::LISTING_DATE_FORMAT,
            $normalized . self::MIDNIGHT_SUFFIX,
            $utc,
        );

        return $candidate === false ? $now : $candidate;
    }

    /**
     * Translate a nine-character symbolic permission string into a value
     * object. Falls back to {@see Permissions::none()} ("0000") when the
     * server does not expose a parseable mode.
     */
    private function permissionsFromSymbolic(string $symbolic): Permissions
    {
        if (strlen($symbolic) !== self::SYMBOLIC_LENGTH) {
            return Permissions::none();
        }

        $chars = str_split($symbolic);
        $mode = 0;

        foreach ($chars as $index => $char) {
            if ($char === self::SYMBOL_NONE) {
                continue;
            }

            // Uppercase S/T mark a special bit while leaving execute unset.
            if (in_array($index, self::EXEC_POSITIONS, true) && !in_array($char, self::EXEC_ACTIVE_CHARS, true)) {
                continue;
            }

            $mode |= self::POSITION_BITS[$index];
        }

        if (in_array($chars[self::SETUID_POSITION], self::SETID_CHARS, true)) {
            $mode |= self::SETUID_BIT;
        }
        if (in_array($chars[self::SETGID_POSITION], self::SETID_CHARS, true)) {
            $mode |= self::SETGID_BIT;
        }
        if (in_array($chars[self::STICKY_POSITION], self::STICKY_CHARS, true)) {
            $mode |= self::STICKY_BIT;
        }

        return new Permissions($mode);
    }

    /**
     * @param resource $stream Open, readable stream resource.
     */
    private function sendStream(Connection $connection, $stream, string $remotePath): void
    {
        if (@ftp_fput($connection, $remotePath, $stream, FTP_BINARY) === false) {
            throw new IOException(sprintf('Unable to upload stream to remote "%s".', $remotePath));
        }
    }

    /**
     * @param resource $stream Open, writable stream resource.
     */
    private function receiveStream(Connection $connection, $stream, string $remotePath): void
    {
        if (@ftp_fget($connection, $stream, $remotePath, FTP_BINARY) === false) {
            throw new IOException(sprintf('Unable to download remote "%s" into stream.', $remotePath));
        }
    }

    private function normalize(string $remotePath): string
    {
        return str_starts_with($remotePath, '/') ? $remotePath : '/' . $remotePath;
    }

    private function requireConnection(): Connection
    {
        if ($this->connection === null) {
            throw new ConnectionException('No active FTP session; call connect() first.');
        }

        return $this->connection;
    }
}
