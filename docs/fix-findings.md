# ETL Pipeline - Security & Quality Remediation Plan

## Overzicht

Dit document beschrijft de acties die ondernomen moeten worden om de kritieke beveiligingsproblemen en kwaliteitsissues op te lossen die zijn geïdentificeerd tijdens de code-analyse van de ETL pipeline.

## 🚨 KRITIEKE BEVEILIGINGSPROBLEMEN (Prioriteit: ONMIDDELLIJK)

### 1. SQL Injection Kwetsbaarheid - `SqlExtractor:114`

**Probleem**: Exception message bevat ongevalideerde table parameter
```php
// GEVAARLIJK (huidige code)
throw new \Exception('A table name has not been set', 1);
```

**Oplossing**:
- [ ] Vervang generieke Exception door aangepaste `PipesSqlException`
- [ ] Valideer table names tegen whitelist of gebruik parameterized queries
- [ ] Implementeer input sanitization voor alle database parameters

```php
// VEILIG (voorgestelde fix)
if (\is_null($this->table)) {
    throw new PipesSqlException('Database table configuration is invalid');
}
```

### 2. Credential Opslag - `FtpExtractor`

**Probleem**: Wachtwoorden worden in plain text opgeslagen in class properties

**Oplossing**:
- [ ] Implementeer credential encryption voor gevoelige data
- [ ] Gebruik Laravel's encryption helpers: `Crypt::encrypt()`
- [ ] Voeg credential masking toe in error messages en logs
- [ ] Overweeg gebruik van Laravel Vault of externe credential stores

```php
// Voorgestelde implementatie
protected string $encryptedPassword;

public function __construct(string $host, string $username, string $password, $remoteFiles)
{
    $this->encryptedPassword = Crypt::encrypt($password);
    // ... rest van constructor
}

private function getDecryptedPassword(): string
{
    return Crypt::decrypt($this->encryptedPassword);
}
```

### 3. Directory Traversal - `FtpExtractor`

**Probleem**: Ongevalideerde temp file paths kunnen directory traversal aanvallen mogelijk maken

**Oplossing**:
- [ ] Valideer alle file paths tegen allowed directories
- [ ] Gebruik `realpath()` om path traversal te voorkomen
- [ ] Implementeer whitelist voor toegestane file extensions
- [ ] Sanitize filenames van remote sources

```php
// Voorgestelde path validation
private function validateTempPath(string $path): string
{
    $realPath = realpath(dirname($path));
    $allowedDir = realpath(sys_get_temp_dir());
    
    if (strpos($realPath, $allowedDir) !== 0) {
        throw new PipesSqlException('Invalid file path detected');
    }
    
    return $path;
}
```

### 4. Input Validation - `Frame:87`

**Probleem**: `setAttribute()` kwetsbaar voor array key manipulation

**Oplossing**:
- [ ] Implementeer input validation voor array keys
- [ ] Beperk toegestane attribute names tot whitelist
- [ ] Voeg type checking toe voor attribute values
- [ ] Implementeer maximum lengte voor keys en values

```php
// Voorgestelde fix
private const ALLOWED_ATTRIBUTES = ['table', 'source_file', 'record_count'];
private const MAX_KEY_LENGTH = 50;
private const MAX_VALUE_LENGTH = 255;

public function setAttribute(array $attribute): void
{
    $key = key($attribute);
    $value = $attribute[$key];
    
    // Validatie
    if (!in_array($key, self::ALLOWED_ATTRIBUTES)) {
        throw new PipesInvalidArgumentException("Invalid attribute key: {$key}");
    }
    
    if (strlen($key) > self::MAX_KEY_LENGTH || strlen($value) > self::MAX_VALUE_LENGTH) {
        throw new PipesInvalidArgumentException("Attribute key or value too long");
    }
    
    $this->attributes[$key] = $value;
}
```

### 5. Database Connection Validation - `SqlLoader:36`

**Probleem**: Ontbrekende database connection validatie

**Oplossing**:
- [ ] Implementeer connection validation in constructor
- [ ] Voeg database health checks toe
- [ ] Implementeer connection retry logic
- [ ] Valideer table existence voor operations

```php
// Voorgestelde fix
public function __construct(string $table, ?string $connection = null)
{
    $this->validateTableName($table);
    
    try {
        if (!is_null($connection)) {
            $this->db = DB::connection($connection)->table($table);
            $this->validateConnection($connection);
        } else {
            $this->db = DB::table($table);
        }
        
        $this->validateTableExists($table);
    } catch (\Exception $e) {
        throw new PipesSqlException("Database connection failed: " . $e->getMessage());
    }
}

private function validateTableName(string $table): void
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
        throw new PipesInvalidArgumentException("Invalid table name format");
    }
}
```

## 🔺 HOGE PRIORITEIT ISSUES

### 6. Memory Management - `StreamingXmlExtractor:123`

**Probleem**: Onbegrensde memory usage door XMLReader->expand()

**Oplossing**:
- [ ] Implementeer memory limits voor XMLReader operations
- [ ] Voeg memory monitoring toe tijdens processing
- [ ] Implementeer circuit breaker pattern voor large files
- [ ] Optimaliseer XML parsing voor memory efficiency

```php
// Voorgestelde memory management
private int $maxMemoryUsage = 128 * 1024 * 1024; // 128MB default

protected function parseElement(XMLReader $reader): array
{
    // Check memory usage before expansion
    if (memory_get_usage() > $this->maxMemoryUsage) {
        throw new PipesException('Memory limit exceeded during XML processing');
    }
    
    $element = $reader->expand();
    // ... rest van method
}

public function setMaxMemoryUsage(int $bytes): self
{
    $this->maxMemoryUsage = $bytes;
    return $this;
}
```

