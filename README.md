![Banner](.github/images/Pipes.png)

[![Tests](https://github.com/jwhulette/pipes/actions/workflows/tests.yml/badge.svg)](https://github.com/jwhulette/pipes/actions/workflows/tests.yml)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/jwhulette/pipes/php)
![Laravel](https://img.shields.io/badge/Laravel-10%2B-blue)
![Packagist Version](https://img.shields.io/packagist/v/jwhulette/pipes)
[![Total Downloads](https://img.shields.io/packagist/dt/jwhulette/pipes.svg?style=flat-square)](https://packagist.org/packages/jwhulette/pipes)

# Pipes - PHP ETL Library for Laravel

Pipes is a powerful PHP Extract Transform Load (ETL) package for Laravel applications. It provides a fluent interface for extracting data from various sources, transforming it according to your needs, and loading it into different destinations.

## Features

- 🚀 **Simple and Intuitive API** - Fluent interface for building ETL pipelines
- 📊 **Multiple Data Sources** - CSV, Excel (XLSX), SQL databases, **XML**, FTP
- 🔄 **Flexible Transformations** - Built-in transformers with easy extensibility
- 💾 **Various Output Formats** - CSV, SQL database, **JSON/NDJSON** support
- 🎯 **Laravel Integration** - Seamless integration with Laravel 10+
- 🧩 **Extensible Architecture** - Easy to add custom extractors, transformers, and loaders
- ⚡ **Performance Optimized** - Efficient memory usage with streaming support
- 📁 **Large XML Files** - Stream XML files (150MB+) with constant memory usage
- 🔗 **Data Merging** - Merge data from multiple sources using SQLite
- 🧹 **Automatic Cleanup** - Clean up temporary files automatically

## Requirements

- PHP 8.1, 8.2, or 8.3
- Laravel 10 or higher

## Installation

Install the package via Composer:

```bash
composer require jwhulette/pipes
```

## Quick Start

Here's a simple example that demonstrates the power of Pipes:

```php
use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\CsvExtractor;
use Jwhulette\Pipes\Transformers\{CaseTransformer, TrimTransformer};
use Jwhulette\Pipes\Loaders\CsvLoader;

EtlPipe::make()
    ->extract(CsvExtractor::make('input.csv'))
    ->transform([
        TrimTransformer::make()->transformAllColumns(),
        CaseTransformer::make()->transformColumn('name', 'title'),
        CaseTransformer::make()->transformColumn('email', 'lower'),
    ])
    ->load(CsvLoader::make('output.csv'))
    ->run();
```

## Usage Guide

### Basic Workflow

1. **Create an ETL Pipeline**: Start with the `EtlPipe` class
2. **Add an Extractor**: Choose your data source
3. **Add Transformers**: Process your data
4. **Add a Loader**: Define where to save the results
5. **Run the Pipeline**: Execute the ETL process

### Extractors

Extractors read data from various sources:

#### CSV Extractor
```php
$extractor = CsvExtractor::make('path/to/file.csv')
    ->setDelimiter(',')
    ->skipHeader();
```

#### Excel Extractor
```php
$extractor = XlsxExtractor::make('path/to/file.xlsx')
    ->setSheet('Sheet1');
```

#### SQL Extractor
```php
$extractor = SqlExtractor::make()
    ->setConnection('mysql')
    ->setTable('users')
    ->setColumns(['id', 'name', 'email']);
```

#### FTP Extractor
```php
// Single file extraction
$extractor = FtpExtractor::make('ftp.example.com', 'username', 'password', '/path/to/file.csv')
    ->setPort(21)
    ->setPassive(true)
    ->setTimeout(120);

// Multiple files extraction
$extractor = FtpExtractor::make('ftp.example.com', 'user', 'pass', [
    '/data/file1.csv',
    '/data/file2.csv',
    '/reports/summary.csv'
]);

// Add more files dynamically
$extractor->addRemoteFiles('/data/file3.csv')
         ->addRemoteFiles(['/data/file4.csv', '/data/file5.csv']);

// Extract files matching a pattern
$extractor = FtpExtractor::make('ftp.example.com', 'user', 'pass', '/data/initial.csv')
    ->withPattern('*.csv', '/data'); // Replaces files with all CSV files in /data directory

// Anonymous FTP with multiple files
$extractor = FtpExtractor::anonymous('ftp.example.com', [
    '/pub/data1.csv',
    '/pub/data2.csv'
]);

// Use custom extractor for downloaded files
$extractor = FtpExtractor::make('ftp.example.com', 'user', 'pass', '/data/file.json')
    ->setFileExtractor(new JsonExtractor('temp.json'));
```

### Transformers

Transform your data with built-in transformers:

#### Case Transformer
Change the case of string values:
```php
CaseTransformer::make()
    ->transformColumn('name', 'title')    // Title Case
    ->transformColumn('email', 'lower')   // lowercase
    ->transformColumn('code', 'upper')    // UPPERCASE
```

#### Trim Transformer
Remove whitespace from strings:
```php
TrimTransformer::make()
    ->transformColumn('name')              // Trim specific column
    ->transformAllColumns()                // Trim all columns
```

#### DateTime Transformer
Format date and time values:
```php
DateTimeTransformer::make()
    ->transformColumn('created_at', 'Y-m-d H:i:s', 'd/m/Y')
```

#### Phone Transformer
Format US phone numbers:
```php
PhoneTransformer::make()
    ->transformColumn('phone')
```

#### Zipcode Transformer
Format US zip codes:
```php
ZipcodeTransformer::make()
    ->transformColumn('zipcode')
```

#### Conditional Transformer
Transform based on conditions:
```php
ConditionalTransformer::make()
    ->addConditional(
        ['status' => 'pending'],           // If status is pending
        ['priority' => 'high']             // Set priority to high
    )
```

### Loaders

Save your transformed data:

#### CSV Loader
```php
CsvLoader::make('output.csv')
    ->setDelimiter(',')
    ->withHeader();
```

#### SQL Loader
```php
SqlLoader::make()
    ->setConnection('mysql')
    ->setTable('processed_users')
    ->setChunkSize(1000);
```

#### JSON Loader
```php
// Standard JSON array format
JsonLoader::make('output.json')
    ->setPrettyPrint()
    ->setBufferSize(500);

// Newline-delimited JSON (NDJSON/JSON Lines)
JsonLoader::make('output.ndjson')
    ->asNdjson();

// Optimized for web APIs
JsonLoader::make('api-response.json')
    ->forWebApi()
    ->setPrettyPrint();

// Append to existing file
JsonLoader::make('log.json')
    ->appendToFile()
    ->asJsonLines();
```

#### XML Extractors

##### Standard XML Extractor (for smaller files)
```php
// Extract specific elements
$extractor = XmlExtractor::make('products.xml', 'Product')
    ->setMaxFileSize(50); // MB

// Extract with XPath
$extractor = XmlExtractor::make('catalog.xml', '//product[@active="true"]');

// With namespaces
$extractor = XmlExtractor::make('namespaced.xml', '//ns:Product')
    ->registerNamespace('ns', 'http://example.com/namespace');
```

##### Streaming XML Extractor (for large files)
```php
// Process large XML files with constant memory usage
$extractor = StreamingXmlExtractor::make('large_catalog.xml', 'Product')
    ->setProgressCallback(function ($count) {
        echo "Processed {$count} records\n";
    });
```

### Advanced Transformers

#### XML to Array Transformer
Convert XML strings or SimpleXMLElements to arrays:
```php
XmlToArrayTransformer::make()
    ->includeAttributes(true)
    ->setAttributePrefix('@')
    ->setValueKey('_value');
```

#### SQLite Merge Transformer
Merge data from multiple sources:
```php
$merger = SqliteMergeTransformer::make()
    ->defineTable('products', [
        'id' => 'text',
        'name' => 'text',
        'price' => 'real'
    ], 'id')
    ->defineTable('stock', [
        'id' => 'text',
        'quantity' => 'integer'
    ], 'id')
    ->setBatchSize(1000)
    ->setProgressCallback(function ($count) {
        echo "Merged {$count} records\n";
    });
```

### Advanced Loaders

#### Cleanup Loader
Automatically clean up resources after processing:
```php
$jsonLoader = new JsonLoader('output.json');
$cleanupLoader = CleanupLoader::wrap($jsonLoader)
    ->addCleanupCallback(function () {
        // Delete temporary files
        unlink('/tmp/processing.db');
    })
    ->setCleanupOnError(true);

## Advanced Examples

### Complex Pipeline Example
```php
EtlPipe::make()
    ->extract(
        SqlExtractor::make()
            ->setConnection('source_db')
            ->setTable('raw_customers')
            ->setColumns(['id', 'full_name', 'phone', 'created_at'])
    )
    ->transform([
        // Clean up phone numbers
        PhoneTransformer::make()->transformColumn('phone'),
        
        // Standardize names
        TrimTransformer::make()->transformAllColumns(),
        CaseTransformer::make()->transformColumn('full_name', 'title'),
        
        // Format dates
        DateTimeTransformer::make()
            ->transformColumn('created_at', 'Y-m-d H:i:s', 'd/m/Y'),
        
        // Apply business logic
        ConditionalTransformer::make()
            ->addConditional(
                ['created_at' => date('Y-m-d')],
                ['is_new' => true]
            )
    ])
    ->load(
        SqlLoader::make()
            ->setConnection('target_db')
            ->setTable('customers')
    )
    ->run();
```

### Creating Custom Components

#### Custom Extractor
```php
use Jwhulette\Pipes\Contracts\ExtractorInterface;
use Jwhulette\Pipes\Frame;

class JsonExtractor implements ExtractorInterface
{
    public function extract(): Generator
    {
        $data = json_decode(file_get_contents($this->path), true);
        
        foreach ($data as $row) {
            yield (new Frame())->setData($row);
        }
    }
}
```

#### Custom Transformer
```php
use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\Frame;

class EncryptTransformer implements TransformerInterface
{
    public function __invoke(Frame $frame): Frame
    {
        $frame->getData()->transform(function ($item, $key) {
            if ($key === 'sensitive_data') {
                return encrypt($item);
            }
            return $item;
        });
        
        return $frame;
    }
}
```

#### Custom Loader
```php
use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;

class ApiLoader implements LoaderInterface
{
    public function load(Frame $frame): void
    {
        Http::post($this->endpoint, $frame->getData()->toArray());
    }
}
```

## Testing

Run the test suite:

```bash
composer test
```

Run PHPStan analysis:

```bash
composer phpstan
```

Run both tests and analysis:

```bash
composer ts
```

## Quality Assurance

### Continuous Integration

This package includes CI/CD configurations for both GitLab and GitHub:

**GitLab CI** (`.gitlab-ci.yml`):
- Multi-PHP version testing (8.1, 8.2, 8.3)
- PHPStan static analysis
- PHPUnit tests with coverage
- Code style checks with PHP-CS-Fixer
- Security vulnerability scanning

**GitHub Actions** (`.github/workflows/quality-checks.yml`):
- Matrix testing across PHP versions and Laravel versions
- Automated code style checking
- Security audits
- Code coverage reporting

### Local Development

**Pre-commit Hooks**:
```bash
# Enable git hooks
./setup-hooks.sh

# The pre-commit hook will:
# - Check PHP syntax
# - Run PHPStan analysis
# - Run tests
# - Fix code style automatically
# - Check for debug statements
```

**Code Style**:
```bash
# Check code style
vendor/bin/php-cs-fixer fix --dry-run --diff

# Fix code style automatically
vendor/bin/php-cs-fixer fix
```

## XML Processing Examples

### Processing Large XML Files

```php
use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\StreamingXmlExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;

// Stream a 150MB+ XML file
$pipe = EtlPipe::make()
    ->extract(
        StreamingXmlExtractor::make('huge_catalog.xml', 'Product')
            ->setProgressCallback(function ($count) {
                if ($count % 1000 === 0) {
                    echo "Processed: {$count}\n";
                }
            })
    )
    ->load(
        JsonLoader::make('output.ndjson')
            ->asNdjson()
            ->setBufferSize(100)
    )
    ->run();
```

### Merging Multiple XML Sources

```php
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Extractors\XmlExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;

// Setup merge transformer
$merger = SqliteMergeTransformer::make()
    ->defineTable('products', [
        'guid' => 'text',
        'name' => 'text',
        'price' => 'real'
    ], 'guid')
    ->defineTable('stock', [
        'guid' => 'text',
        'quantity' => 'integer'
    ], 'guid');

// Process each XML file
foreach (['products.xml', 'stock.xml'] as $file) {
    $tableName = basename($file, '.xml');
    $extractor = new XmlExtractor($file, rtrim($tableName, 's'));
    
    foreach ($extractor->extract() as $frame) {
        if (!$frame->getEnd()) {
            $frame->setAttribute(['table' => $tableName]);
            $merger($frame);
        }
    }
}

// Trigger merge and output
$endFrame = new Frame();
$endFrame->setEnd();
$merger($endFrame);

// Save merged data
$jsonLoader = JsonLoader::make('merged.json')->setPrettyPrint();
foreach ($merger->getMergedData() as $row) {
    $frame = new Frame();
    $frame->setData($row);
    $jsonLoader->load($frame);
}
$jsonLoader->load($endFrame);
```

### FTP to JSON Pipeline

```php
use Jwhulette\Pipes\Extractors\FtpExtractor;
use Jwhulette\Pipes\Loaders\{JsonLoader, CleanupLoader};

$pipe = EtlPipe::make()
    ->extract(
        FtpExtractor::make('ftp.example.com', 'user', 'pass', [
            '/data/products.xml',
            '/data/inventory.xml'
        ])
        ->setPassive(true)
        ->setTimeout(300)
    )
    ->load(
        CleanupLoader::wrap(JsonLoader::make('output.json'))
            ->addCleanupCallback(function () {
                // Clean up temp files
                array_map('unlink', glob('/tmp/ftp_extract_*'));
            })
    )
    ->run();
```

### Complete Examples

See the `examples/` directory for complete working examples:

- **`xml-simple-merge.php`** - Basic XML file merging
- **`xml-large-files.php`** - Processing large XML files with streaming
- **`xml-ftp-to-json.php`** - Download XML from FTP and convert to JSON
- **`xml-custom-merge.php`** - Custom merge logic implementation
- **`xml-progress-tracking.php`** - Progress tracking for large files

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details on how to contribute to this project.

## Security

If you discover any security-related issues, please email jwhulette@gmail.com instead of using the issue tracker.

## Credits

- [Wes Hulette](https://github.com/jwhulette)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.