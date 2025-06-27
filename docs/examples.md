# EtlPipe Voorbeelden - Praktische Toepassingen

Deze gids bevat praktische voorbeelden die je direct kunt gebruiken en aanpassen voor je eigen projecten. Van eenvoudige transformaties tot complexe data-verwerkingsscenario's.

## Basis Voorbeelden

### 1. CSV Transformatie - Basis

```php
use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\CsvExtractor;
use Jwhulette\Pipes\Transformers\{TrimTransformer, CaseTransformer};
use Jwhulette\Pipes\Loaders\CsvLoader;

// Simpele CSV opschoning
EtlPipe::make()
    ->extract(
        CsvExtractor::make('ruwe-data.csv')
            ->setDelimiter(';')
            ->skipHeader()
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns(),
        CaseTransformer::make()->transformColumn('name', 'title'),
        CaseTransformer::make()->transformColumn('email', 'lower'),
    ])
    ->load(
        CsvLoader::make('schone-data.csv')
            ->setDelimiter(',')
            ->withHeader()
    )
    ->run();
```

### 2. Database naar JSON

```php
use Jwhulette\Pipes\Extractors\SqlExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\DateTimeTransformer;

// Klanten uit database naar JSON
EtlPipe::make()
    ->extract(
        SqlExtractor::make()
            ->setConnection('mysql')
            ->setTable('customers')
            ->setColumns(['id', 'name', 'email', 'created_at'])
            ->setWhere(['active' => 1])
    )
    ->transform([
        DateTimeTransformer::make()
            ->transformColumn('created_at', 'Y-m-d H:i:s', 'Y-m-d')
    ])
    ->load(
        JsonLoader::make('customers.json')
            ->setPrettyPrint()
            ->setBufferSize(500)
    )
    ->run();
```

### 3. Excel naar Database

```php
use Jwhulette\Pipes\Extractors\XlsxExtractor;
use Jwhulette\Pipes\Loaders\SqlLoader;
use Jwhulette\Pipes\Transformers\{PhoneTransformer, ZipcodeTransformer};

// Excel bestand importeren in database
EtlPipe::make()
    ->extract(
        XlsxExtractor::make('import.xlsx')
            ->setSheet('Klanten')
    )
    ->transform([
        PhoneTransformer::make()->transformColumn('phone'),
        ZipcodeTransformer::make()->transformColumn('zipcode'),
        TrimTransformer::make()->transformAllColumns(),
    ])
    ->load(
        SqlLoader::make()
            ->setConnection('pgsql')
            ->setTable('imported_customers')
            ->setChunkSize(1000)
    )
    ->run();
```

## Geavanceerde Voorbeelden

### 4. Conditionele Transformaties

```php
use Jwhulette\Pipes\Transformers\ConditionalTransformer;

// Business logic toepassen
EtlPipe::make()
    ->extract(CsvExtractor::make('orders.csv'))
    ->transform([
        // Grote klanten markeren
        ConditionalTransformer::make()
            ->addConditional(
                ['amount' => fn($value) => $value > 10000], // Conditie
                ['customer_type' => 'premium']               // Actie
            ),
        
        // Statusupdates
        ConditionalTransformer::make()
            ->addConditional(
                ['status' => 'pending', 'days_old' => fn($v) => $v > 7],
                ['priority' => 'urgent', 'status' => 'escalated']
            ),
    ])
    ->load(SqlLoader::make()->setTable('processed_orders'))
    ->run();
```

### 5. XML Bestanden Samenvoegen

