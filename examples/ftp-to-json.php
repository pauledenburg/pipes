<?php

use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\FtpExtractor;
use Jwhulette\Pipes\Transformers\{TrimTransformer, CaseTransformer, DateTimeTransformer};
use Jwhulette\Pipes\Loaders\JsonLoader;

// Example 1: FTP to JSON - Standard format
EtlPipe::make()
    ->extract(
        FtpExtractor::make('ftp.example.com', 'username', 'password', [
            '/data/customers.csv',
            '/data/orders.csv'
        ])
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns(),
        CaseTransformer::make()
            ->transformColumn('customer_name', 'title')
            ->transformColumn('email', 'lower'),
        DateTimeTransformer::make()
            ->transformColumn('order_date', 'Y-m-d', 'd/m/Y')
    ])
    ->load(
        JsonLoader::make('output/consolidated_data.json')
            ->setPrettyPrint()
            ->setBufferSize(100)
    )
    ->run();

// Example 2: Create NDJSON for streaming applications
EtlPipe::make()
    ->extract(
        FtpExtractor::make('ftp.example.com', 'username', 'password', '/logs/access.log.csv')
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns()
    ])
    ->load(
        JsonLoader::make('output/access_logs.ndjson')
            ->asNdjson() // Each record on its own line
    )
    ->run();

// Example 3: Create API-ready JSON response
EtlPipe::make()
    ->extract(
        FtpExtractor::make('api.company.com', 'api_user', 'api_pass', [
            '/exports/products.csv',
            '/exports/inventory.csv'
        ])
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns(),
        // Add any API-specific transformations
    ])
    ->load(
        JsonLoader::make('api/products_response.json')
            ->forWebApi() // Unescaped slashes and unicode
            ->preserveNumeric() // Keep numeric values as numbers
            ->setPrettyPrint()
    )
    ->run();

// Example 4: Append to existing log file
$logLoader = JsonLoader::make('logs/etl_processing.jsonl')
    ->appendToFile() // Don't overwrite existing content
    ->asJsonLines() // Use JSON Lines format for logs
    ->setBufferSize(1); // Write immediately for real-time logging

// Process multiple FTP files and log each record
EtlPipe::make()
    ->extract(
        FtpExtractor::anonymous('ftp.public-data.com', [
            '/pub/daily/report1.csv',
            '/pub/daily/report2.csv'
        ])
    )
    ->transform([
        // Each record will have _source_file added by FtpExtractor
        TrimTransformer::make()->transformAllColumns()
    ])
    ->load($logLoader)
    ->run();

// Example 5: Convert CSV to JSON with custom formatting
EtlPipe::make()
    ->extract(
        FtpExtractor::make('ftp.example.com', 'user', 'pass', '/data/sales.csv')
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns(),
        DateTimeTransformer::make()
            ->transformColumn('sale_date', 'Y-m-d H:i:s', 'c'), // ISO 8601 format
    ])
    ->load(
        JsonLoader::make('output/sales_data.json')
            ->setPrettyPrint()
            ->setJsonFlags(JSON_FORCE_OBJECT | JSON_PRESERVE_ZERO_FRACTION)
    )
    ->run();