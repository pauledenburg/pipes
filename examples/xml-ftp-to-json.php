<?php

/**
 * FTP XML to JSON Example
 * 
 * This example demonstrates downloading XML files from an FTP server,
 * merging them, and outputting the result as JSON.
 */

require __DIR__ . '/../vendor/autoload.php';

use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\FtpExtractor;
use Jwhulette\Pipes\Loaders\CleanupLoader;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Frame;

// FTP Configuration
$ftpHost = 'ftp.example.com';
$ftpUsername = 'username';
$ftpPassword = 'password';

// Files to download
$remoteFiles = [
    '/export/Products.xml',
    '/export/Stock.xml',
    '/export/Groups.xml'
];

// Output configuration
$outputJson = __DIR__ . '/output/ftp_merged_products.json';
$tempDbPath = sys_get_temp_dir() . '/merge_' . uniqid() . '.db';

echo "Connecting to FTP server...\n";

// Create merge transformer with table definitions
$mergeTransformer = new SqliteMergeTransformer($tempDbPath);
$mergeTransformer
    ->defineTable('Products', [
        'EcommerceProductGuid' => 'text',
        'ProductName' => 'text',
        'Description' => 'text',
        'Price' => 'real',
        'GroupId' => 'integer'
    ], 'EcommerceProductGuid')
    ->defineTable('Stock', [
        'EcommerceProductGuid' => 'text',
        'Quantity' => 'integer',
        'Warehouse' => 'text'
    ], 'EcommerceProductGuid')
    ->defineTable('Groups', [
        'GroupId' => 'integer',
        'GroupName' => 'text'
    ], 'GroupId')
    ->setProgressCallback(function ($count) {
        if ($count % 100 === 0) {
            echo "Processed {$count} records...\n";
        }
    });

// Create JSON loader with cleanup
$jsonLoader = JsonLoader::make($outputJson)->setPrettyPrint(true);
$cleanupLoader = CleanupLoader::wrap($jsonLoader);
$cleanupLoader->addCleanupCallback(function () use ($tempDbPath) {
    if (file_exists($tempDbPath)) {
        echo "Cleaning up temporary database...\n";
        unlink($tempDbPath);
    }
});

// Process each file from FTP
foreach ($remoteFiles as $remoteFile) {
    echo "Downloading and processing: {$remoteFile}\n";
    
    $ftpExtractor = FtpExtractor::make($ftpHost, $ftpUsername, $ftpPassword, $remoteFile)
        ->setPassive(true)
        ->setTimeout(300); // 5 minute timeout for large files
    
    // Process the file
    foreach ($ftpExtractor->extract() as $frame) {
        if (!$frame->getEnd()) {
            // FtpExtractor automatically sets table attribute for XML files
            $mergeTransformer($frame);
        }
    }
}

echo "\nMerging data...\n";

// Trigger merge
$endFrame = new Frame();
$endFrame->setEnd();
$mergeTransformer($endFrame);

// Write merged data to JSON
echo "Writing merged data to JSON...\n";
$recordCount = 0;
foreach ($mergeTransformer->getMergedData() as $row) {
    $frame = new Frame();
    $frame->setData($row);
    $cleanupLoader->load($frame);
    $recordCount++;
}

// Close JSON and trigger cleanup
$cleanupLoader->load($endFrame);

echo "\n=== Complete ===\n";
echo "Merged {$recordCount} products\n";
echo "Output saved to: {$outputJson}\n";

// Alternative: Using pipeline approach for single file
echo "\n\n--- Alternative Pipeline Approach ---\n";

$singleFilePipe = new EtlPipe();
$singleFilePipe
    ->extract(
        FtpExtractor::make($ftpHost, $ftpUsername, $ftpPassword, '/export/Products.xml')
    )
    ->transform([
        // You can add transformers here if needed
    ])
    ->load(
        JsonLoader::make(__DIR__ . '/output/single_file.json')->asNdjson()
    );

// Uncomment to run:
// $singleFilePipe->run();