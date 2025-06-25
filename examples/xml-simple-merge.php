<?php

/**
 * Simple XML Merge Example
 * 
 * This example demonstrates how to merge data from multiple XML files
 * using the Pipes ETL framework with SQLite as a temporary merge database.
 */

require __DIR__ . '/../vendor/autoload.php';

use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\XmlExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Frame;

// Example XML files (you would have these from FTP or local storage)
$productsXml = __DIR__ . '/xml-files/Products.xml';
$stockXml = __DIR__ . '/xml-files/Stock.xml';
$groupsXml = __DIR__ . '/xml-files/Groups.xml';

// Output JSON file
$outputJson = __DIR__ . '/output/merged_products.json';

// Create SQLite merge transformer
$mergeTransformer = SqliteMergeTransformer::make()
    ->defineTable('products', [
        'EcommerceProductGuid' => 'text',
        'ProductName' => 'text',
        'Description' => 'text',
        'Price' => 'real',
        'GroupId' => 'integer'
    ], 'EcommerceProductGuid')
    ->defineTable('stock', [
        'EcommerceProductGuid' => 'text',
        'Quantity' => 'integer',
        'Warehouse' => 'text',
        'LastUpdated' => 'text'
    ], 'EcommerceProductGuid')
    ->defineTable('groups', [
        'GroupId' => 'integer',
        'GroupName' => 'text',
        'ParentGroupId' => 'integer'
    ], 'GroupId');

// Process Products XML
echo "Processing products...\n";
$productsExtractor = new XmlExtractor($productsXml, 'Product');
foreach ($productsExtractor->extract() as $frame) {
    if (!$frame->getEnd()) {
        $frame->setAttribute(['table' => 'products']);
        $mergeTransformer($frame);
    }
}

// Process Stock XML
echo "Processing stock...\n";
$stockExtractor = new XmlExtractor($stockXml, 'StockItem');
foreach ($stockExtractor->extract() as $frame) {
    if (!$frame->getEnd()) {
        $frame->setAttribute(['table' => 'stock']);
        $mergeTransformer($frame);
    }
}

// Process Groups XML
echo "Processing groups...\n";
$groupsExtractor = new XmlExtractor($groupsXml, 'Group');
foreach ($groupsExtractor->extract() as $frame) {
    if (!$frame->getEnd()) {
        $frame->setAttribute(['table' => 'groups']);
        $mergeTransformer($frame);
    }
}

// Trigger the merge
echo "Merging data...\n";
$endFrame = new Frame();
$endFrame->setEnd();
$mergeTransformer($endFrame);

// Write merged data to JSON
echo "Writing output to JSON...\n";
$jsonLoader = new JsonLoader($outputJson);
$jsonLoader->setPrettyPrint(true);

$recordCount = 0;
foreach ($mergeTransformer->getMergedData() as $row) {
    $frame = new Frame();
    $frame->setData($row);
    $jsonLoader->load($frame);
    $recordCount++;
}

// Close the JSON file
$jsonLoader->load($endFrame);

echo "Done! Merged {$recordCount} products to {$outputJson}\n";

// The SQLite database will be automatically cleaned up