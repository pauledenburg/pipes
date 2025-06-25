<?php

/**
 * Large XML Files Example
 * 
 * This example demonstrates how to process large XML files (150MB+)
 * using streaming to maintain constant memory usage.
 */

require __DIR__ . '/../vendor/autoload.php';

use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\StreamingXmlExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\XmlToArrayTransformer;

// Large XML file (150MB+)
$largeXmlFile = __DIR__ . '/xml-files/large_products.xml';
$outputJson = __DIR__ . '/output/large_products.ndjson';

// Track memory usage
$startMemory = memory_get_usage();
$startTime = microtime(true);

echo "Starting processing of large XML file...\n";
echo "Initial memory usage: " . formatBytes($startMemory) . "\n\n";

// Create pipeline with streaming extractor
$pipe = new EtlPipe();
$pipe->extract(
    StreamingXmlExtractor::make($largeXmlFile, 'Product')
        ->setProgressCallback(function ($count) {
            if ($count % 1000 === 0) {
                $memory = formatBytes(memory_get_usage());
                echo "Processed {$count} records. Memory: {$memory}\n";
            }
        })
    )
    ->load(
        JsonLoader::make($outputJson)
            ->asNdjson()  // Use NDJSON format for large datasets
            ->setBufferSize(100)  // Write every 100 records
    );

// Run the pipeline
$pipe->run();

// Calculate statistics
$endTime = microtime(true);
$endMemory = memory_get_usage();
$peakMemory = memory_get_peak_usage();

$duration = round($endTime - $startTime, 2);
$memoryUsed = formatBytes($endMemory - $startMemory);
$peakMemoryFormatted = formatBytes($peakMemory);

echo "\n=== Processing Complete ===\n";
echo "Duration: {$duration} seconds\n";
echo "Memory used: {$memoryUsed}\n";
echo "Peak memory: {$peakMemoryFormatted}\n";
echo "Output file: {$outputJson}\n";

// Count records in output
$lineCount = 0;
$handle = fopen($outputJson, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        if (trim($line) !== '') {
            $lineCount++;
        }
    }
    fclose($handle);
}
echo "Total records: {$lineCount}\n";

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}