```php
use Jwhulette\Pipes\Extractors\{XmlExtractor, StreamingXmlExtractor};
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Loaders\JsonLoader;

// Setup merge transformer
$merger = SqliteMergeTransformer::make()
    ->defineTable('products', [
        'id' => 'text',
        'name' => 'text', 
        'price' => 'real'
    ], 'id')
    ->defineTable('inventory', [
        'product_id' => 'text',
        'stock' => 'integer'
    ], 'product_id')
    ->setBatchSize(1000);

// XML bestanden verwerken
$files = [
    'products.xml' => 'Product',
    'inventory.xml' => 'Item'
];

foreach ($files as $file => $element) {
    $tableName = pathinfo($file, PATHINFO_FILENAME);
    
    // Gebruik StreamingXmlExtractor voor grote bestanden
    $extractor = filesize($file) > 50_000_000 
        ? StreamingXmlExtractor::make($file, $element)
        : XmlExtractor::make($file, $element);
    
    foreach ($extractor->extract() as $frame) {
        if (!$frame->getEnd()) {
            $frame->setAttribute(['table' => $tableName]);
            $merger($frame);
        }
    }
}

// Merge uitvoeren en output genereren
$endFrame = new Frame();
$endFrame->setEnd();
$merger($endFrame);

// Samengevoegde data opslaan
$jsonLoader = JsonLoader::make('merged-data.json')->setPrettyPrint();
foreach ($merger->getMergedData() as $row) {
    $frame = new Frame();
    $frame->setData($row);
    $jsonLoader->load($frame);
}
$jsonLoader->load($endFrame);
```

### 6. FTP naar Database Pipeline

```php
use Jwhulette\Pipes\Extractors\FtpExtractor;
use Jwhulette\Pipes\Loaders\{SqlLoader, CleanupLoader};

// Bestanden van FTP downloaden en verwerken
$pipeline = EtlPipe::make()
    ->extract(
        FtpExtractor::make('ftp.example.com', 'user', 'password', [
            '/data/daily-sales.csv',
            '/data/inventory-update.csv'
        ])
        ->setPassive(true)
        ->setTimeout(300)
        ->setProgressCallback(function($bytesDownloaded, $totalBytes) {
            $percent = round(($bytesDownloaded / $totalBytes) * 100);
            echo "\rDownloading: {$percent}%";
        })
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns(),
        DateTimeTransformer::make()
            ->transformColumn('date', 'd/m/Y', 'Y-m-d')
    ])
    ->load(
        CleanupLoader::wrap(
            SqlLoader::make()
                ->setConnection('mysql')
                ->setTable('daily_imports')
        )
        ->addCleanupCallback(function() {
            // Tijdelijke bestanden opruimen
            array_map('unlink', glob('/tmp/ftp_*'));
        })
        ->setCleanupOnError(true)
    )
    ->run();
```

## Custom Components Maken

### 7. Custom Extractor - API Data

```php
use Jwhulette\Pipes\Contracts\ExtractorInterface;
use Jwhulette\Pipes\Frame;
use Illuminate\Support\Facades\Http;

class ApiExtractor implements ExtractorInterface
{
    private string $baseUrl;
    private array $headers;
    private int $page = 1;
    
    public static function make(string $baseUrl, array $headers = []): self
    {
        return new self($baseUrl, $headers);
    }
    
    public function __construct(string $baseUrl, array $headers = [])
    {
        $this->baseUrl = $baseUrl;
        $this->headers = $headers;
    }
    
    public function extract(): Generator
    {
        do {
            $response = Http::withHeaders($this->headers)
                ->get($this->baseUrl, ['page' => $this->page]);
            
            $data = $response->json();
            
            foreach ($data['results'] as $item) {
                yield (new Frame())->setData($item);
            }
            
            $this->page++;
            
        } while ($data['has_more']);
        
        // Einde markeren
        yield (new Frame())->setEnd();
    }
}

// Gebruik:
EtlPipe::make()
    ->extract(ApiExtractor::make('https://api.example.com/users', [
        'Authorization' => 'Bearer ' . config('api.token')
    ]))
    ->load(JsonLoader::make('api-data.json'))
    ->run();
```

### 8. Custom Transformer - Data Validatie

