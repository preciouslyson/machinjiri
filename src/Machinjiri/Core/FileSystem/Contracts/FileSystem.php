<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Contracts;

interface FileSystem
{
    /**
     * Read file contents as a string.
     *
     * @param string $path
     * @return string
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function read(string $path): string;

    /**
     * Read file contents as a stream resource.
     *
     * @param string $path
     * @return resource
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function readStream(string $path);

    /**
     * Write string contents to a file.
     *
     * @param string $path
     * @param string $contents
     * @param array $config
     * @return bool
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function write(string $path, string $contents, array $config = []): bool;

    /**
     * Write a stream resource to a file.
     *
     * @param string $path
     * @param resource $resource
     * @param array $config
     * @return bool
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function writeStream(string $path, $resource, array $config = []): bool;

    /**
     * Check if a file exists.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool;

    /**
     * Delete a file.
     *
     * @param string $path
     * @return bool
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function delete(string $path): bool;

    /**
     * Move a file.
     *
     * @param string $source
     * @param string $destination
     * @return bool
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function move(string $source, string $destination): bool;

    /**
     * Copy a file.
     *
     * @param string $source
     * @param string $destination
     * @return bool
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function copy(string $source, string $destination): bool;

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function listContents(string $directory = '', bool $recursive = false): array;

    /**
     * Get file size in bytes.
     *
     * @param string $path
     * @return int
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function size(string $path): int;

    /**
     * Get last modified timestamp.
     *
     * @param string $path
     * @return int
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function lastModified(string $path): int;

    /**
     * Get visibility (public/private).
     *
     * @param string $path
     * @return string
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function getVisibility(string $path): string;

    /**
     * Set visibility (public/private).
     *
     * @param string $path
     * @param string $visibility
     * @return bool
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    public function setVisibility(string $path, string $visibility): bool;
}