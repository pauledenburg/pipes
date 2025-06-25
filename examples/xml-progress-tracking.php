<?php

/**
 * Progress Tracking Example
 * 
 * This example demonstrates how to track progress when processing
 * large XML files with real-time updates and statistics.
 */

require __DIR__ . '/../vendor/autoload.php';

use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\StreamingXmlExtractor;
use Jwhulette\Pipes\Extractors\FtpExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Frame;

class ProgressTracker
{
    private $startTime;
    private $totalFiles;
    private $currentFile = 0;
    private $recordCounts = [];
    private $lastUpdate = 0;
    
    public function __construct(int $totalFiles)
    {
        $this->startTime = microtime(true);
        $this->totalFiles = $totalFiles;
    }
    
    public function startFile(string $filename): void
    {
        $this->currentFile++;
        $this->recordCounts[$filename] = 0;
        echo "\n[{$this->currentFile}/{$this->totalFiles}] Processing: {$filename}\n";
        echo str_repeat('-', 50) . "\n";
    }
    
    public function updateProgress(string $filename, int $count): void
    {
        $this->recordCounts[$filename] = $count;
        
        // Update every 100 records or every second
        $now = microtime(true);
        if ($count % 100 === 0 || ($now - $this->lastUpdate) >= 1.0) {
            $this->lastUpdate = $now;
            $this->displayProgress($filename, $count);
        }
    }
    
    private function displayProgress(string $filename, int $count): void
    {
        $elapsed = microtime(true) - $this->startTime;
        $rate = $count > 0 ? round($count / $elapsed) : 0;
        $memory = $this->formatBytes(memory_get_usage());
        
        // Clear line and display progress
        echo "\r";
        echo sprintf(
            "Records: %d | Rate: %d/s | Memory: %s | Time: %s",
            $count,
            $rate,
            $memory,
            $this->formatTime($elapsed)
        );
    }
    
    public function completeFile(string $filename): void
    {
        echo "\n✓ Completed {$filename}: {$this->recordCounts[$filename]} records\n";
    }
    
    public function displaySummary(): void
    {
        $totalTime = microtime(true) - $this->startTime;
        $totalRecords = array_sum($this->recordCounts);
        
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "PROCESSING COMPLETE\n";
        echo str_repeat('=', 50) . "\n";
        echo "Total files processed: {$this->totalFiles}\n";
        echo "Total records: " . number_format($totalRecords) . "\n";
        echo "Total time: " . $this->formatTime($totalTime) . "\n";
        echo "Average rate: " . round($totalRecords / $totalTime) . " records/second\n";
        echo "Peak memory: " . $this->formatBytes(memory_get_peak_usage()) . "\n";
        echo "\nBreakdown by file:\n";
        
        foreach ($this->recordCounts as $file => $count) {
            echo "  - {$file}: " . number_format($count) . " records\n";
        }
    }
    
    private function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    private function formatTime($seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . round($seconds % 60) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }
}

// Configuration
$xmlFiles = [
    'Products.xml' => __DIR__ . '/xml-files/Products.xml',
    'Stock.xml' => __DIR__ . '/xml-files/Stock.xml',
    'Orders.xml' => __DIR__ . '/xml-files/Orders.xml',
];

$outputJson = __DIR__ . '/output/tracked_output.json';

// Initialize progress tracker
$tracker = new ProgressTracker(count($xmlFiles));

// Initialize merge transformer with progress callbacks
$merger = SqliteMergeTransformer::make();

// Define tables (simplified for example)
$merger->defineTable('Products', [
    'ProductId' => 'text',
    'ProductName' => 'text',
    'Price' => 'real'
], 'ProductId');

$merger->defineTable('Stock', [
    'ProductId' => 'text',
    'Quantity' => 'integer'
], 'ProductId');

$merger->defineTable('Orders', [
    'OrderId' => 'text',
    'ProductId' => 'text',
    'Quantity' => 'integer'
], 'OrderId');

// Process each XML file with progress tracking
foreach ($xmlFiles as $filename => $filepath) {
    $tracker->startFile($filename);
    
    // Determine element name from filename
    $elementName = rtrim($filename, '.xml');
    $elementName = rtrim($elementName, 's'); // Remove plural 's'
    
    // Create extractor with progress callback
    $extractor = StreamingXmlExtractor::make($filepath, $elementName)
        ->setProgressCallback(function ($count) use ($tracker, $filename) {
            $tracker->updateProgress($filename, $count);
        });
    
    // Process file
    foreach ($extractor->extract() as $frame) {
        if (!$frame->getEnd()) {
            $tableName = pathinfo($filename, PATHINFO_FILENAME);
            $frame->setAttribute(['table' => $tableName]);
            $merger($frame);
        }
    }
    
    $tracker->completeFile($filename);
}

echo "\nMerging data...\n";

// Trigger merge
$endFrame = new Frame();
$endFrame->setEnd();
$merger($endFrame);

// Write output with progress
echo "\nWriting output file...\n";
$jsonLoader = JsonLoader::make($outputJson)->setPrettyPrint(true);

$outputCount = 0;
foreach ($merger->getMergedData() as $row) {
    $frame = new Frame();
    $frame->setData($row);
    $jsonLoader->load($frame);
    $outputCount++;
    
    if ($outputCount % 100 === 0) {
        echo "\rWritten: " . number_format($outputCount) . " records";
    }
}

$jsonLoader->load($endFrame);
echo "\rWritten: " . number_format($outputCount) . " records\n";

// Display final summary
$tracker->displaySummary();
echo "\nOutput saved to: {$outputJson}\n";

// Example of FTP with progress tracking
echo "\n\n--- FTP Progress Example ---\n";
echo "Example code for FTP with progress tracking:\n\n";
echo <<<'PHP'
$ftpExtractor = FtpExtractor::make($host, $user, $pass, 'large_file.xml');

// Since FTP downloads the entire file first, track download progress
$ftpExtractor->setProgressCallback(function ($bytesDownloaded, $totalBytes) {
    $percent = round(($bytesDownloaded / $totalBytes) * 100);
    echo "\rDownloading: {$percent}%";
});

// Then use streaming XML extractor for processing
$tempFile = $ftpExtractor->getDownloadedFilePath();
$xmlExtractor = StreamingXmlExtractor::make($tempFile, 'Product')
    ->setProgressCallback(function ($count) use ($tracker) {
        $tracker->updateProgress('FTP File', $count);
    });
PHP;