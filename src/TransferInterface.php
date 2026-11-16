<?php

declare(strict_types=1);

namespace G\FileTransfer;

use G\FileTransfer\Exception\ConnectionExceptionInterface;
use G\FileTransfer\Exception\FileNotFoundExceptionInterface;
use G\FileTransfer\Exception\IOExceptionInterface;

/**
 * Governs bidirectional movement of payload data between the local
 * environment and the remote endpoint.
 *
 * Path-based and stream-based operations are split into distinct methods so
 * that every signature stays strongly typed. Stream operands are declared
 * `mixed` natively (PHP has no native `resource` type) and constrained to an
 * open stream resource through `@param resource` annotations, eliminating
 * the `string|resource` ambiguity entirely.
 */
interface TransferInterface
{
    /**
     * Uploads a local file, addressed by path, to the remote endpoint.
     *
     * @throws FileNotFoundExceptionInterface When the local source path does
     *                                        not exist.
     * @throws IOExceptionInterface           When the source cannot be read
     *                                        or the destination cannot be
     *                                        written.
     * @throws ConnectionExceptionInterface   When the link is unavailable or
     *                                        drops mid-transfer.
     */
    public function uploadFile(string $localPath, string $remotePath): void;

    /**
     * Uploads the contents of an open stream to the remote endpoint.
     *
     * The implementation reads from the stream at its current position until
     * EOF and MUST NOT close a caller-owned stream.
     *
     * @param resource $sourceStream Open, readable stream resource.
     *
     * @throws IOExceptionInterface         When the stream cannot be read or
     *                                      the destination cannot be written.
     * @throws ConnectionExceptionInterface When the link is unavailable or
     *                                      drops mid-transfer.
     */
    public function uploadStream(mixed $sourceStream, string $remotePath): void;

    /**
     * Downloads a remote file into a local file addressed by path.
     *
     * @throws FileNotFoundExceptionInterface When the remote path does not
     *                                        exist.
     * @throws IOExceptionInterface           When the source cannot be read
     *                                        or the local target cannot be
     *                                        written.
     * @throws ConnectionExceptionInterface   When the link is unavailable or
     *                                        drops mid-transfer.
     */
    public function downloadToFile(string $remotePath, string $localPath): void;

    /**
     * Downloads a remote file into an open stream.
     *
     * The implementation writes to the stream at its current position and
     * MUST NOT close a caller-owned stream.
     *
     * @param resource $targetStream Open, writable stream resource.
     *
     * @throws FileNotFoundExceptionInterface When the remote path does not
     *                                        exist.
     * @throws IOExceptionInterface           When the source cannot be read
     *                                        or the stream cannot be written.
     * @throws ConnectionExceptionInterface   When the link is unavailable or
     *                                        drops mid-transfer.
     */
    public function downloadToStream(string $remotePath, mixed $targetStream): void;
}
