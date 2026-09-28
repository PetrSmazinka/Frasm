<?php

declare(strict_types=1);

namespace Core\DB;

use Core\Config\Config;
use Core\Exceptions\DatabaseException;
use InvalidArgumentException;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use Throwable;

/**
 * @file DB.php
 * @brief Database abstraction layer implementing the Singleton pattern over mysqli.
 */

/**
 * @class DB
 * @brief High-performance database client providing prepared statements, transactions, and CRUD helpers.
 *
 * One instance per named connection of `database.connections`, opened on first use:
 * DB::connection('blog') for an application database, DB::getInstance() (= DB::connection())
 * for the default connection (`database.default`), which holds the framework tables (frasm_*).
 * Every connection should use its own database user with rights on its database only.
 * Transactions never span connections.
 *
 * Automatically resolves database credentials from Core\Config\Config if not explicitly provided.
 * Translates low-level mysqli driver exceptions into framework-specific DatabaseException instances.
 */
class DB
{
    /**
     * @var string Valid connection name.
     */
    private const NAME = '/^[a-z0-9_]{1,64}$/D';

    /**
     * @var mysqli Active database connection instance.
     */
    protected mysqli $connection;

    /**
     * @var string Connection name (key of `database.connections`).
     */
    protected string $name = '';

    /**
     * @var string Name of the connected database.
     */
    protected string $database = '';

    /**
     * @var array<string, static> Open connections keyed by class and connection name (singleton subclassing).
     */
    private static array $instances = [];

    /**
     * @brief Protected constructor initializing the database connection.
     *
     * Enforces strict error reporting via mysqli_sql_exception.
     *
     * @param array{host?: string, user?: string, password?: string, database?: string, port?: int, charset?: string, sync_timezone?: bool}|null $config
     *        Explicit settings, or null to read those of the connection from Core\Config\Config.
     * @param string|null $name Connection name (null = the default connection).
     * @throws DatabaseException If the connection is unknown, or connecting or setting the charset fails.
     */
    protected function __construct(?array $config = null, ?string $name = null)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->name = $name ?? self::defaultName();
        $config ??= self::settings($this->name);
        $this->database = (string)($config['database'] ?? '');

        $host    = (string)($config['host'] ?? '127.0.0.1');
        $user     = (string)($config['user'] ?? '');
        $password = (string)($config['password'] ?? '');
        $database = (string)($config['database'] ?? '');
        $port     = (int)($config['port'] ?? 3306);
        $charset  = (string)($config['charset'] ?? 'utf8mb4');