### 7. Dependency Injection - `SqliteMergeTransformer:150`

**Probleem**: Directe PDO instantiatie zonder dependency injection

**Oplossing**:
- [ ] Maak PDO injectable via constructor
- [ ] Implementeer database factory pattern
- [ ] Voeg interface toe voor database connections
- [ ] Gebruik Laravel's database manager waar mogelijk

```php
// Voorgestelde interface
interface DatabaseConnectionInterface
{
    public function query(string $sql): mixed;
    public function prepare(string $sql): mixed;
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollBack(): bool;
}

// Updated constructor
public function __construct(
    ?string $dbPath = null, 
    ?DatabaseConnectionInterface $connection = null
) {
    $this->dbPath = $dbPath ?? ':memory:';
    $this->connection = $connection ?? $this->createDefaultConnection();
}
```

### 8. Production Observability

**Probleem**: Ontbrekende metrics, logging en health checks

**Oplossing**:
- [ ] Implementeer structured logging (PSR-3 compatible)
- [ ] Voeg metrics collection toe (processing time, memory usage, record counts)
- [ ] Implementeer health check endpoints
- [ ] Voeg performance monitoring toe

```php
// Voorgestelde logging interface
interface EtlLoggerInterface
{
    public function logExtractionStart(string $source, array $context = []): void;
    public function logTransformationApplied(string $transformer, int $recordCount): void;
    public function logLoadingComplete(string $destination, int $recordCount): void;
    public function logError(\Throwable $exception, array $context = []): void;
}

// Metrics collection
interface EtlMetricsInterface
{
    public function incrementCounter(string $metric, array $tags = []): void;
    public function recordTiming(string $metric, float $time, array $tags = []): void;
    public function recordGauge(string $metric, float $value, array $tags = []): void;
}
```

## 🔶 MEDIUM PRIORITEIT ISSUES

### 9. Test Coverage Uitbreiding

**Probleem**: Slechts 27 test files voor complex ETL systeem

**Oplossing**:
- [ ] Voeg integration tests toe voor complete ETL pipelines
- [ ] Implementeer unit tests voor alle transformers
- [ ] Voeg security tests toe voor SQL injection en path traversal
- [ ] Implementeer performance tests voor large datasets
- [ ] Voeg edge case tests toe (empty files, malformed data, network failures)

**Test Structure**:
```
tests/
├── Unit/
│   ├── Extractors/
│   ├── Transformers/
│   ├── Loaders/
│   └── Security/
├── Integration/
│   ├── FullPipelineTest.php
│   ├── LargeDatasetTest.php
│   └── ErrorHandlingTest.php
└── Performance/
    ├── MemoryUsageTest.php
    └── ProcessingSpeedTest.php
```

### 10. Input Validation Framework

**Probleem**: Inconsistente input validatie door het systeem

**Oplossing**:
- [ ] Implementeer centralized validation service
- [ ] Voeg validation rules toe voor alle input types
- [ ] Implementeer sanitization helpers
- [ ] Voeg validation middleware toe voor pipeline processing

```php
// Voorgestelde validation service
class PipelineValidationService
{
    public function validateFilePath(string $path): string
    {
        // Path validation logic
    }
    
    public function validateDatabaseTable(string $table): string
    {
        // Table name validation
    }
    
    public function validateFrameData(array $data): array
    {
        // Data validation and sanitization
    }
}
```

## 🔻 LAGE PRIORITEIT ISSUES

### 11. Error Handling Consistentie

**Probleem**: Mix van Exception en custom exceptions

**Oplossing**:
- [ ] Standaardiseer op custom exception hierarchy
- [ ] Implementeer exception context voor betere debugging
- [ ] Voeg error codes toe voor programmatic handling
- [ ] Documenteer exception handling patterns

```php
// Voorgestelde exception hierarchy
abstract class PipesException extends \Exception
{
    public function getContext(): array
    {
        return [];
    }
}

class PipesSecurityException extends PipesException {}
class PipesValidationException extends PipesException {}
class PipesConnectionException extends PipesException {}
```

## Implementation Roadmap

### Fase 1: Kritieke Security Fixes (Week 1-2)
- [ ] SQL Injection fixes
- [ ] Credential encryption
- [ ] Input validation
- [ ] Path traversal protection

### Fase 2: Architecture & Performance (Week 3-4)
- [ ] Memory management
- [ ] Dependency injection
- [ ] Observability framework
- [ ] Database connection improvements

### Fase 3: Quality & Testing (Week 5-6)
- [ ] Test coverage expansion
- [ ] Error handling standardization
- [ ] Documentation updates
- [ ] Performance optimization

### Fase 4: Production Readiness (Week 7-8)
- [ ] Security audit
- [ ] Load testing
- [ ] Deployment preparation
- [ ] Monitoring setup

## Testing Strategy

Voor elke fix:
1. **Unit tests** schrijven die de vulnerability demonstreren
2. **Fix implementeren**
3. **Tests laten slagen**
4. **Integration tests** toevoegen
5. **Security validation** uitvoeren

## Acceptatie Criteria

- [ ] Alle critical security issues opgelost
- [ ] Test coverage > 80%
- [ ] Security scan zonder high/critical findings
- [ ] Performance benchmarks binnen acceptable ranges
- [ ] Documentation bijgewerkt
- [ ] Code review completed

## Monitoring & Maintenance

Na implementatie:
- [ ] Security monitoring alerts opzetten
- [ ] Performance metrics dashboards
- [ ] Regular security audits schedules
- [ ] Dependency updates monitoring

---

**Belangrijk**: Start met de kritieke security issues voordat andere improvements worden doorgevoerd. Production deployment is blocked totdat alle critical security vulnerabilities zijn opgelost.