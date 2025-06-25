<?php

/**
 * Test XML Files to JSON
 * 
 * This script processes the XML files in examples/xml-files/ directory
 * and converts them to JSON format, demonstrating the merge functionality.
 */

require __DIR__ . '/../vendor/autoload.php';

use Jwhulette\Pipes\Extractors\XmlExtractor;
use Jwhulette\Pipes\Extractors\StreamingXmlExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Frame;

// Define paths
$xmlDir = __DIR__ . '/xml-files';
$outputDir = __DIR__ . '/output';

// Create output directory if it doesn't exist
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

echo "=== XML to JSON Test Script ===\n\n";

// 1. First, let's convert each XML file to JSON individually
echo "Step 1: Converting individual XML files to JSON...\n";

// Process Products.xml
echo "- Processing Products.xml...\n";
$productsExtractor = new XmlExtractor($xmlDir . '/Products.xml', 'Product');
$productsJson = JsonLoader::make($outputDir . '/products.json')->setPrettyPrint(true);

$productCount = 0;
foreach ($productsExtractor->extract() as $frame) {
    if (!$frame->getEnd()) {
        $productsJson->load($frame);
        $productCount++;
    } else {
        $productsJson->load($frame);
    }
}
echo "  Found {$productCount} products\n";

// Process Stock.xml - using path to get Product elements
echo "- Processing Stock.xml...\n";
$stockExtractor = new XmlExtractor($xmlDir . '/Stock.xml', '//Products/Product');
$stockJson = JsonLoader::make($outputDir . '/stock.json')->setPrettyPrint(true);

$stockCount = 0;
foreach ($stockExtractor->extract() as $frame) {
    if (!$frame->getEnd()) {
        $stockJson->load($frame);
        $stockCount++;
    } else {
        $stockJson->load($frame);
    }
}
echo "  Found {$stockCount} stock items\n";

// Process Groups.xml - this seems to be a large file
echo "- Processing Groups.xml (using streaming)...\n";
$groupsExtractor = new StreamingXmlExtractor($xmlDir . '/Groups.xml', 'Group');
$groupsJson = JsonLoader::make($outputDir . '/groups.json')->setPrettyPrint(true);

$groupCount = 0;
$groupsExtractor->setProgressCallback(function ($count) use (&$groupCount) {
    $groupCount = $count;
    if ($count % 10 === 0) {
        echo "\r  Processing groups: {$count}";
    }
});

foreach ($groupsExtractor->extract() as $frame) {
    if (!$frame->getEnd()) {
        $groupsJson->load($frame);
    } else {
        $groupsJson->load($frame);
        echo "\r  Found {$groupCount} groups\n";
    }
}

// 2. Now let's merge Products and Stock data based on EcommerceProductGuid
echo "\nStep 2: Merging Products and Stock data...\n";

// First, let's flatten the complex XML structure
$productTransformer = function(Frame $frame): Frame {
    $data = $frame->getData()->toArray();
    
    // Extract simple fields from the complex structure
    $flattened = [
        'EcommerceProductGuid' => $data['EcommerceProductGuid'] ?? '',
        'ProductNumber' => $data['ProductNumber'] ?? '',
        'Type' => $data['Type'] ?? '',
        'Brand' => $data['Brand'] ?? '',
        'SmallInfo' => $data['SmallInfo'] ?? '',
        'BigInfo' => $data['BigInfo'] ?? '',
        'Visible' => $data['Visible'] ?? 'True',
        'StockProduct' => $data['StockProduct'] ?? 'True'
    ];
    
    // Extract price from ProductVariations if available
    if (isset($data['ProductVariations']['ProductVariation'])) {
        $variation = $data['ProductVariations']['ProductVariation'];
        $flattened['SalesPriceInc'] = $variation['SalesPriceInc'] ?? '0';
        $flattened['Color'] = $variation['Color'] ?? '';
    }
    
    $frame->setData($flattened);
    return $frame;
};