        try {
            $this->connection = new mysqli($host, $user, $password, $database, $port);
            $this->connection->set_charset($charset);

            // Align SQL NOW()/CURRENT_TIMESTAMP with PHP's date() so both sides compare the same clock
            if ((bool)($config['sync_timezone'] ?? true)) {
                $this->connection->query("SET time_zone = '" . date('P') . "'");
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException(
                "Database connection error: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * @brief Prevent instance cloning (Singleton pattern).
     */
    protected function __clone()
    {
    }

    /**
     * @brief Prevent instance unserialization (Singleton pattern).
     *
     * @throws DatabaseException Always throws an exception upon deserialization attempt.
     */
    public function __wakeup(): void
    {
        throw new DatabaseException("Cannot unserialize a singleton instance.");
    }

    /**
     * @brief Returns the instance of the default connection.
     *
     * @param array{host?: string, user?: string, password?: string, database?: string, port?: int, charset?: string}|null $config
     *        Settings used when this call opens the default connection (tools, tests); null reads the configuration.
     * @return static
     * @throws DatabaseException If connection fails on initialization.
     */
    public static function getInstance(?array $config = null): static
    {
        $name = self::defaultName();
        return self::$instances[static::class . '#' . $name] ??= new static($config, $name);
    }

    /**
     * @brief Returns the instance of a named connection, opening it on first use.
     *
     * @param string|null $name Key of `database.connections`, or null for the default connection.
     * @return static
     * @throws DatabaseException If the connection is not configured or cannot be established.
     */
    public static function connection(?string $name = null): static
    {
        $name ??= self::defaultName();
        return self::$instances[static::class . '#' . $name] ??= new static(null, $name);
    }

    /**
     * @brief Returns the name of the default connection (`database.default`).
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return (string)Config::get('database.default', 'mysql');
    }

    /**
     * @brief Returns the configured connection names, the default connection first.
     *
     * @return list<string>
     */
    public static function connectionNames(): array
    {
        $names = array_map('strval', array_keys((array)Config::get('database.connections', [])));
        return array_values(array_unique([self::defaultName(), ...$names]));
    }

    /**
     * @brief Returns the settings of a connection in the form the constructor expects.
     *
     * @param string $name Connection name.
     * @return array{host: string, user: string, password: string, database: string, port: int, charset: string, sync_timezone: bool}
     * @throws DatabaseException If the name is invalid or the connection is not configured.
     */
    public static function settings(string $name): array
    {
        $settings = preg_match(self::NAME, $name) ? Config::get("database.connections.{$name}") : null;
        if (!is_array($settings)) {
            throw new DatabaseException("Unknown database connection '{$name}' (define it in database.connections).");
        }

        return [
            'host'          => (string)($settings['host'] ?? '127.0.0.1'),
            'user'          => (string)($settings['username'] ?? ''),
            'password'      => (string)($settings['password'] ?? ''),
            'database'      => (string)($settings['database'] ?? ''),
            'port'          => (int)($settings['port'] ?? 3306),
            'charset'       => (string)($settings['charset'] ?? 'utf8mb4'),
            'sync_timezone' => (bool)($settings['sync_timezone'] ?? true),
        ];
    }

    /**
     * @brief Returns the connection name of this instance.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @brief Returns the name of the connected database.
     *
     * @return string
     */
    public function database(): string
    {
        return $this->database;
    }

    /**
     * @brief Executes a parameterized SQL statement.
     *
     * Utilizes mysqli::execute_query available in PHP 8.2+.
     *
     * @param string $query SQL statement with '?' parameter markers.
     * @param array<int|string, mixed> $params Values to bind to query placeholders.
     * @return mysqli_result|bool Result object for queries producing a result set, boolean otherwise.
     * @throws DatabaseException On execution or syntax failure.
     */
    public function query(string $query, array $params = []): mysqli_result|bool
    {
        try {
            return $this->connection->execute_query($query, $params);
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException(
                "Query execution failed: " . $e->getMessage() . " [SQL: {$query}]",
                (int)$e->getCode(),
                $e,
                $query,
                $params
            );
        }
    }

    /**
     * @brief Executes a SELECT query and returns all matching rows.
     *
     * @param string $query SQL SELECT statement with '?' markers.
     * @param array<int|string, mixed> $params Values to bind to query placeholders.
     * @return array<int, array<string, mixed>> List of matching records as associative arrays.
     * @throws DatabaseException On execution failure.
     */
    public function select(string $query, array $params = []): array
    {
        $result = $this->query($query, $params);
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * @brief Executes a SELECT query and returns a single row or null.
     *
     * @param string $query SQL SELECT statement with '?' markers.
     * @param array<int|string, mixed> $params Values to bind to query placeholders.
     * @return array<string, mixed>|null Associative array containing row data or null if empty.
     * @throws DatabaseException On execution failure.
     */
    public function selectOne(string $query, array $params = []): ?array
    {
        $result = $this->query($query, $params);
        if ($result instanceof mysqli_result) {
            return $result->fetch_assoc() ?: null;
        }

        return null;
    }

    /**
     * @brief Executes a query and returns the first column of the first row (e.g. COUNT(*)).
     *
     * @param string $query SQL statement with '?' markers.
     * @param array<int|string, mixed> $params Values to bind to query placeholders.
     * @return mixed Scalar value, or null if the query returned no records.
     * @throws DatabaseException On execution failure.
     */
    public function selectValue(string $query, array $params = []): mixed
    {
        $result = $this->query($query, $params);
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_row();
            return $row[0] ?? null;
        }

        return null;
    }

    /**
     * @brief Inserts a record into the specified table using an associative array.
     *
     * Automatically escapes column names using backticks.
     *
     * @param string $table Target table name.
     * @param array<string, mixed> $data Key-value pairs [column => value].
     * @return int|string Generated auto-increment ID.
     * @throws InvalidArgumentException If data array is empty.
     * @throws DatabaseException On execution failure.
     */
    public function insert(string $table, array $data): int|string
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Cannot insert empty data array into table '{$table}'.");
        }

        $columns = array_keys($data);
        $escapedColumns = implode(', ', array_map(fn(string $col): string => $this->quoteIdentifier($col), $columns));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO " . $this->quoteIdentifier($table) . " ({$escapedColumns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));

        return $this->lastInsertId();
    }

    /**
     * @brief Updates records in the specified table using an associative array.
     *
     * @param string $table Target table name.
     * @param array<string, mixed> $data Key-value pairs to update [column => value].
     * @param string $where WHERE condition (e.g., 'id = ?').
     * @param array<int|string, mixed> $whereParams Values to bind into the WHERE clause.
     * @return int Number of affected rows.
     * @throws InvalidArgumentException If data array is empty.
     * @throws DatabaseException On execution failure.
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Cannot update table '{$table}' with empty data.");
        }

        $setParts = [];
        foreach (array_keys($data) as $column) {
            $escapedColumn = $this->quoteIdentifier((string)$column);
            $setParts[] = "{$escapedColumn} = ?";
        }
        $setClause = implode(', ',$setParts);

        $sql = "UPDATE " . $this->quoteIdentifier($table) . " SET {$setClause} WHERE {$where}";
        $params = array_merge(array_values($data),$whereParams);

        $this->query($sql,$params);

        return $this->affectedRows();
    }

    /**
     * @brief Deletes records from a table based on a condition.
     *
     * @param string $table Target table name.
     * @param string $where WHERE condition (e.g., 'status = ?').
     * @param array<int|string, mixed> $params Values to bind into the WHERE clause.
     * @return int Number of affected rows.
     * @throws DatabaseException On execution failure.
     */
    public function delete(string $table, string $where, array$params = []): int
    {
        $sql = "DELETE FROM " . $this->quoteIdentifier($table) . " WHERE {$where}";
        $this->query($sql,$params);

        return $this->affectedRows();
    }

    /**
     * @brief Starts a new database transaction.
     *
     * @return bool Returns true on success.
     * @throws DatabaseException If transaction fails to start.
     */
    public function begin(): bool
    {
        try {
            return $this->connection->begin_transaction();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("Failed to begin transaction: " . $e->getMessage(), (int)$e->getCode(),$e);
        }
    }

    /**
     * @brief Commits the active transaction.
     *
     * @return bool Returns true on success.
     * @throws DatabaseException If commit fails.
     */
    public function commit(): bool
    {
        try {
            return $this->connection->commit();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("Failed to commit transaction: " . $e->getMessage(), (int)$e->getCode(),$e);
        }
    }

    /**
     * @brief Rolls back the active transaction.
     *
     * @return bool Returns true on success.
     * @throws DatabaseException If rollback fails.
     */
    public function rollback(): bool
    {
        try {
            return $this->connection->rollback();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("Failed to rollback transaction: " . $e->getMessage(), (int)$e->getCode(),$e);
        }
    }

    /**
     * @brief Executes a callback within a managed transaction.
     *
     * Automatically commits on success and rolls back when an unhandled throwable is raised.
     *
     * @template T
     * @param callable(self): T $callback Action to execute within transaction scope.
     * @return T Value returned by the callback.
     * @throws Throwable Any exception thrown inside the callback or during transaction flow.
     */
    public function transactional(callable $callback): mixed
    {
        $this->begin();
        try {
            $result =$callback($this);$this->commit();
            return $result;
        } catch (Throwable $e) {$this->rollback();
            throw $e;
        }
    }

    /**
     * @brief Quotes a table or column identifier with backticks, escaping embedded backticks.
     *
     * @param string $identifier Identifier name (a 'schema.table' form is quoted per segment).
     * @return string Quoted identifier.
     */
    public function quoteIdentifier(string $identifier): string
    {
        return implode('.', array_map(
            fn(string $part): string => '`' . str_replace('`', '``', $part) . '`',
            explode('.', $identifier)
        ));
    }

    /**
     * @brief Escapes a string for raw SQL insertion (prefer prepared statements instead).
     *
     * @param string $variable String to escape.
     * @return string Escaped string.
     */
    public function escape(string $variable): string
    {
        return $this->connection->real_escape_string($variable);
    }

    /**
     * @brief Returns the last error message from the active connection.
     *
     * @return string Error message string.
     */
    public function error(): string
    {
        return $this->connection->error;
    }

    /**
     * @brief Returns the auto-generated ID from the latest INSERT query.
     *
     * @return int|string Last insert identifier.
     */
    public function lastInsertId(): int|string
    {
        return $this->connection->insert_id;
    }

    /**
     * @brief Returns the number of rows affected by the last write operation.
     *
     * @return int Number of affected rows.
     */
    public function affectedRows(): int
    {
        return $this->connection->affected_rows;
    }

    /**
     * @brief Provides direct access to the underlying mysqli connection instance.
     *
     * @return mysqli Active mysqli instance.
     */
    public function getConnection(): mysqli
    {
        return $this->connection;
    }

    /**
     * @brief Closes open connections; the next use opens them again.
     *
     * @param string|null $name Connection name, or null for all connections.
     * @return void
     */
    public static function disconnect(?string $name = null): void
    {
        $prefix = static::class . '#';
        foreach (array_keys(self::$instances) as $key) {
            if (str_starts_with($key, $prefix) && ($name === null || $key === $prefix . $name)) {
                self::$instances[$key]->connection->close();
                unset(self::$instances[$key]);
            }
        }
    }
}