<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Adapters;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\FileSystem;

class FtpAdapter implements FileSystem
{
    protected $connection;
    protected array $config;
    protected string $root;

    /**
     * @throws MachinjiriException
     */
    public function __construct(array $config)
    {
        if (!extension_loaded('ftp')) {
            throw new MachinjiriException('FTP extension is required', 500);
        }

        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 21,
            'username' => 'anonymous',
            'password' => '',
            'root' => '/',
            'ssl' => false,
            'passive' => true,
            'timeout' => 90,
        ], $config);

        $this->root = rtrim($this->config['root'], '/') . '/';
        $this->connect();
    }

    /**
     * @throws MachinjiriException
     */
    protected function connect(): void
    {
        $function = $this->config['ssl'] ? 'ftp_ssl_connect' : 'ftp_connect';
        $this->connection = $function($this->config['host'], $this->config['port'], $this->config['timeout']);
        if (!$this->connection) {
            throw new MachinjiriException("Could not connect to FTP server: {$this->config['host']}", 500);
        }

        if (!ftp_login($this->connection, $this->config['username'], $this->config['password'])) {
            throw new MachinjiriException('FTP login failed', 401);
        }

        ftp_pasv($this->connection, $this->config['passive']);

        if (!empty($this->root) && !$this->changeDirectory($this->root)) {
            throw new MachinjiriException("FTP root directory does not exist: {$this->root}", 404);
        }
    }

    protected function changeDirectory(string $directory): bool
    {
        return @ftp_chdir($this->connection, $directory);
    }

    protected function absolute(string $path): string
    {
        return $this->root . ltrim($path, '/');
    }

    /**
     * @throws MachinjiriException
     */
    public function read(string $path): string
    {
        $remote = $this->absolute($path);
        $temp = tmpfile();
        $meta = stream_get_meta_data($temp);
        $tempPath = $meta['uri'];

        if (!ftp_get($this->connection, $tempPath, $remote, FTP_BINARY)) {
            fclose($temp);
            throw new MachinjiriException("Failed to read FTP file: {$path}", 500);
        }

        $content = file_get_contents($tempPath);
        fclose($temp);
        return $content;
    }

    /**
     * @throws MachinjiriException
     */
    public function readStream(string $path)
    {
        $remote = $this->absolute($path);
        $temp = fopen('php://temp', 'r+b');
        if (!ftp_fget($this->connection, $temp, $remote, FTP_BINARY)) {
            fclose($temp);
            throw new MachinjiriException("Failed to stream FTP file: {$path}", 500);
        }
        rewind($temp);
        return $temp;
    }

    /**
     * @throws MachinjiriException
     */
    public function write(string $path, string $contents, array $config = []): bool
    {
        $remote = $this->absolute($path);
        $temp = tmpfile();
        fwrite($temp, $contents);
        $meta = stream_get_meta_data($temp);
        $tempPath = $meta['uri'];
        $result = ftp_put($this->connection, $remote, $tempPath, FTP_BINARY);
        fclose($temp);
        if (!$result) {
            throw new MachinjiriException("Failed to write FTP file: {$path}", 500);
        }
        if (isset($config['visibility'])) {
            $this->setVisibility($path, $config['visibility']);
        }
        return true;
    }

    /**
     * @throws MachinjiriException
     */
    public function writeStream(string $path, $resource, array $config = []): bool
    {
        $remote = $this->absolute($path);
        if (!ftp_fput($this->connection, $remote, $resource, FTP_BINARY)) {
            throw new MachinjiriException("Failed to write stream to FTP file: {$path}", 500);
        }
        if (isset($config['visibility'])) {
            $this->setVisibility($path, $config['visibility']);
        }
        return true;
    }

    public function exists(string $path): bool
    {
        $remote = $this->absolute($path);
        $list = ftp_nlist($this->connection, dirname($remote));
        if ($list === false) {
            return false;
        }
        return in_array($remote, $list);
    }

    /**
     * @throws MachinjiriException
     */
    public function delete(string $path): bool
    {
        $remote = $this->absolute($path);
        if (!ftp_delete($this->connection, $remote)) {
            throw new MachinjiriException("Failed to delete FTP file: {$path}", 500);
        }
        return true;
    }

    /**
     * @throws MachinjiriException
     */
    public function move(string $source, string $destination): bool
    {
        $from = $this->absolute($source);
        $to = $this->absolute($destination);
        if (!ftp_rename($this->connection, $from, $to)) {
            throw new MachinjiriException("Failed to move FTP file from {$source} to {$destination}", 500);
        }
        return true;
    }

    /**
     * @throws MachinjiriException
     */
    public function copy(string $source, string $destination): bool
    {
        $content = $this->read($source);
        return $this->write($destination, $content);
    }

    /**
     * @throws MachinjiriException
     */
    public function listContents(string $directory = '', bool $recursive = false): array
    {
        $remote = $this->absolute($directory);
        $items = ftp_rawlist($this->connection, $remote, true);
        if ($items === false) {
            throw new MachinjiriException("Failed to list FTP directory: {$directory}", 500);
        }
        $results = [];
        foreach ($items as $item) {
            $chunks = preg_split('/\s+/', $item, 9);
            if (count($chunks) < 9) continue;
            $name = $chunks[8];
            if ($name === '.' || $name === '..') continue;
            $fullPath = ltrim($remote . '/' . $name, '/');
            $isDir = $chunks[0][0] === 'd';
            $results[] = [
                'type' => $isDir ? 'dir' : 'file',
                'path' => substr($fullPath, strlen($this->root)),
                'basename' => $name,
                'size' => (int)$chunks[4],
                'lastModified' => strtotime($chunks[5] . ' ' . $chunks[6] . ' ' . $chunks[7]),
            ];
            if ($recursive && $isDir && $name !== '.' && $name !== '..') {
                $results = array_merge($results, $this->listContents($fullPath, true));
            }
        }
        return $results;
    }

    /**
     * @throws MachinjiriException
     */
    public function size(string $path): int
    {
        $remote = $this->absolute($path);
        $size = ftp_size($this->connection, $remote);
        if ($size === -1) {
            throw new MachinjiriException("Failed to get FTP file size: {$path}", 500);
        }
        return $size;
    }

    /**
     * @throws MachinjiriException
     */
    public function lastModified(string $path): int
    {
        $remote = $this->absolute($path);
        $time = ftp_mdtm($this->connection, $remote);
        if ($time === -1) {
            throw new MachinjiriException("Failed to get FTP file modified time: {$path}", 500);
        }
        return $time;
    }

    /**
     * @throws MachinjiriException
     */
    public function getVisibility(string $path): string
    {
        // FTP does not have a simple visibility concept; default to 'public'
        return 'public';
    }

    /**
     * @throws MachinjiriException
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        // For FTP, we attempt to set Unix permissions (if supported)
        $remote = $this->absolute($path);
        $mode = $visibility === 'public' ? 0644 : 0600;
        if (!ftp_chmod($this->connection, $mode, $remote)) {
            throw new MachinjiriException("Failed to set FTP visibility for {$path}", 500);
        }
        return true;
    }

    public function __destruct()
    {
        if ($this->connection) {
            ftp_close($this->connection);
        }
    }
}