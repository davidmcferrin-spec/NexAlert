<?php
/**
 * NexAlert - Database Connection Singleton
 * PDO wrapper with connection retry, query helpers, and transaction support.
 * MySQL 8.0 / Azure MySQL Flexible Server compatible.
 */

declare(strict_types=1);

namespace NexAlert\Config;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private int $queryCount = 0;

    private function __construct()
    {
        $this->connect();
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the raw PDO connection.
     */
    public function pdo(): PDO
    {
        // Ping and reconnect if the connection dropped (common on long-running workers)
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException) {
            $this->connect();
        }
        return $this->pdo;
    }

    /**
     * Establish PDO connection with retry logic.
     */
    private function connect(int $maxRetries = 3): void
    {
        $host    = Env::require('DB_HOST');
        $port    = Env::int('DB_PORT', 3306);
        $dbname  = Env::require('DB_NAME');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
            // Azure MySQL Flexible Server requires SSL
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ];

        $attempt = 0;
        while ($attempt < $maxRetries) {
            try {
                $this->pdo = new PDO(
                    $dsn,
                    Env::require('DB_USER'),
                    Env::require('DB_PASS'),
                    $options
                );

                // Enforce strict mode and timezone per session
                $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
                $this->pdo->exec("SET SESSION time_zone = '+00:00'");

                return;
            } catch (PDOException $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    Logger::error('Database connection failed after retries', [
                        'host'    => $host,
                        'dbname'  => $dbname,
                        'error'   => $e->getMessage(),
                        'attempt' => $attempt,
                    ]);
                    throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
                }
                sleep($attempt); // 1s, 2s backoff
            }
        }
    }

    /**
     * Execute a query and return the statement.
     * Use for INSERT, UPDATE, DELETE.
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $this->queryCount++;
        return $stmt;
    }

    /**
     * Fetch a single row. Returns null if not found.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row  = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single column value from the first row.
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->execute($sql, $params);
        $val  = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): void
    {
        $this->pdo()->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->pdo()->commit();
    }

    /**
     * Roll back the current transaction.
     */
    public function rollback(): void
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    /**
     * Run a callable inside a transaction. Auto-commits or rolls back.
     *
     * @throws \Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Build a safe IN() clause placeholder string and merge params.
     * Usage: [$placeholders, $mergedParams] = $db->inClause([1,2,3], $existingParams);
     *
     * @param array $ids
     * @param array $existingParams
     * @return array{0: string, 1: array}
     */
    public function inClause(array $ids, array $existingParams = []): array
    {
        if (empty($ids)) {
            throw new \InvalidArgumentException('inClause() requires at least one value');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return [$placeholders, array_merge($existingParams, array_values($ids))];
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    // Prevent cloning/unserialization of singleton
    private function __clone() {}
    public function __wakeup(): never
    {
        throw new \RuntimeException('Database singleton cannot be unserialized.');
    }
}