```php
use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\Frame;

class ValidationTransformer implements TransformerInterface
{
    private array $rules;
    private bool $skipInvalid;
    
    public static function make(array $rules, bool $skipInvalid = false): self
    {
        return new self($rules, $skipInvalid);
    }
    
    public function __construct(array $rules, bool $skipInvalid = false)
    {
        $this->rules = $rules;
        $this->skipInvalid = $skipInvalid;
    }
    
    public function __invoke(Frame $frame): Frame
    {
        $errors = [];
        
        foreach ($this->rules as $field => $rule) {
            $value = $frame->data->get($field);
            
            if ($rule === 'required' && empty($value)) {
                $errors[] = "{$field} is required";
            }
            
            if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$field} must be valid email";
            }
            
            if (is_callable($rule) && !$rule($value)) {
                $errors[] = "{$field} failed validation";
            }
        }
        
        if (!empty($errors)) {
            if ($this->skipInvalid) {
                // Markeer als te skippen
                $frame->setAttribute(['skip' => true, 'errors' => $errors]);
            } else {
                throw new InvalidDataException(implode(', ', $errors));
            }
        }
        
        return $frame;
    }
}

// Gebruik:
EtlPipe::make()
    ->extract(CsvExtractor::make('users.csv'))
    ->transform([
        ValidationTransformer::make([
            'email' => 'email',
            'age' => fn($v) => is_numeric($v) && $v >= 18,
            'name' => 'required'
        ], $skipInvalid = true),
        
        // Filter ongeldige records uit
        new class implements TransformerInterface {
            public function __invoke(Frame $frame): Frame {
                if ($frame->attributes['skip'] ?? false) {
                    $frame->setEnd(); // Skip dit record
                }
                return $frame;
            }
        }
    ])
    ->load(SqlLoader::make()->setTable('valid_users'))
    ->run();
```

### 9. Custom Loader - Meerdere Outputs

```php
use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;

class MultiOutputLoader implements LoaderInterface
{
    private array $loaders;
    private $routingFunction;
    
    public static function make(array $loaders, callable $routingFunction): self
    {
        return new self($loaders, $routingFunction);
    }
    
    public function __construct(array $loaders, callable $routingFunction)
    {
        $this->loaders = $loaders;
        $this->routingFunction = $routingFunction;
    }
    
    public function load(Frame $frame): void
    {
        if ($frame->getEnd()) {
            // Alle loaders afsluiten
            foreach ($this->loaders as $loader) {
                $loader->load($frame);
            }
            return;
        }
        
        $target = ($this->routingFunction)($frame);
        
        if (isset($this->loaders[$target])) {
            $this->loaders[$target]->load($frame);
        }
    }
}

// Gebruik: Data verdelen over meerdere bestanden
EtlPipe::make()
    ->extract(CsvExtractor::make('mixed-data.csv'))
    ->load(
        MultiOutputLoader::make([
            'customers' => CsvLoader::make('customers.csv'),
            'orders' => CsvLoader::make('orders.csv'),
            'products' => CsvLoader::make('products.csv')
        ], function(Frame $frame) {
            return $frame->data->get('record_type');
        })
    )
    ->run();
```

## Performance Optimalisatie

### 10. Grote Bestanden Verwerken

```php
// Voor zeer grote XML bestanden (100MB+)
$progressCallback = function($count) {
    if ($count % 10000 === 0) {
        $memory = round(memory_get_usage() / 1024 / 1024, 2);
        echo "Processed: {$count} records, Memory: {$memory}MB\n";
    }
};

EtlPipe::make()
    ->extract(
        StreamingXmlExtractor::make('huge-file.xml', 'Record')
            ->setProgressCallback($progressCallback)
    )
    ->transform([
        // Alleen essentiële transformaties
        TrimTransformer::make()->transformColumn('name')
    ])
    ->load(
        JsonLoader::make('output.ndjson')
            ->asNdjson()                    // NDJSON voor streaming
            ->setBufferSize(10000)          // Grote buffer voor performance
    )
    ->run();
```

### 11. Parallel Processing Pattern

```php
// Voor verschillende databronnen parallel verwerken
use Symfony\Component\Process\Process;

$sources = [
    'customers.csv' => 'customers_output.json',
    'orders.csv' => 'orders_output.json', 
    'products.csv' => 'products_output.json'
];

$processes = [];

foreach ($sources as $input => $output) {
    $command = [
        'php', 'process-file.php',
        '--input', $input,
        '--output', $output
    ];
    
    $process = new Process($command);
    $process->start();
    $processes[] = $process;
}

// Wacht tot alle processen klaar zijn
foreach ($processes as $process) {
    $process->wait();
    echo "Completed: " . $process->getCommandLine() . "\n";
}
```

## Error Handling

### 12. Robuuste Error Handling