$merger = SqliteMergeTransformer::make($outputDir . '/merge_temp.db')
    ->defineTable('products', [
        'EcommerceProductGuid' => 'text',
        'ProductNumber' => 'text',
        'Type' => 'text',
        'Brand' => 'text',
        'SmallInfo' => 'text',
        'BigInfo' => 'text',
        'Visible' => 'text',
        'StockProduct' => 'text',
        'SalesPriceInc' => 'real',
        'Color' => 'text'
    ], 'EcommerceProductGuid')
    ->defineTable('stock', [
        'EcommerceProductGuid' => 'text',
        'ProductId' => 'text',
        'Quantity' => 'real',
        'Reserved' => 'real',
        'LastPurchaseDate' => 'text',
        'AvailabilityStatus' => 'text'
    ], 'EcommerceProductGuid');

// Load products into merger
echo "- Loading products data...\n";
$productsExtractor2 = new XmlExtractor($xmlDir . '/Products.xml', 'Product');
foreach ($productsExtractor2->extract() as $frame) {
    if (!$frame->getEnd()) {
        // Apply transformer to flatten the data
        $frame = $productTransformer($frame);
        $frame->setAttribute(['table' => 'products']);
        $merger($frame);
    }
}

// Load stock into merger
echo "- Loading stock data...\n";
$stockExtractor2 = new XmlExtractor($xmlDir . '/Stock.xml', '//Products/Product');
foreach ($stockExtractor2->extract() as $frame) {
    if (!$frame->getEnd()) {
        $frame->setAttribute(['table' => 'stock']);
        $merger($frame);
    }
}

// Trigger merge
echo "- Merging data...\n";
$endFrame = new Frame();
$endFrame->setEnd();
$merger($endFrame);

// Save merged data
$mergedJson = JsonLoader::make($outputDir . '/merged_products_stock.json')->setPrettyPrint(true);
$mergedCount = 0;

foreach ($merger->getMergedData() as $row) {
    $frame = new Frame();
    $frame->setData($row);
    $mergedJson->load($frame);
    $mergedCount++;
}
$mergedJson->load($endFrame);

echo "  Merged {$mergedCount} products with stock data\n";

// 3. Create a sample with first 10 merged items for easy viewing
echo "\nStep 3: Creating sample output...\n";
$sampleJson = JsonLoader::make($outputDir . '/sample_merged.json')->setPrettyPrint(true);
$sampleCount = 0;

foreach ($merger->getMergedData() as $row) {
    if ($sampleCount >= 10) break;
    
    // Clean up HTML entities in the output for readability
    if (isset($row['SmallInfo'])) {
        $row['SmallInfo'] = html_entity_decode($row['SmallInfo']);
    }
    if (isset($row['BigInfo'])) {
        $row['BigInfo'] = html_entity_decode($row['BigInfo']);
    }
    
    $frame = new Frame();
    $frame->setData($row);
    $sampleJson->load($frame);
    $sampleCount++;
}
$sampleJson->load($endFrame);

// Clean up temporary database
$merger->cleanup();

echo "\n=== Processing Complete ===\n";
echo "\nOutput files created in: {$outputDir}/\n";
echo "- products.json ({$productCount} items)\n";
echo "- stock.json ({$stockCount} items)\n";
echo "- groups.json ({$groupCount} items)\n";
echo "- merged_products_stock.json ({$mergedCount} items)\n";
echo "- sample_merged.json (first 10 merged items)\n";

// Display first merged item as example
echo "\nExample merged item:\n";
$sampleData = json_decode(file_get_contents($outputDir . '/sample_merged.json'), true);
if (!empty($sampleData)) {
    $firstItem = $sampleData[0];
    echo "Product: " . ($firstItem['Type'] ?? 'N/A') . "\n";
    echo "Brand: " . ($firstItem['Brand'] ?? 'N/A') . "\n";
    echo "Product Number: " . ($firstItem['ProductNumber'] ?? 'N/A') . "\n";
    echo "Quantity: " . ($firstItem['Quantity'] ?? 'N/A') . "\n";
    echo "Status: " . ($firstItem['AvailabilityStatus'] ?? 'N/A') . "\n";
}