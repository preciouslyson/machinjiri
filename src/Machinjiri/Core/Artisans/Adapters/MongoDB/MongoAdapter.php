<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Adapters\MongoDB;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class MongoAdapter
{
    protected array $config;
    protected ?Client $client = null;
    protected ?Database $database = null;

    /**
     * MongoAdapter constructor.
     *
     * @param array $config MongoDB connection configuration.
     * Supported keys:
     * - uri (string): full MongoDB URI, or
     * - host (string), port (int), username (string), password (string)
     * - database (string): optional default database
     * - options (array): MongoDB client options
     * - driverOptions (array): MongoDB driver-specific options
     */
    public function __construct(array $config)
    {
        $this->config = $this->normalizeConfig($config);
    }

    /**
     * Connect to MongoDB and return the client.
     *
     * @return Client
     * @throws MachinjiriException
     */
    public function connect(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $uri = $this->config['uri'] ?? $this->buildUri();
        $options = $this->config['options'] ?? [];
        $driverOptions = $this->config['driverOptions'] ?? [];

        if (!class_exists(Client::class)) {
            throw new MachinjiriException("MongoDB Error: MongoDB PHP library is not available. Install the mongodb extension and mongodb/mongodb package.", 301);
        }

        try {
            $this->client = new Client($uri, $options, $driverOptions);
        } catch (\Throwable $e) {
            throw new MachinjiriException("MongoDB Error: Connection failed: " . $e->getMessage(), 302, $e);
        }

        if (!empty($this->config['database'])) {
            $this->database = $this->client->selectDatabase($this->config['database']);
        }

        return $this->client;
    }

    /**
     * Select a MongoDB database.
     *
     * @param string $database
     * @return Database
     * @throws MachinjiriException
     */
    public function selectDatabase(string $database): Database
    {
        $client = $this->connect();
        $this->database = $client->selectDatabase($database);
        return $this->database;
    }

    /**
     * Get the default or currently selected database.
     *
     * @return Database|null
     * @throws MachinjiriException
     */
    public function getDatabase(): ?Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        if (!empty($this->config['database'])) {
            return $this->selectDatabase($this->config['database']);
        }

        return null;
    }

    /**
     * Select a collection from the default or specified database.
     *
     * @param string $collection
     * @param string|null $database
     * @return Collection
     * @throws MachinjiriException
     */
    public function selectCollection(string $collection, ?string $database = null): Collection
    {
        $db = $database !== null ? $this->selectDatabase($database) : $this->getDatabase();
        if ($db === null) {
            throw new MachinjiriException("MongoDB Error: No database selected.", 303);
        }

        return $db->selectCollection($collection);
    }

    /**
     * Get the underlying MongoDB client.
     *
     * @return Client
     * @throws MachinjiriException
     */
    public function getClient(): Client
    {
        return $this->connect();
    }

    /**
     * Determine whether the adapter has an active connection.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->client !== null;
    }

    /**
     * Disconnect the current client.
     */
    public function disconnect(): void
    {
        $this->database = null;
        $this->client = null;
    }

    protected function normalizeConfig(array $config): array
    {
        $defaults = [
            'host' => '127.0.0.1',
            'port' => 27017,
            'username' => null,
            'password' => null,
            'database' => null,
            'uri' => null,
            'options' => [],
            'driverOptions' => [],
        ];

        $config = array_replace($defaults, $config);

        if (empty($config['uri']) && empty($config['host'])) {
            throw new MachinjiriException('MongoDB Error: Configuration requires a host or uri.', 300);
        }

        return $config;
    }

    protected function buildUri(): string
    {
        $username = $this->config['username'];
        $password = $this->config['password'];
        $host = $this->config['host'];
        $port = $this->config['port'];

        $auth = '';
        if ($username !== null && $password !== null) {
            $auth = rawurlencode($username) . ':' . rawurlencode($password) . '@';
        }

        return "mongodb://{$auth}{$host}:{$port}";
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