```php
use Jwhulette\Pipes\Exceptions\ExtractorException;

try {
    EtlPipe::make()
        ->extract(
            CsvExtractor::make('possibly-corrupt.csv')
                ->setErrorCallback(function($error, $lineNumber) {
                    Log::warning("CSV parsing error on line {$lineNumber}: {$error}");
                    return 'skip'; // of 'stop' of 'continue'
                })
        )
        ->transform([
            new class implements TransformerInterface {
                public function __invoke(Frame $frame): Frame {
                    try {
                        // Riskante transformatie
                        $frame->data->transform(function($value) {
                            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                        });
                    } catch (JsonException $e) {
                        Log::error("JSON decode error: " . $e->getMessage());
                        $frame->setAttribute(['error' => $e->getMessage()]);
                    }
                    
                    return $frame;
                }
            }
        ])
        ->load(
            new class implements LoaderInterface {
                private $errorFile;
                private $successFile;
                
                public function __construct() {
                    $this->errorFile = fopen('errors.log', 'a');
                    $this->successFile = fopen('success.json', 'w');
                }
                
                public function load(Frame $frame): void {
                    if ($frame->getEnd()) {
                        fclose($this->errorFile);
                        fclose($this->successFile);
                        return;
                    }
                    
                    if (isset($frame->attributes['error'])) {
                        fwrite($this->errorFile, json_encode([
                            'data' => $frame->data->toArray(),
                            'error' => $frame->attributes['error']
                        ]) . "\n");
                    } else {
                        fwrite($this->successFile, json_encode($frame->data->toArray()) . "\n");
                    }
                }
            }
        )
        ->run();
        
} catch (ExtractorException $e) {
    Log::error("Extractor failed: " . $e->getMessage());
    // Fallback actie
} catch (Exception $e) {
    Log::error("Pipeline failed: " . $e->getMessage());
    // Cleanup acties
}
```

## Testing Patterns

### 13. Unit Testing Custom Components

```php
use PHPUnit\Framework\TestCase;

class CustomTransformerTest extends TestCase
{
    public function test_transforms_data_correctly()
    {
        $transformer = new CustomTransformer();
        
        $frame = new Frame();
        $frame->setData(['name' => '  John Doe  ', 'email' => 'JOHN@EXAMPLE.COM']);
        
        $result = $transformer($frame);
        
        $this->assertEquals('John Doe', $result->data->get('name'));
        $this->assertEquals('john@example.com', $result->data->get('email'));
    }
    
    public function test_handles_empty_data()
    {
        $transformer = new CustomTransformer();
        
        $frame = new Frame();
        $frame->setData([]);
        
        $result = $transformer($frame);
        
        $this->assertTrue($result->data->isEmpty());
    }
}
```

### 14. Integration Testing

```php
class PipelineIntegrationTest extends TestCase
{
    public function test_complete_pipeline()
    {
        // Setup test data
        $testCsv = $this->createTestCsv([
            ['name', 'email', 'age'],
            ['John Doe', 'john@example.com', '30'],
            ['Jane Smith', 'jane@example.com', '25']
        ]);
        
        $outputFile = tempnam(sys_get_temp_dir(), 'pipeline_test');
        
        // Run pipeline
        EtlPipe::make()
            ->extract(CsvExtractor::make($testCsv))
            ->transform([
                TrimTransformer::make()->transformAllColumns()
            ])
            ->load(JsonLoader::make($outputFile))
            ->run();
        
        // Assert results
        $output = json_decode(file_get_contents($outputFile), true);
        $this->assertCount(2, $output);
        $this->assertEquals('John Doe', $output[0]['name']);
        
        // Cleanup
        unlink($testCsv);
        unlink($outputFile);
    }
    
    private function createTestCsv(array $data): string
    {
        $file = tempnam(sys_get_temp_dir(), 'test_csv');
        $handle = fopen($file, 'w');
        
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }
        
        fclose($handle);
        return $file;
    }
}
```

## Real-World Use Cases

### 15. E-commerce Product Import

