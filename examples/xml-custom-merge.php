<?php

/**
 * Custom Merge Logic Example
 * 
 * This example demonstrates how to implement custom merge logic
 * when combining data from multiple XML sources.
 */

require __DIR__ . '/../vendor/autoload.php';

use Jwhulette\Pipes\Extractors\XmlExtractor;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Frame;

// Input files
$productsXml = __DIR__ . '/xml-files/Products.xml';
$pricesXml = __DIR__ . '/xml-files/Prices.xml'; // Multiple prices per product
$categoriesXml = __DIR__ . '/xml-files/Categories.xml';

// Create custom merge transformer
class CustomMergeTransformer extends SqliteMergeTransformer
{
    /**
     * Override merge query to implement custom logic
     */
    protected function buildMergeQuery(): string
    {
        // Custom SQL that aggregates multiple prices per product
        return "
            SELECT 
                p.EcommerceProductGuid,
                p.ProductName,
                p.Description,
                c.CategoryName,
                c.CategoryPath,
                MIN(pr.Price) as MinPrice,
                MAX(pr.Price) as MaxPrice,
                AVG(pr.Price) as AvgPrice,
                GROUP_CONCAT(pr.Currency || ':' || pr.Price) as AllPrices
            FROM products p
            LEFT JOIN categories c ON p.CategoryId = c.CategoryId
            LEFT JOIN prices pr ON p.EcommerceProductGuid = pr.EcommerceProductGuid
            GROUP BY p.EcommerceProductGuid
            ORDER BY p.ProductName
        ";
    }
}

// Initialize custom merger
$merger = new CustomMergeTransformer();

// Define table schemas
$merger->defineTable('products', [
    'EcommerceProductGuid' => 'text',
    'ProductName' => 'text',
    'Description' => 'text',
    'CategoryId' => 'integer'
], 'EcommerceProductGuid');

$merger->defineTable('prices', [
    'EcommerceProductGuid' => 'text',
    'Price' => 'real',
    'Currency' => 'text',
    'PriceType' => 'text'
], 'EcommerceProductGuid');

$merger->defineTable('categories', [
    'CategoryId' => 'integer',
    'CategoryName' => 'text',
    'CategoryPath' => 'text',
    'ParentCategoryId' => 'integer'
], 'CategoryId');

echo "Loading products...\n";
$productCount = 0;
foreach ((new XmlExtractor($productsXml, 'Product'))->extract() as $frame) {
    if (!$frame->getEnd()) {
        $frame->setAttribute(['table' => 'products']);
        $merger($frame);
        $productCount++;
    }
}
echo "Loaded {$productCount} products\n";

echo "Loading prices...\n";
$priceCount = 0;
foreach ((new XmlExtractor($pricesXml, 'Price'))->extract() as $frame) {
    if (!$frame->getEnd()) {
        $frame->setAttribute(['table' => 'prices']);
        $merger($frame);
        $priceCount++;
    }
}
echo "Loaded {$priceCount} prices\n";

echo "Loading categories...\n";
$categoryCount = 0;
foreach ((new XmlExtractor($categoriesXml, 'Category'))->extract() as $frame) {
    if (!$frame->getEnd()) {
        $frame->setAttribute(['table' => 'categories']);
        $merger($frame);
        $categoryCount++;
    }
}
echo "Loaded {$categoryCount} categories\n";

// Trigger merge
$endFrame = new Frame();
$endFrame->setEnd();
$merger($endFrame);

// Output to JSON with custom formatting
$outputJson = __DIR__ . '/output/custom_merged.json';
$jsonLoader = JsonLoader::make($outputJson)->setPrettyPrint(true);

echo "\nMerging and formatting data...\n";

foreach ($merger->getMergedData() as $row) {
    // Additional data transformation
    $formattedRow = [
        'guid' => $row['EcommerceProductGuid'],
        'name' => $row['ProductName'],
        'description' => $row['Description'],
        'category' => [
            'name' => $row['CategoryName'] ?? 'Uncategorized',
            'path' => $row['CategoryPath'] ?? '/'
        ],
        'pricing' => [
            'min' => round($row['MinPrice'], 2),
            'max' => round($row['MaxPrice'], 2),
            'average' => round($row['AvgPrice'], 2),
            'all_prices' => $row['AllPrices'] ? explode(',', $row['AllPrices']) : []
        ]
    ];
    
    $frame = new Frame();
    $frame->setData($formattedRow);
    $jsonLoader->load($frame);
}

$jsonLoader->load($endFrame);

echo "Custom merge complete! Output saved to: {$outputJson}\n";

// Example of accessing merge statistics
$mergedCount = $merger->getMergedCount();
echo "Total merged products: {$mergedCount}\n";