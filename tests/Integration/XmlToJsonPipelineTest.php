<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Tests\Integration;

use Jwhulette\Pipes\EtlPipe;
use Jwhulette\Pipes\Extractors\StreamingXmlExtractor;
use Jwhulette\Pipes\Extractors\XmlExtractor;
use Jwhulette\Pipes\Frame;
use Jwhulette\Pipes\Loaders\CleanupLoader;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use Jwhulette\Pipes\Transformers\XmlToArrayTransformer;
use PHPUnit\Framework\TestCase;

class XmlToJsonPipelineTest extends TestCase
{
    private string $testDir;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/pipes_xml_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temp files
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Remove test directory
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testDir);
        }
    }

    public function test_simple_xml_to_json_pipeline(): void
    {
        // Create test XML file
        $xmlFile = $this->testDir . '/products.xml';
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product>
        <Id>1</Id>
        <Name>Product 1</Name>
        <Price>10.99</Price>
    </Product>
    <Product>
        <Id>2</Id>
        <Name>Product 2</Name>
        <Price>20.99</Price>
    </Product>
</Products>';
        file_put_contents($xmlFile, $xmlContent);
        $this->tempFiles[] = $xmlFile;

        // Create JSON output file
        $jsonFile = $this->testDir . '/output.json';
        $this->tempFiles[] = $jsonFile;

        // Build pipeline
        $pipe = new EtlPipe();
        $pipe->extract(new XmlExtractor($xmlFile, 'Product'))
            ->transform([new XmlToArrayTransformer()])
            ->load(new JsonLoader($jsonFile));

        // Run pipeline
        $pipe->run();

        // Verify output
        $this->assertFileExists($jsonFile);
        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);

        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertEquals('1', $data[0]['Id']);
        $this->assertEquals('Product 1', $data[0]['Name']);
        $this->assertEquals('10.99', $data[0]['Price']);
    }

    public function test_merge_multiple_xml_files(): void
    {
        // Create Products XML
        $productsXml = $this->testDir . '/products.xml';
        file_put_contents($productsXml, '<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product>
        <Guid>ABC123</Guid>
        <Name>Product A</Name>
        <Category>Electronics</Category>
    </Product>
    <Product>
        <Guid>DEF456</Guid>
        <Name>Product B</Name>
        <Category>Books</Category>
    </Product>
</Products>');
        $this->tempFiles[] = $productsXml;

        // Create Stock XML
        $stockXml = $this->testDir . '/stock.xml';
        file_put_contents($stockXml, '<?xml version="1.0" encoding="UTF-8"?>
<StockItems>
    <Stock>
        <Guid>ABC123</Guid>
        <Quantity>100</Quantity>
        <Warehouse>Main</Warehouse>
    </Stock>
    <Stock>
        <Guid>DEF456</Guid>
        <Quantity>50</Quantity>
        <Warehouse>Secondary</Warehouse>
    </Stock>
</StockItems>');
        $this->tempFiles[] = $stockXml;

        // Create SQLite merge transformer
        $dbPath = $this->testDir . '/merge.db';
        $this->tempFiles[] = $dbPath;

        $mergeTransformer = new SqliteMergeTransformer($dbPath);
        $mergeTransformer->defineTable('products', [
            'Guid' => 'text',
            'Name' => 'text',
            'Category' => 'text',
        ], 'Guid');

        $mergeTransformer->defineTable('stock', [
            'Guid' => 'text',
            'Quantity' => 'integer',
            'Warehouse' => 'text',
        ], 'Guid');

        // Create JSON output
        $jsonFile = $this->testDir . '/merged.json';
        $this->tempFiles[] = $jsonFile;

        // Process products
        $productsExtractor = new XmlExtractor($productsXml, 'Product');
        foreach ($productsExtractor->extract() as $frame) {
            if (! $frame->getEnd()) {
                $frame->setAttribute(['table' => 'products']);
                $mergeTransformer($frame);
            }
        }

        // Process stock
        $stockExtractor = new XmlExtractor($stockXml, 'Stock');
        foreach ($stockExtractor->extract() as $frame) {
            if (! $frame->getEnd()) {
                $frame->setAttribute(['table' => 'stock']);
                $mergeTransformer($frame);
            }
        }

        // Trigger merge
        $endFrame = new Frame();
        $endFrame->setEnd();
        $mergeTransformer($endFrame);

        // Write merged data to JSON
        $jsonLoader = new JsonLoader($jsonFile);
        foreach ($mergeTransformer->getMergedData() as $row) {
            $frame = new Frame();
            $frame->setData($row);
            $jsonLoader->load($frame);
        }

        // Send end frame to close JSON
        $jsonLoader->load($endFrame);

        // Verify merged output
        $this->assertFileExists($jsonFile);
        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);

        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        // Check first product
        $product1 = $data[0];
        $this->assertEquals('ABC123', $product1['Guid']);
        $this->assertEquals('Product A', $product1['Name']);
        $this->assertEquals('Electronics', $product1['Category']);
        $this->assertEquals(100, $product1['Quantity']);
        $this->assertEquals('Main', $product1['Warehouse']);

        // Check second product
        $product2 = $data[1];
        $this->assertEquals('DEF456', $product2['Guid']);
        $this->assertEquals('Product B', $product2['Name']);
        $this->assertEquals('Books', $product2['Category']);
        $this->assertEquals(50, $product2['Quantity']);
        $this->assertEquals('Secondary', $product2['Warehouse']);
    }

    public function test_large_xml_streaming(): void
    {
        // Create a large XML file with many products
        $largeXmlFile = $this->testDir . '/large_products.xml';
        $this->tempFiles[] = $largeXmlFile;

        $handle = fopen($largeXmlFile, 'w');
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($handle, '<Products>' . "\n");

        // Write 1000 products
        for ($i = 1; $i <= 1000; $i++) {
            $xml = "    <Product>\n";
            $xml .= "        <Id>{$i}</Id>\n";
            $xml .= "        <Name>Product {$i}</Name>\n";
            $xml .= '        <Price>' . number_format($i * 0.99, 2) . "</Price>\n";
            $xml .= "        <Description>This is a description for product {$i}</Description>\n";
            $xml .= "    </Product>\n";
            fwrite($handle, $xml);
        }

        fwrite($handle, '</Products>');
        fclose($handle);

        // Create JSON output
        $jsonFile = $this->testDir . '/large_output.json';
        $this->tempFiles[] = $jsonFile;

        // Use streaming extractor
        $pipe = new EtlPipe();
        $pipe->extract(new StreamingXmlExtractor($largeXmlFile, 'Product'))
            ->load(JsonLoader::make($jsonFile)->asNdjson());

        // Track memory usage
        $startMemory = memory_get_usage();

        // Run pipeline
        $pipe->run();

        $endMemory = memory_get_usage();
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // MB

        // Memory usage should be reasonable (less than 50MB for 1000 products)
        $this->assertLessThan(50, $memoryUsed, "Memory usage too high: {$memoryUsed}MB");

        // Verify output
        $this->assertFileExists($jsonFile);

        // Count lines in NDJSON file
        $lineCount = 0;
        $handle = fopen($jsonFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $lineCount++;
                }
            }
            fclose($handle);
        }

        $this->assertEquals(1000, $lineCount);
    }

    public function test_cleanup_loader_integration(): void
    {
        // Create test files
        $xmlFile = $this->testDir . '/test.xml';
        file_put_contents($xmlFile, '<?xml version="1.0"?><root><item>test</item></root>');
        $this->tempFiles[] = $xmlFile;

        $jsonFile = $this->testDir . '/output.json';
        $this->tempFiles[] = $jsonFile;

        $tempDbFile = $this->testDir . '/temp.db';

        // Create cleanup loader
        $jsonLoader = new JsonLoader($jsonFile);
        $cleanupLoader = CleanupLoader::wrap($jsonLoader);

        // Add cleanup for temp database
        $cleanupLoader->addCleanupCallback(function () use ($tempDbFile): void {
            if (file_exists($tempDbFile)) {
                unlink($tempDbFile);
            }
        });

        // Create temp database file to test cleanup
        file_put_contents($tempDbFile, 'temp data');
        $this->assertFileExists($tempDbFile);

        // Run pipeline
        $pipe = new EtlPipe();
        $pipe->extract(new XmlExtractor($xmlFile, 'item'))
            ->load($cleanupLoader);

        $pipe->run();

        // Verify JSON was created
        $this->assertFileExists($jsonFile);

        // Verify temp database was cleaned up
        $this->assertFileDoesNotExist($tempDbFile);
    }

    public function test_xml_with_attributes_and_namespaces(): void
    {
        // Create XML with attributes and namespaces
        $xmlFile = $this->testDir . '/complex.xml';
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<catalog xmlns:p="http://example.com/products" xmlns:inv="http://example.com/inventory">
    <p:product id="1" active="true">
        <p:name>Product 1</p:name>
        <p:price currency="USD">10.99</p:price>
        <inv:stock warehouse="main">100</inv:stock>
    </p:product>
    <p:product id="2" active="false">
        <p:name>Product 2</p:name>
        <p:price currency="EUR">20.99</p:price>
        <inv:stock warehouse="secondary">50</inv:stock>
    </p:product>
</catalog>';
        file_put_contents($xmlFile, $xmlContent);
        $this->tempFiles[] = $xmlFile;

        $jsonFile = $this->testDir . '/complex_output.json';
        $this->tempFiles[] = $jsonFile;

        // Create extractor with namespace support
        $extractor = XmlExtractor::make($xmlFile, '//p:product');
        $extractor->registerNamespace('p', 'http://example.com/products');
        $extractor->registerNamespace('inv', 'http://example.com/inventory');

        // Create transformer with namespace support
        $transformer = XmlToArrayTransformer::make();
        $transformer->registerNamespace('p', 'http://example.com/products');
        $transformer->registerNamespace('inv', 'http://example.com/inventory');

        // Build pipeline
        $pipe = new EtlPipe();
        $pipe->extract($extractor)
            ->transform([$transformer])
            ->load(new JsonLoader($jsonFile));

        $pipe->run();

        // Verify output
        $this->assertFileExists($jsonFile);
        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);

        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        // Check attributes
        $this->assertEquals('1', $data[0]['@id']);
        $this->assertEquals('true', $data[0]['@active']);

        // Check namespaced elements
        $this->assertEquals('Product 1', $data[0]['p:name']);
        $this->assertIsArray($data[0]['p:price']);
        $this->assertEquals('USD', $data[0]['p:price']['@currency']);
        $this->assertEquals('10.99', $data[0]['p:price']['_value']);
    }
}
