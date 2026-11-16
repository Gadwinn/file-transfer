<?php

declare(strict_types=1);

namespace G\FileTransfer;

use G\FileTransfer\Exception\ConnectionExceptionInterface;
use G\FileTransfer\Exception\FileNotFoundExceptionInterface;
use G\FileTransfer\Exception\IOExceptionInterface;
use G\FileTransfer\ValueObject\RemoteResourceInterface;
use Generator;

/**
 * Governs structural management, mutation, and inspection of the remote
 * filesystem, independent of payload transfer.
 */
interface FilesystemOperationsInterface
{
    /**
     * Removes a single remote file.
     *
     * @throws FileNotFoundExceptionInterface When the path does not exist.
     * @throws IOExceptionInterface           When removal is denied or
     *                                        otherwise fails.
     * @throws ConnectionExceptionInterface   When the link is unavailable.
     */
    public function delete(string $remotePath): void;

    /**
     * Creates a directory at the given remote path.
     *
     * @throws IOExceptionInterface         When creation is denied or the
     *                                      path is otherwise unwritable.
     * @throws ConnectionExceptionInterface When the link is unavailable.
     */
    public function createDirectory(string $remotePath): void;

    /**
     * Removes a remote directory.
     *
     * When $recursive is false the implementation MUST refuse to remove a
     * non-empty directory and signal the failure via an
     * {@see IOExceptionInterface}. When true, all descendant files and
     * directories are removed first.
     *
     * @throws FileNotFoundExceptionInterface When the path does not exist.
     * @throws IOExceptionInterface           When the directory is
     *                                        non-empty without $recursive,
     *                                        or removal is denied.
     * @throws ConnectionExceptionInterface   When the link is unavailable.
     */
    public function deleteDirectory(string $remotePath, bool $recursive = false): void;

    /**
     * Reports whether a file or directory exists at the given remote path.
     *
     * @throws ConnectionExceptionInterface When the link is unavailable.
     */
    public function exists(string $remotePath): bool;

    /**
     * Lazily enumerates the immediate children of a remote directory.
     *
     * Implementations MUST yield results through a generator rather than
     * materializing them into an array, bounding memory usage when listing
     * dense directories that hold tens of thousands of entries.
     *
     * @param string $remotePath Directory to enumerate; defaults to the
     *                           transport root.
     *
     * @return Generator<int, RemoteResourceInterface> Lazy sequence of
     *                                                  direct children.
     *
     * @throws FileNotFoundExceptionInterface When the directory does not
     *                                        exist.
     * @throws IOExceptionInterface           When the directory cannot be
     *                                        read.
     * @throws ConnectionExceptionInterface   When the link is unavailable.
     */
    public function listDirectory(string $remotePath = '/'): Generator;
}
