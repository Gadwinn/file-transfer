# G\FileTransfer

A decoupled, driver-agnostic file-transfer library for PHP 8.3+. Your application codes against a single contract — `UniversalTransferAdapterInterface` — and stays identical whether the transport underneath is SFTP, FTP, FTPS, S3, the local filesystem, or any future driver. Swap protocols by changing one class reference, not your call sites.

The package ships with a production-ready **SFTP** driver built on the native `ssh2.sftp://` stream wrapper. Additional drivers are plain classes that implement the same contract and are dropped into the factory the same way.

## Tech Stack

| Component | Requirement |
|---|---|
| Language | PHP `>= 8.3` (`strict_types`, readonly properties, native enums-grade typing) |
| SFTP driver runtime | `ext-ssh2` (suggested — only needed when using `SftpDriver`) |
| Static guarantees | PSR-12 · PHPStan level max · zero magic numbers |
| Exceptions | Unified `TransferExceptionInterface` boundary over SPL |

The core (contracts, factory, facade, value objects) has **no runtime dependency** beyond PHP itself. `ext-ssh2` is required only at the moment you call `connect()` on the SFTP driver.

## Installation

```bash
composer require gadwinn/file-transfer
```

For the bundled SFTP driver, ensure the `ssh2` extension is available:

```bash
# Debian/Ubuntu
sudo apt-get install php-ssh2
```

## Usage

### 1. Quickstart — drop in a protocol driver

The `FileTransfer` facade takes a driver class and its configuration, and returns a fully wired adapter. Consumer code never references the concrete driver beyond this single line.

```php
use G\FileTransfer\FileTransfer;
use G\FileTransfer\Driver\SftpDriver;

$transfer = FileTransfer::create(SftpDriver::class, [
    'host'     => 'sftp.example.com',
    'port'     => 22,                 // optional, defaults to 22
    'username' => 'deploy',
    'password' => 's3cr3t',
]);

$transfer->connect();
$transfer->uploadFile('/local/report.pdf', '/remote/reports/report.pdf');
$transfer->disconnect();
```

Switching transport later is a one-token change — `SftpDriver::class` → `FtpDriver::class`, `S3Driver::class`, … — with the rest of the code untouched.

### 2. SFTP configuration keys

| Key | Type | Notes |
|---|---|---|
| `host` | `string` | **Required.** Non-empty hostname. |
| `port` | `int` | Optional, defaults to `22`. |
| `username` | `string` | **Required.** Non-empty. |
| `password` | `string` | Password authentication. |
| `privateKey` | `string` | Path to the private key file (use **with** `publicKey`). |
| `publicKey` | `string` | Path to the public key file. |
| `passphrase` | `string` | Passphrase protecting the private key. |
| `fingerprint` | `string` | Expected host MD5 fingerprint (hex). Verified on connect; mismatch aborts. |

Provide **either** `password`, **or both** `privateKey` and `publicKey`. Malformed wiring throws a `ConfigurationExceptionInterface` at construction time.

Key-pair authentication with host verification:

```php
$transfer = FileTransfer::create(SftpDriver::class, [
    'host'        => 'sftp.example.com',
    'username'    => 'deploy',
    'privateKey'  => '/home/deploy/.ssh/id_ed25519',
    'publicKey'   => '/home/deploy/.ssh/id_ed25519.pub',
    'passphrase'  => getenv('SSH_KEY_PASSPHRASE') ?: '',
    'fingerprint' => 'A1:B2:C3:D4:E5:F6:07:18:29:3A:4B:5C:6D:7E:8F:90',
]);
```

### 3. Transfers — files and streams

Path-based and stream-based operations are distinct methods, each strongly typed. Stream operands are open `resource` handles (the implementation never closes a caller-owned stream).

```php
// Upload / download by path
$transfer->uploadFile('/local/archive.tar.gz', '/remote/backups/archive.tar.gz');
$transfer->downloadToFile('/remote/backups/archive.tar.gz', '/local/restore.tar.gz');

// Stream an upload without buffering to disk
$source = fopen('php://temp', 'rb+');
fwrite($source, $payload);
rewind($source);
$transfer->uploadStream($source, '/remote/data/payload.bin');
fclose($source);

// Stream a download straight to the client
$target = fopen('php://output', 'wb');
$transfer->downloadToStream('/remote/data/payload.bin', $target);
fclose($target);
```

