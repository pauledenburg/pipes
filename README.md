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
- 📊 **Multiple Data Sources** - CSV, Excel (XLSX), SQL databases
- 🔄 **Flexible Transformations** - Built-in transformers with easy extensibility
- 💾 **Various Output Formats** - CSV, SQL database support
- 🎯 **Laravel Integration** - Seamless integration with Laravel 10+
- 🧩 **Extensible Architecture** - Easy to add custom extractors, transformers, and loaders
- ⚡ **Performance Optimized** - Efficient memory usage with streaming support

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