```php
// Complete product import from supplier CSV
class ProductImportPipeline 
{
    public function run(string $supplierCsv): void
    {
        EtlPipe::make()
            ->extract(
                CsvExtractor::make($supplierCsv)
                    ->setDelimiter(';')
                    ->skipHeader()
            )
            ->transform([
                // Cleanup
                TrimTransformer::make()->transformAllColumns(),
                
                // Price formatting
                new class implements TransformerInterface {
                    public function __invoke(Frame $frame): Frame {
                        $price = str_replace([',', '€', ' '], ['', '', ''], $frame->data->get('price'));
                        $frame->data->put('price', (float) $price);
                        return $frame;
                    }
                },
                
                // Category mapping
                ConditionalTransformer::make()
                    ->addConditional(
                        ['category' => 'Electronics'],
                        ['category_id' => 1]
                    )
                    ->addConditional(
                        ['category' => 'Clothing'],
                        ['category_id' => 2]
                    ),
                
                // Stock validation
                ValidationTransformer::make([
                    'sku' => 'required',
                    'price' => fn($v) => is_numeric($v) && $v > 0,
                    'stock' => fn($v) => is_numeric($v) && $v >= 0
                ])
            ])
            ->load(
                SqlLoader::make()
                    ->setConnection('mysql')
                    ->setTable('products')
                    ->setOnDuplicate('update') // Update existing products
                    ->setChunkSize(500)
            )
            ->run();
    }
}
```

### 16. Log Analysis Pipeline

```php
// Apache log analysis naar metrics
class LogAnalysisPipeline
{
    public function run(string $logFile): void
    {
        $metrics = [
            'total_requests' => 0,
            'status_codes' => [],
            'top_ips' => [],
            'hourly_distribution' => []
        ];
        
        EtlPipe::make()
            ->extract(
                new class($logFile) implements ExtractorInterface {
                    private string $file;
                    
                    public function __construct(string $file) {
                        $this->file = $file;
                    }
                    
                    public function extract(): Generator {
                        $handle = fopen($this->file, 'r');
                        
                        while (($line = fgets($handle)) !== false) {
                            // Parse Apache log format
                            if (preg_match('/^(\S+) \S+ \S+ \[(.*?)\] "(\S+) (\S+) (\S+)" (\d+) (\d+)/', 
                                          $line, $matches)) {
                                yield (new Frame())->setData([
                                    'ip' => $matches[1],
                                    'timestamp' => $matches[2],
                                    'method' => $matches[3],
                                    'path' => $matches[4],
                                    'status' => (int) $matches[6],
                                    'size' => (int) $matches[7]
                                ]);
                            }
                        }
                        
                        fclose($handle);
                        yield (new Frame())->setEnd();
                    }
                }
            )
            ->transform([
                // Extract hour from timestamp
                new class implements TransformerInterface {
                    public function __invoke(Frame $frame): Frame {
                        $timestamp = $frame->data->get('timestamp');
                        $hour = date('H', strtotime($timestamp));
                        $frame->data->put('hour', $hour);
                        return $frame;
                    }
                }
            ])
            ->load(
                new class($metrics) implements LoaderInterface {
                    private array $metrics;
                    
                    public function __construct(array &$metrics) {
                        $this->metrics = &$metrics;
                    }
                    
                    public function load(Frame $frame): void {
                        if ($frame->getEnd()) {
                            file_put_contents('metrics.json', json_encode($this->metrics, JSON_PRETTY_PRINT));
                            return;
                        }
                        
                        $data = $frame->data;
                        
                        $this->metrics['total_requests']++;
                        $this->metrics['status_codes'][$data->get('status')] = 
                            ($this->metrics['status_codes'][$data->get('status')] ?? 0) + 1;
                        $this->metrics['top_ips'][$data->get('ip')] = 
                            ($this->metrics['top_ips'][$data->get('ip')] ?? 0) + 1;
                        $this->metrics['hourly_distribution'][$data->get('hour')] = 
                            ($this->metrics['hourly_distribution'][$data->get('hour')] ?? 0) + 1;
                    }
                }
            )
            ->run();
    }
}
```

Deze voorbeelden geven je een solide basis om je eigen ETL-pipelines te bouwen. Start met de eenvoudige voorbeelden en werk langzaam naar de meer complexe scenario's toe naarmate je vertrouwd raakt met de architectuur.