### 4. Remote filesystem operations

```php
$transfer->createDirectory('/remote/releases/2026-06-02');

if ($transfer->exists('/remote/releases/current')) {
    $transfer->delete('/remote/releases/current');
}

// Non-empty directories require explicit recursion
$transfer->deleteDirectory('/remote/releases/old', recursive: true);
```

### 5. Listing — lazy, strongly typed

`listDirectory()` returns a `Generator<int, RemoteResourceInterface>`, so listing a directory with tens of thousands of entries stays memory-bounded. Each item is an immutable value object — never a raw array or delimited string.

```php
foreach ($transfer->listDirectory('/remote/uploads') as $resource) {
    printf(
        "%s  %s  %8d bytes  %s  (modified %s UTC)\n",
        $resource->isDirectory() ? 'd' : '-',
        $resource->getPermissions()->toString(),   // canonical 4-digit octal, e.g. "0644"
        $resource->getSize(),
        $resource->getName(),
        $resource->getLastModified()->format('Y-m-d H:i:s'), // always UTC-anchored
    );
}
```

`RemoteResourceInterface` exposes: `getPath()`, `getName()`, `getSize()`, `getLastModified(): DateTimeImmutable` (always UTC), `isDirectory()`, `isFile()`, and `getPermissions(): PermissionsInterface`. `PermissionsInterface` provides `toString()` plus the nine `is{Readable,Writable,Executable}By{Owner,Group,Public}()` predicates.

### 6. Error handling — one unified boundary

Every throwable that escapes the public surface implements `TransferExceptionInterface`, so the entire library can be isolated behind a single catch — without coupling to driver-specific errors.

```php
use G\FileTransfer\Exception\TransferExceptionInterface;
use G\FileTransfer\Exception\ConfigurationExceptionInterface;
use G\FileTransfer\Exception\ConnectionExceptionInterface;
use G\FileTransfer\Exception\FileNotFoundExceptionInterface;
use G\FileTransfer\Exception\IOExceptionInterface;

try {
    $transfer->connect();
    $transfer->downloadToFile('/remote/missing.txt', '/local/missing.txt');
} catch (ConfigurationExceptionInterface $e) {
    // Missing/malformed config, unresolvable driver, or ext-ssh2 not loaded.
} catch (ConnectionExceptionInterface $e) {
    // Unreachable host, rejected credentials, fingerprint mismatch, dropped link.
} catch (FileNotFoundExceptionInterface $e) {
    // Remote (or local source) path does not exist.
} catch (IOExceptionInterface $e) {
    // Read/write failure on a healthy link.
} catch (TransferExceptionInterface $e) {
    // Catch-all for anything from the library.
} finally {
    $transfer->disconnect(); // safe no-op when not connected
}
```

### 7. Type-narrowing and custom factories

The facade returns the `UniversalTransferAdapterInterface` contract. When you want the IDE/static analyser to narrow to the concrete driver type, call the factory directly:

```php
use G\FileTransfer\Factory\TransferFactory;
use G\FileTransfer\Driver\SftpDriver;

$driver = (new TransferFactory())->create(SftpDriver::class, $config); // statically typed as SftpDriver
```

For dependency-injection containers or test doubles, swap the factory the facade uses. Drivers that need richer wiring (an injected clock, logger, or DI-managed collaborators) are constructed by your own `TransferFactoryInterface`:

```php
use G\FileTransfer\FileTransfer;
use G\FileTransfer\Factory\TransferFactoryInterface;
use G\FileTransfer\UniversalTransferAdapterInterface;

final class ContainerTransferFactory implements TransferFactoryInterface
{
    public function create(
        string $driverClass,
        array $config = [],
        mixed $collaborator = null,
    ): UniversalTransferAdapterInterface {
        // resolve dependencies from your container, then build the driver
        return new $driverClass($config, /* ...injected collaborators */);
    }
}

FileTransfer::setFactory(new ContainerTransferFactory());
// ... FileTransfer::setFactory(null); restores the default factory
```

### Writing another driver

