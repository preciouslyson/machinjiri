<?php
namespace Mlangeni\Machinjiri\Core\Database;
use \PDO;
use \PDOStatement;
use \PDOException;
use MongoDB\Client;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Database\MysqlGrammer;

class DatabaseConnection
{
    private static $connection = null; // Can be PDO or MongoDB\Client
    private static ?array $config = null;

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() {
        throw new MachinjiriException("Cannot unserialize a singleton.");
    }

    /**
     * Set database configuration
     * 
     * @param array $config Configuration array with keys:
     *   - 'driver' (required): Database driver (mysql, pgsql, sqlite, mongodb, etc.)
     *   - Other driver-specific options:
     *        MySQL/PostgreSQL: host, port, dbname, username, password, charset
     *        SQLite: path
     *        MongoDB: host, port, username, password, dbname, options
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Get the database connection instance
     * 
     * @return PDO|Client
     * @throws MachinjiriException If configuration is incomplete or connection fails
     */
    public static function getInstance()
    {
        if (self::$connection === null) {
            if (self::$config === null) {
                throw new MachinjiriException("Database configuration not set. Call setConfig() first.");
            }

            $driver = self::$config['driver'] ?? null;
            if (!$driver) {
                throw new MachinjiriException("Database configuration must specify a 'driver'.");
            }

            switch ($driver) {
                case 'mysql':
                case 'pgsql':
                    self::$connection = self::createPdoConnection();
                    break;
                case 'sqlite':
                    self::$connection = self::createSqliteConnection();
                    break;
                case 'mongodb':
                    self::$connection = self::createMongoConnection();
                    break;
                default:
                    self::$connection = self::createCustomPdoConnection();
            }
        }

        return self::$connection;
    }

    /**
     * Create a PDO connection for MySQL/PostgreSQL
     */
    private static function createPdoConnection(): PDO
    {
        $required = ['host', 'database', 'username', 'password'];
        foreach ($required as $key) {
            if (!isset(self::$config[$key]) && $key !== 'password') {
                throw new MachinjiriException("Missing required configuration: {$key}");
            }
        }

        $host = self::$config['host'];
        $port = self::$config['port'] ?? (self::$config['driver'] === 'mysql' ? 3306 : 5432);
        $dbname = self::$config['database'];
        $username = self::$config['username'];
        $password = self::$config['password'] ?? '';
        $charset = self::$config['charset'] ?? 'utf8mb4';

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $options = array_replace(
            $defaultOptions,
            self::$config['options'] ?? []
        );

        $driver = self::$config['driver'];
        $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname}";
        if ($driver === 'mysql') {
            $dsn .= ";charset={$charset}";
        }

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new MachinjiriException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Create SQLite connection
     */
    private static function createSqliteConnection(): PDO
    {
        $path = __DIR__. "/../../../database/database.sqlite";
        if (!is_file($path)) {
          fopen($path, "w");
        }
        $dsn = "sqlite:{$path}";

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        $options = array_replace(
            $defaultOptions,
            self::$config['options'] ?? []
        );

        try {
            return new PDO($dsn, null, null, $options);
        } catch (PDOException $e) {
            throw new MachinjiriException("SQLite connection failed: " . $e->getMessage());
        }
    }

    /**
     * Create MongoDB connection
     */
    private static function createMongoConnection(): Client
    {
        if (!class_exists(Client::class)) {
            throw new MachinjiriException("MongoDB PHP driver not installed.");
        }

        $host = self::$config['host'] ?? 'localhost';
        $port = self::$config['port'] ?? 27017;
        $username = self::$config['username'] ?? null;
        $password = self::$config['password'] ?? null;

        $auth = '';
        if ($username && $password) {
            $auth = rawurlencode($username) . ':' . rawurlencode($password) . '@';
        }

        $uri = "mongodb://{$auth}{$host}:{$port}";
        
        try {
            return new Client(
                $uri,
                self::$config['options'] ?? [],
                self::$config['driverOptions'] ?? []
            );
        } catch (MongoDBException $e) {
            throw new MachinjiriException("MongoDB connection failed: " . $e->getMessage());
        }
    }

    /**
     * Create connection for other PDO drivers
     */
    private static function createCustomPdoConnection(): PDO
    {
        if (empty(self::$config['dsn'])) {
            throw new MachinjiriException("Custom PDO driver requires 'dsn' configuration.");
        }

        $username = self::$config['username'] ?? null;
        $password = self::$config['password'] ?? null;

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $options = array_replace(
            $defaultOptions,
            self::$config['options'] ?? []
        );

        try {
            return new PDO(
                self::$config['dsn'],
                $username,
                $password,
                $options
            );
        } catch (PDOException $e) {
            throw new MachinjiriException("PDO connection failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a prepared SQL statement
     * 
     * @throws MachinjiriException If called with non-PDO connection
     */
    public static function executeQuery(string $sql, array $params = []): PDOStatement
    {
        $conn = self::getInstance();
        if (!$conn instanceof PDO) {
            throw new MachinjiriException("executeQuery() only supports PDO connections.");
        }

        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new MachinjiriException("Query execution failed: " . $e->getMessage());
        }
    }
    
    public static function getDriver(): string
    {
        return self::$config['driver'] ?? '';
    }

    public static function getGrammar(): Grammar
    {
        $driver = self::getDriver();
        
        return match($driver) {
            'pgsql' => new PostgresGrammar(),
            default => new MySqlGrammar(),
        };
    }
    
}
