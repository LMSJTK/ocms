<?php
/**
 * Database Connection Class
 * Handles PDO connection to PostgreSQL or MySQL database
 */

class Database {
    private static $instance = null;
    private $pdo;
    private $config;
    private $dbType;

    private function __construct($config) {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Get singleton instance of Database
     */
    public static function getInstance($config = null) {
        if (self::$instance === null) {
            if ($config === null) {
                throw new Exception("Configuration required for first database connection");
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Establish PDO connection
     */
    private function connect() {
        try {
            // Default to PostgreSQL if type not specified
            $this->dbType = isset($this->config['type']) ? $this->config['type'] : 'pgsql';

            // Build DSN based on database type
            if ($this->dbType === 'mysql') {
                $dsn = sprintf(
                    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['dbname']
                );
            } else {
                // PostgreSQL
                $dsn = sprintf(
                    "pgsql:host=%s;port=%s;dbname=%s",
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['dbname']
                );
            }

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->pdo = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );

            // Set search path to use the correct schema (PostgreSQL only)
            if ($this->dbType === 'pgsql' && isset($this->config['schema'])) {
                $this->pdo->exec("SET search_path TO " . $this->config['schema']);
            }

        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Get PDO instance
     */
    public function getPDO() {
        return $this->pdo;
    }

    /**
     * Get database type
     */
    public function getDbType() {
        return $this->dbType;
    }

    /**
     * Execute a query
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage() . " SQL: " . $sql);
        }
    }

    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Insert a record and return the ID (if auto-generated)
     */
    public function insert($table, $data) {
        $columns = array_keys($data);
        $placeholders = array_map(function($col) { return ':' . $col; }, $columns);

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($data as $key => $value) {
            // Convert boolean values based on database type
            if (is_bool($value)) {
                if ($this->dbType === 'mysql') {
                    $params[':' . $key] = $value ? 1 : 0;
                } else {
                    $params[':' . $key] = $value ? 'true' : 'false';
                }
            } else {
                $params[':' . $key] = $value;
            }
        }

        $this->query($sql, $params);

        // Return last inserted ID if available (only works for SERIAL columns)
        // For tables with text IDs (like content), this will return null
        try {
            $lastId = $this->pdo->lastInsertId();
            return $lastId ?: null;
        } catch (PDOException $e) {
            // No sequence used (e.g., text ID), return null
            return null;
        }
    }

    /**
     * Update a record
     */
    public function update($table, $data, $where, $whereParams = []) {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "$column = :$column";
        }

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $sets),
            $where
        );

        $params = [];
        foreach ($data as $key => $value) {
            // Convert boolean values based on database type
            if (is_bool($value)) {
                if ($this->dbType === 'mysql') {
                    $params[':' . $key] = $value ? 1 : 0;
                } else {
                    $params[':' . $key] = $value ? 'true' : 'false';
                }
            } else {
                $params[':' . $key] = $value;
            }
        }

        $params = array_merge($params, $whereParams);

        return $this->query($sql, $params);
    }

    /**
     * Delete a record
     */
    public function delete($table, $where, $whereParams = []) {
        $sql = "DELETE FROM $table WHERE $where";
        return $this->query($sql, $whereParams);
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
}