Any transport — FTP, FTPS, S3, WebDAV — becomes a drop-in by implementing `UniversalTransferAdapterInterface` (which aggregates `ConnectionInterface`, `TransferInterface`, and `FilesystemOperationsInterface`). Translate every transport-native fault into the library's exception hierarchy, anchor returned timestamps to UTC, and the new class plugs into `FileTransfer::create()` exactly like `SftpDriver`.

## FTP and FTPS

The bundled `FtpDriver` speaks plain FTP and explicit FTPS through PHP's native `ext-ftp` extension. It always negotiates **passive mode** after login (for NAT/firewall compatibility), maps every `ftp_*` fault onto the unified exception boundary, and anchors listing timestamps to **UTC** via an injected `Psr\Clock\ClockInterface`.

Enable the extension:

```bash
# Debian/Ubuntu — ext-ftp ships with the standard PHP build; FTPS needs OpenSSL support
sudo apt-get install php-ftp
```

### Configuration keys

| Key | Type | Notes |
|---|---|---|
| `host` | `string` | **Required.** Non-empty hostname. |
| `port` | `int` | Optional, defaults to `21` (use `990` for implicit-style FTPS endpoints). |
| `username` | `string` | **Required.** Non-empty; use `anonymous` for public servers. |
| `password` | `string` | **Required**, but may be the empty string `''` for anonymous access. |
| `timeout` | `int` | Optional, defaults to `90`. Connection timeout in seconds; must be positive. |
| `ssl` | `bool` | Optional, defaults to `false`. `true` opens the control channel with `ftp_ssl_connect()` (explicit FTPS). |

### Construction — an injected clock

Unlike `SftpDriver`, the `FtpDriver` constructor takes a second argument: a `Psr\Clock\ClockInterface`. FTP directory listings report the time-of-day **without a year** for recent entries, so the driver resolves the year against the clock — and the clock keeps timestamps deterministic under test. Because of this extra dependency, build it directly (or through a custom factory; see *Type-narrowing and custom factories* above).

```php
use G\FileTransfer\Driver\FtpDriver;
use Psr\Clock\ClockInterface;

/** @var ClockInterface $clock — e.g. symfony/clock, lcobucci/clock, or your own */

$transfer = new FtpDriver([
    'host'     => 'ftp.example.com',
    'port'     => 21,
    'username' => 'deploy',
    'password' => 's3cr3t',
    'timeout'  => 90,
], $clock);

$transfer->connect();
$transfer->uploadFile('/local/report.pdf', '/reports/report.pdf');
$transfer->disconnect();
```

### Explicit FTPS

Flip a single flag to upgrade the control channel to TLS:

```php
$secure = new FtpDriver([
    'host'     => 'ftps.example.com',
    'port'     => 990,
    'username' => 'deploy',
    'password' => 's3cr3t',
    'ssl'      => true,   // ftp_ssl_connect() — explicit FTPS
], $clock);

$secure->connect();

foreach ($secure->listDirectory('/incoming') as $resource) {
    printf(
        "%s  %s  %8d bytes  %s  (modified %s UTC)\n",
        $resource->isDirectory() ? 'd' : '-',
        $resource->getPermissions()->toString(), // "0000" when the server hides permissions
        $resource->getSize(),
        $resource->getName(),
        $resource->getLastModified()->format('Y-m-d H:i:s'),
    );
}

$secure->disconnect();
```

Every method — transfers, filesystem operations, lazy `listDirectory()`, and the unified exception ladder shown earlier — behaves identically to the SFTP driver; only the construction differs.

### Wiring `FtpDriver` behind the facade

A driver collaborator (here the clock) is passed as the **optional third argument** to `FileTransfer::create()` — the factory forwards it as the constructor's second parameter, so no custom factory is needed:

```php
use G\FileTransfer\FileTransfer;
use G\FileTransfer\Driver\FtpDriver;

$transfer = FileTransfer::create(
    FtpDriver::class,
    ['host' => 'ftp.example.com', 'username' => 'deploy', 'password' => 's3cr3t'],
    $clock, // forwarded to new FtpDriver($config, $clock)
);
```

For container-managed wiring you can still register a custom `TransferFactoryInterface` (see *Type-narrowing and custom factories* above) — but for a single collaborator the third argument is all you need.

## Local filesystem

`LocalDriver` treats a directory of the machine (or of a mounted volume) as the remote endpoint. Use it to store files locally in development and on SFTP or S3 in production without changing the calling code, or to test code written against the library without any server. It needs no extension.

