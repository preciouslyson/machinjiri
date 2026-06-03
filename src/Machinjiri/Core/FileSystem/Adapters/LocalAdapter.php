<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Adapters;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\FileSystem;

class LocalAdapter implements FileSystem
{
    protected string $root;

    /**
     * @throws MachinjiriException
     */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;

        if (!is_dir($this->root) && !mkdir($this->root, 0755, true)) {
            throw new MachinjiriException("Cannot create root directory: {$this->root}", 500);
        }
    }

    protected function absolute(string $path): string
    {
        return $this->root . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), '\\/');
    }

    protected function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new MachinjiriException("Cannot create directory: {$dir}", 500);
        }
    }

    /**
     * Override read-only permissions to make a file writable (owner write).
     * Does nothing if the file does not exist or is already writable.
     *
     * @throws MachinjiriException
     */
    protected function ensureWritable(string $absolutePath): void
    {
        if (!is_file($absolutePath)) {
            return;
        }

        if (!is_writable($absolutePath)) {
            $perms = fileperms($absolutePath);
            if ($perms === false) {
                throw new MachinjiriException("Cannot get permissions for file: {$absolutePath}", 500);
            }

            // Add owner write permission (0x0080)
            $newPerms = $perms | 0x0080;
            if (!chmod($absolutePath, $newPerms)) {
                throw new MachinjiriException("Cannot make file writable: {$absolutePath}", 500);
            }
        }
    }

    public function read(string $path): string
    {
        $abs = $this->absolute($path);
        if (!is_file($abs)) {
            throw new MachinjiriException("File does not exist: {$path}", 404);
        }
        $content = file_get_contents($abs);
        if ($content === false) {
            throw new MachinjiriException("Failed to read file: {$path}", 500);
        }
        return $content;
    }

    public function readStream(string $path)
    {
        $abs = $this->absolute($path);
        if (!is_file($abs)) {
            throw new MachinjiriException("File does not exist: {$path}", 404);
        }
        $stream = fopen($abs, 'rb');
        if ($stream === false) {
            throw new MachinjiriException("Failed to open stream for file: {$path}", 500);
        }
        return $stream;
    }

    public function write(string $path, string $contents, array $config = []): bool
    {
        $abs = $this->absolute($path);
        $this->ensureDirectory(dirname($abs));

        // If file already exists and is read-only, make it writable
        if (is_file($abs)) {
            $this->ensureWritable($abs);
        }

        if (file_put_contents($abs, $contents, LOCK_EX) === false) {
            throw new MachinjiriException("Failed to write file: {$path}", 500);
        }

        if (isset($config['visibility'])) {
            $this->setVisibility($path, $config['visibility']);
        }
        return true;
    }

    public function writeStream(string $path, $resource, array $config = []): bool
    {
        $abs = $this->absolute($path);
        $this->ensureDirectory(dirname($abs));

        // If file already exists and is read-only, make it writable
        if (is_file($abs)) {
            $this->ensureWritable($abs);
        }

        $dest = fopen($abs, 'wb');
        if ($dest === false) {
            throw new MachinjiriException("Failed to open destination stream: {$path}", 500);
        }
        $result = stream_copy_to_stream($resource, $dest);
        fclose($dest);
        if ($result === false) {
            throw new MachinjiriException("Failed to write stream to file: {$path}", 500);
        }

        if (isset($config['visibility'])) {
            $this->setVisibility($path, $config['visibility']);
        }
        return true;
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function delete(string $path): bool
    {
        $abs = $this->absolute($path);
        if (!is_file($abs)) {
            throw new MachinjiriException("File does not exist: {$path}", 404);
        }

        // Override read-only permissions to allow deletion
        $this->ensureWritable($abs);

        if (!unlink($abs)) {
            throw new MachinjiriException("Failed to delete file: {$path}", 500);
        }
        return true;
    }

    public function move(string $source, string $destination): bool
    {
        $sourceAbs = $this->absolute($source);
        $destAbs = $this->absolute($destination);

        if (!is_file($sourceAbs)) {
            throw new MachinjiriException("Source file does not exist: {$source}", 404);
        }

        $this->ensureDirectory(dirname($destAbs));

        // If destination already exists and is read-only, make it writable
        if (is_file($destAbs)) {
            $this->ensureWritable($destAbs);
        }

        if (!rename($sourceAbs, $destAbs)) {
            throw new MachinjiriException("Failed to move from {$source} to {$destination}", 500);
        }
        return true;
    }

    public function copy(string $source, string $destination): bool
    {
        $sourceAbs = $this->absolute($source);
        $destAbs = $this->absolute($destination);

        if (!is_file($sourceAbs)) {
            throw new MachinjiriException("Source file does not exist: {$source}", 404);
        }

        $this->ensureDirectory(dirname($destAbs));

        // If destination already exists and is read-only, make it writable
        if (is_file($destAbs)) {
            $this->ensureWritable($destAbs);
        }

        if (!copy($sourceAbs, $destAbs)) {
            throw new MachinjiriException("Failed to copy from {$source} to {$destination}", 500);
        }
        return true;
    }

    public function listContents(string $directory = '', bool $recursive = false): array
    {
        $abs = $this->absolute($directory);
        if (!is_dir($abs)) {
            return [];
        }

        $results = [];
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \RecursiveDirectoryIterator::SKIP_DOTS))
            : new \DirectoryIterator($abs);

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = $this->relative($item->getPathname());
            if ($item->isFile()) {
                $results[] = [
                    'type' => 'file',
                    'path' => $relative,
                    'basename' => $item->getBasename(),
                    'size' => $item->getSize(),
                    'lastModified' => $item->getMTime(),
                ];
            } elseif ($item->isDir() && !$recursive && $directory !== '') {
                $results[] = [
                    'type' => 'dir',
                    'path' => $relative,
                    'basename' => $item->getBasename(),
                ];
            }
        }
        return $results;
    }

    public function size(string $path): int
    {
        $abs = $this->absolute($path);
        if (!is_file($abs)) {
            throw new MachinjiriException("File does not exist: {$path}", 404);
        }
        $size = filesize($abs);
        if ($size === false) {
            throw new MachinjiriException("Failed to get file size: {$path}", 500);
        }
        return $size;
    }

    public function lastModified(string $path): int
    {
        $abs = $this->absolute($path);
        if (!is_file($abs)) {
            throw new MachinjiriException("File does not exist: {$path}", 404);
        }
        $mtime = filemtime($abs);
        if ($mtime === false) {
            throw new MachinjiriException("Failed to get last modified time: {$path}", 500);
        }
        return $mtime;
    }

    public function getVisibility(string $path): string
    {
        $abs = $this->absolute($path);
        if (!is_file($abs)) {
            throw new MachinjiriException("File does not exist: {$path}", 404);
        }
        $perms = fileperms($abs);
        return ($perms & 0x0040) ? 'public' : 'private';
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        $abs = $this->absolute($path);
        if (!is_file($abs)) {
            throw new MachinjiriException("File does not exist: {$path}", 404);
        }
        $mode = $visibility === 'public' ? 0644 : 0600;
        if (!chmod($abs, $mode)) {
            throw new MachinjiriException("Failed to set visibility for {$path}", 500);
        }
        return true;
    }

    protected function relative(string $absolute): string
    {
        return substr($absolute, strlen($this->root));
    }

}