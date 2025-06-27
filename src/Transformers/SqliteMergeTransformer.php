<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Transformers;

use Exception;
use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\Frame;
use PDO;
use PDOException;

final class SqliteMergeTransformer implements TransformerInterface
{
    protected PDO $pdo;

    protected string $dbPath;

    protected array $tables = [];

    protected array $mergeKeys = [];

    protected string $outputTable = 'merged_data';

    protected bool $initialized = false;

    protected int $batchSize = 1000;

    protected array $insertBuffers = [];

    protected int $recordCount = 0;

    /** @var callable|null */
    protected $progressCallback = null;

    protected bool $autoCleanup = true;

    /**
     * @param string|null $dbPath Path to SQLite database (null for in-memory)
     */
    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?? ':memory:';
        $this->initializeDatabase();
    }

    /**
     * Define a source table schema.
     * @param string $tableName Name of the table
     * @param array $columns Column definitions ['column_name' => 'type']
     * @param string|array $mergeKey Column(s) to use for merging
     */
    public function defineTable(string $tableName, array $columns, $mergeKey): self
    {
        $this->tables[$tableName] = $columns;
        $this->mergeKeys[$tableName] = is_array($mergeKey) ? $mergeKey : [$mergeKey];

        // Create table if database is already initialized
        if ($this->initialized) {
            $this->createTable($tableName, $columns);
            $this->createIndexes($tableName, $this->mergeKeys[$tableName]);
        }

        return $this;
    }

    /**
     * Set the output table name.
     */
    public function setOutputTable(string $tableName): self
    {
        $this->outputTable = $tableName;

        return $this;
    }

    /**
     * Set batch size for inserts.
     */
    public function setBatchSize(int $size): self
    {
        $this->batchSize = $size;

        return $this;
    }

    /**
     * Set progress callback.
     * @param callable $callback Function that receives record count
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Disable automatic cleanup on destruction.
     */
    public function disableAutoCleanup(): self
    {
        $this->autoCleanup = false;

        return $this;
    }

    public function __invoke(Frame $frame): Frame
    {
        // Initialize tables on first frame
        if (! $this->initialized && ! empty($this->tables)) {
            $this->initializeTables();
        }

        // Handle end frame
        if ($frame->getEnd()) {
            $this->flushAllBuffers();
            $this->performMerge();

            return $frame;
        }

        // Get table name from frame attribute or detect from data
        $tableName = null;
        $tableName = $frame->getAttribute('table');
        
        if (!$tableName) {
            // No table attribute, try to detect
            $tableName = $this->detectTableFromData($frame->getData()->toArray());
        }

        if (! $tableName || ! isset($this->tables[$tableName])) {
            throw new Exception('Unknown table for frame data');
        }

        // Buffer the insert
        $this->bufferInsert($tableName, $frame->getData()->toArray());

        // Return frame unchanged for next transformer
        return $frame;
    }

    /**
     * Initialize SQLite database.
     */
    protected function initializeDatabase(): void
    {
        try {
            $dsn = $this->dbPath === ':memory:' ? 'sqlite::memory:' : "sqlite:{$this->dbPath}";
            $this->pdo = new PDO($dsn);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Optimize SQLite for performance
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = 10000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
        } catch (PDOException $e) {
            throw new Exception('Failed to initialize SQLite database: ' . $e->getMessage());
        }
    }

    /**
     * Initialize all defined tables.
     */
    protected function initializeTables(): void
    {
        foreach ($this->tables as $tableName => $columns) {
            $this->createTable($tableName, $columns);
            $this->createIndexes($tableName, $this->mergeKeys[$tableName]);
            $this->insertBuffers[$tableName] = [];
        }

        $this->initialized = true;
    }

    /**
     * Create a table in SQLite.
     */
    protected function createTable(string $tableName, array $columns): void
    {
        $columnDefs = [];
        foreach ($columns as $name => $type) {
            $sqlType = $this->mapToSqliteType($type);
            $columnDefs[] = "`{$name}` {$sqlType}";
        }

        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n" .
               implode(",\n", $columnDefs) . "\n)";

        $this->pdo->exec($sql);
    }

    /**
     * Create indexes for merge keys.
     */
    protected function createIndexes(string $tableName, array $keys): void
    {
        foreach ($keys as $key) {
            $indexName = "idx_{$tableName}_{$key}";
            $sql = "CREATE INDEX IF NOT EXISTS `{$indexName}` ON `{$tableName}` (`{$key}`)";
            $this->pdo->exec($sql);
        }
    }

    /**
     * Map PHP types to SQLite types.
     */
    protected function mapToSqliteType(string $type): string
    {
        return match (strtolower($type)) {
            'int', 'integer' => 'INTEGER',
            'float', 'double', 'decimal' => 'REAL',
            'bool', 'boolean' => 'INTEGER',
            'date', 'datetime', 'timestamp' => 'TEXT',
            default => 'TEXT'
        };
    }

    /**
     * Buffer insert for batch processing.
     */
    protected function bufferInsert(string $tableName, array $data): void
    {
        $this->insertBuffers[$tableName][] = $data;

        if (count($this->insertBuffers[$tableName]) >= $this->batchSize) {
            $this->flushBuffer($tableName);
        }
    }

    /**
     * Flush a specific table's buffer.
     */
    protected function flushBuffer(string $tableName): void
    {
        if (empty($this->insertBuffers[$tableName])) {
            return;
        }

        $records = $this->insertBuffers[$tableName];
        $this->insertBuffers[$tableName] = [];

        // Get column names from first record
        $columns = array_keys($records[0]);
        $columnList = implode('`, `', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO `{$tableName}` (`{$columnList}`) VALUES ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);

        $this->pdo->beginTransaction();
        try {
            foreach ($records as $record) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $record[$column] ?? null;
                }
                $stmt->execute($values);

                $this->recordCount++;
                if ($this->progressCallback !== null && $this->recordCount % 100 === 0) {
                    ($this->progressCallback)($this->recordCount);
                }
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new Exception('Failed to insert records: ' . $e->getMessage());
        }
    }

    /**
     * Flush all buffers.
     */
    protected function flushAllBuffers(): void
    {
        foreach (array_keys($this->insertBuffers) as $tableName) {
            $this->flushBuffer($tableName);
        }
    }

    /**
     * Perform the merge operation.
     */
    protected function performMerge(): void
    {
        if (empty($this->tables)) {
            return;
        }

        // Build merge query
        $sql = $this->buildMergeQuery();

        // Create merged table
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->outputTable}`");
        $this->pdo->exec("CREATE TABLE `{$this->outputTable}` AS {$sql}");

        // Create indexes on output table
        $commonKeys = $this->getCommonMergeKeys();
        foreach ($commonKeys as $key) {
            $indexName = "idx_{$this->outputTable}_{$key}";
            $this->pdo->exec("CREATE INDEX `{$indexName}` ON `{$this->outputTable}` (`{$key}`)");
        }
    }

    /**
     * Build the merge SQL query.
     */
    protected function buildMergeQuery(): string
    {
        $tables = array_keys($this->tables);

        if (count($tables) === 1) {
            // Single table, no merge needed
            return "SELECT * FROM `{$tables[0]}`";
        }

        // Start with first table
        $baseTable = array_shift($tables);
        $sql = 'SELECT ';

        // Build column list (avoiding duplicates)
        $selectedColumns = [];
        $allColumns = [];

        // Add columns from base table
        foreach ($this->tables[$baseTable] as $column => $type) {
            $selectedColumns[] = "`{$baseTable}`.`{$column}`";
            $allColumns[] = $column;
        }

        // Add columns from other tables (excluding merge keys to avoid duplicates)
        foreach ($tables as $table) {
            foreach ($this->tables[$table] as $column => $type) {
                if (! in_array($column, $allColumns) || in_array($column, $this->mergeKeys[$table])) {
                    if (! in_array($column, $allColumns)) {
                        $selectedColumns[] = "`{$table}`.`{$column}`";
                        $allColumns[] = $column;
                    }
                }
            }
        }

        $sql .= implode(', ', $selectedColumns);
        $sql .= " FROM `{$baseTable}`";

        // Add JOINs
        foreach ($tables as $table) {
            $joinConditions = [];
            foreach ($this->mergeKeys[$table] as $key) {
                if (in_array($key, $this->mergeKeys[$baseTable])) {
                    $joinConditions[] = "`{$baseTable}`.`{$key}` = `{$table}`.`{$key}`";
                }
            }

            if (! empty($joinConditions)) {
                $sql .= " LEFT JOIN `{$table}` ON " . implode(' AND ', $joinConditions);
            }
        }

        return $sql;
    }

    /**
     * Get common merge keys across all tables.
     */
    protected function getCommonMergeKeys(): array
    {
        if (empty($this->mergeKeys)) {
            return [];
        }

        $allKeys = array_merge(...array_values($this->mergeKeys));

        return array_unique($allKeys);
    }

    /**
     * Detect table name from data structure.
     */
    protected function detectTableFromData(array $data): ?string
    {
        // This is a simple implementation - you might want to customize this
        // based on your specific data structure

        foreach ($this->tables as $tableName => $columns) {
            $tableColumns = array_keys($columns);
            $dataColumns = array_keys($data);

            // Check if data columns match table columns
            $matching = array_intersect($tableColumns, $dataColumns);
            // Require exact match for all table columns (data can have extra columns)
            if (count($matching) == count($tableColumns) && count($tableColumns) > 0) {
                return $tableName;
            }
        }

        return null;
    }

    /**
     * Get merged data as generator.
     */
    public function getMergedData(): \Generator
    {
        $stmt = $this->pdo->query("SELECT * FROM `{$this->outputTable}`");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * Get count of merged records.
     */
    public function getMergedCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$this->outputTable}`");

        return (int) $stmt->fetchColumn();
    }

    /**
     * Cleanup temporary database and associated WAL/SHM files.
     */
    public function cleanup(): void
    {
        if ($this->dbPath !== ':memory:' && file_exists($this->dbPath)) {
            // Remove main database file
            unlink($this->dbPath);
            
            // Remove WAL (Write-Ahead Logging) file if it exists
            $walFile = $this->dbPath . '-wal';
            if (file_exists($walFile)) {
                unlink($walFile);
            }
            
            // Remove SHM (Shared Memory) file if it exists
            $shmFile = $this->dbPath . '-shm';
            if (file_exists($shmFile)) {
                unlink($shmFile);
            }
        }
    }

    /**
     * Destructor - cleanup if auto cleanup is enabled.
     */
    public function __destruct()
    {
        if ($this->autoCleanup) {
            $this->cleanup();
        }
    }

    /**
     * Create instance.
     */
    public static function make(?string $dbPath = null): static
    {
        return new static($dbPath);
    }
}