```php
use G\FileTransfer\Driver\LocalDriver;
use G\FileTransfer\FileTransfer;

$transfer = FileTransfer::create(LocalDriver::class, ['root' => '/var/app/storage']);

$transfer->connect();                                   // fails if the root directory does not exist
$transfer->createDirectory('/invoices');
$transfer->uploadFile('/tmp/invoice.pdf', '/invoices/2026-001.pdf');
$transfer->disconnect();
```

| Key | Type | Required | Description |
|---|---|---|---|
| `root` | `string` | yes | Existing directory that holds the files |

Every remote path is resolved **inside the root**: `/invoices/a.pdf`, `invoices/a.pdf` and `invoices\a.pdf` are the same file, and a path trying to climb out of the root (`../`) throws an `IOException`. Symbolic links located inside the root are followed as the operating system does. As with the other drivers, parent directories are not created implicitly.

## Amazon S3 (and S3-compatible)

The bundled `S3Driver` projects the unified contract onto S3's flat, stateless object model using the official `aws/aws-sdk-php`. It works against Amazon S3 and any S3-compatible endpoint (MinIO, Cloudflare R2, DigitalOcean Spaces, Ceph, …). "Directories" are emulated through the `/` key delimiter and zero-byte placeholder objects; object timestamps are anchored to **UTC**, and — since S3 exposes no POSIX mode — permissions always report `"0000"`.

Install the SDK alongside the library:

```bash
composer require aws/aws-sdk-php
```

### Configuration keys

| Key | Type | Notes |
|---|---|---|
| `bucket` | `string` | **Required.** Target bucket name. |
| `prefix` | `string` | Optional base key prefix scoping every operation (e.g. `tenants/acme`). A trailing `/` is added automatically. |

Region, endpoint, credentials and signature version are **not** part of this config — they belong to the injected `S3ClientInterface`, which the AWS SDK already models precisely.

### Construction — an injected S3 client

Like `FtpDriver`, the `S3Driver` constructor takes a collaborator as its second argument: a fully configured `Aws\S3\S3ClientInterface`. Injecting the client keeps credential/endpoint concerns in the SDK where they belong, and makes the driver trivially testable. `connect()` performs a lightweight `HeadBucket` probe, so a missing bucket or bad credentials surface immediately as a `ConnectionExceptionInterface`.

```php
use Aws\S3\S3Client;
use G\FileTransfer\Driver\S3Driver;

$client = new S3Client([
    'version'     => 'latest',
    'region'      => 'eu-west-3',
    'credentials' => [
        'key'    => getenv('AWS_ACCESS_KEY_ID') ?: '',
        'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
    ],
]);

$transfer = new S3Driver(['bucket' => 'acme-artifacts'], $client);

$transfer->connect();
$transfer->uploadFile('/local/report.pdf', '/reports/2026/q2.pdf');
$transfer->disconnect();
```

### S3-compatible endpoints

Point the SDK at any compatible gateway; the driver is unchanged:

```php
$client = new S3Client([
    'version'                 => 'latest',
    'region'                  => 'us-east-1',
    'endpoint'                => 'https://minio.internal:9000',
    'use_path_style_endpoint' => true, // required by most non-AWS gateways
    'credentials'             => ['key' => $accessKey, 'secret' => $secretKey],
]);

$transfer = new S3Driver(['bucket' => 'backups', 'prefix' => 'nightly'], $client);
$transfer->connect();

foreach ($transfer->listDirectory('/2026-06') as $resource) {
    printf(
        "%s  %8d bytes  %s  (modified %s UTC)\n",
        $resource->isDirectory() ? 'd' : '-',
        $resource->getSize(),
        $resource->getName(),
        $resource->getLastModified()->format('Y-m-d H:i:s'),
    );
}
```

Streaming, the lazy paginated `listDirectory()`, recursive `deleteDirectory('/path', recursive: true)`, and the unified exception ladder all behave exactly as with the other drivers — only the construction differs. To route through the facade, pass the client as the third argument to `FileTransfer::create()`:

```php
use G\FileTransfer\FileTransfer;
use G\FileTransfer\Driver\S3Driver;

$transfer = FileTransfer::create(S3Driver::class, ['bucket' => 'acme-artifacts'], $client);
```
