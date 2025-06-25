<?php

use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\FtpExtractor;
use Jwhulette\Pipes\Transformers\{TrimTransformer, CaseTransformer, DateTimeTransformer};
use Jwhulette\Pipes\Loaders\CsvLoader;

// Example 1: Extract specific multiple files
EtlPipe::make()
    ->extract(
        FtpExtractor::make('ftp.example.com', 'username', 'password', [
            '/data/sales_jan_2024.csv',
            '/data/sales_feb_2024.csv',
            '/data/sales_mar_2024.csv'
        ])
        ->setPort(21)
        ->setPassive(true)
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns(),
        CaseTransformer::make()->transformColumn('customer_name', 'title'),
        DateTimeTransformer::make()->transformColumn('order_date', 'Y-m-d', 'd/m/Y')
    ])
    ->load(CsvLoader::make('consolidated_sales_q1_2024.csv'))
    ->run();

// Example 2: Extract files using pattern matching
EtlPipe::make()
    ->extract(
        FtpExtractor::make('ftp.example.com', 'username', 'password', '/data/dummy.csv')
            ->withPattern('sales_*.csv', '/data') // This replaces the initial file with all matching files
    )
    ->transform([
        TrimTransformer::make()->transformAllColumns()
    ])
    ->load(CsvLoader::make('all_sales_data.csv'))
    ->run();

// Example 3: Add files dynamically
$ftpExtractor = FtpExtractor::make('ftp.example.com', 'username', 'password', '/reports/daily_report.csv');

// Add more files based on some condition
if (date('j') === '1') { // First day of month
    $ftpExtractor->addRemoteFiles([
        '/reports/monthly_summary.csv',
        '/reports/monthly_metrics.csv'
    ]);
}

// Add files from different directories
$ftpExtractor->addRemoteFiles('/backup/archive.csv')
             ->addRemoteFiles([
                 '/exports/customers.csv',
                 '/exports/products.csv'
             ]);

EtlPipe::make()
    ->extract($ftpExtractor)
    ->transform([
        TrimTransformer::make()->transformAllColumns()
    ])
    ->load(CsvLoader::make('combined_reports.csv'))
    ->run();

// Example 4: Anonymous FTP with multiple files
EtlPipe::make()
    ->extract(
        FtpExtractor::anonymous('ftp.public-data.com', [
            '/pub/datasets/weather_data.csv',
            '/pub/datasets/temperature_data.csv',
            '/pub/datasets/rainfall_data.csv'
        ])
    )
    ->transform([
        // Each file will have _source_file column added automatically
        // This helps identify which row came from which file
    ])
    ->load(CsvLoader::make('weather_combined.csv'))
    ->run();