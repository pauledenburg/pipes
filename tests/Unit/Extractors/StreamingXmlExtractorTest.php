<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Tests\Unit\Extractors;

use Exception;
use Jwhulette\Pipes\Extractors\StreamingXmlExtractor;
use Jwhulette\Pipes\Frame;
use PHPUnit\Framework\TestCase;

class StreamingXmlExtractorTest extends TestCase
{
    private string $testFilesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilesPath = __DIR__ . '/../../fixtures/xml';
        
        // Create test XML files directory if it doesn't exist
        if (!is_dir($this->testFilesPath)) {
            mkdir($this->testFilesPath, 0777, true);
        }
    }

    public function test_extract_simple_xml(): void
    {
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

        $testFile = $this->testFilesPath . '/simple_products.xml';
        file_put_contents($testFile, $xmlContent);

        $extractor = new StreamingXmlExtractor($testFile, 'Product');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (!$frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(2, $results);
        $this->assertEquals(['Id' => '1', 'Name' => 'Product 1', 'Price' => '10.99'], $results[0]);
        $this->assertEquals(['Id' => '2', 'Name' => 'Product 2', 'Price' => '20.99'], $results[1]);

        unlink($testFile);
    }

    public function test_extract_with_attributes(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product id="1" active="true">
        <Name>Product 1</Name>
        <Price currency="USD">10.99</Price>
    </Product>
    <Product id="2" active="false">
        <Name>Product 2</Name>
        <Price currency="EUR">20.99</Price>
    </Product>
</Products>';

        $testFile = $this->testFilesPath . '/products_with_attributes.xml';
        file_put_contents($testFile, $xmlContent);

        $extractor = new StreamingXmlExtractor($testFile, 'Product');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (!$frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(2, $results);
        $this->assertEquals('1', $results[0]['@id']);
        $this->assertEquals('true', $results[0]['@active']);
        $this->assertEquals('Product 1', $results[0]['Name']);
        $this->assertIsArray($results[0]['Price']);
        $this->assertEquals('USD', $results[0]['Price']['@currency']);
        $this->assertEquals('10.99', $results[0]['Price']['_value']);

        unlink($testFile);
    }

    public function test_extract_nested_elements(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product>
        <Id>1</Id>
        <Name>Product 1</Name>
        <Categories>
            <Category>Electronics</Category>
            <Category>Computers</Category>
        </Categories>
    </Product>
</Products>';

        $testFile = $this->testFilesPath . '/nested_products.xml';
        file_put_contents($testFile, $xmlContent);

        $extractor = new StreamingXmlExtractor($testFile, 'Product');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (!$frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(1, $results);
        $this->assertEquals('1', $results[0]['Id']);
        $this->assertIsArray($results[0]['Categories']['Category']);
        $this->assertCount(2, $results[0]['Categories']['Category']);
        $this->assertEquals('Electronics', $results[0]['Categories']['Category'][0]);
        $this->assertEquals('Computers', $results[0]['Categories']['Category'][1]);

        unlink($testFile);
    }

    public function test_progress_callback(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product><Id>1</Id></Product>
    <Product><Id>2</Id></Product>
    <Product><Id>3</Id></Product>
</Products>';

        $testFile = $this->testFilesPath . '/progress_test.xml';
        file_put_contents($testFile, $xmlContent);

        $progressCalls = [];
        $extractor = new StreamingXmlExtractor($testFile, 'Product');
        $extractor->setProgressCallback(function ($count) use (&$progressCalls) {
            $progressCalls[] = $count;
        });

        foreach ($extractor->extract() as $frame) {
            // Process frames
        }

        $this->assertEquals([1, 2, 3], $progressCalls);
        $this->assertEquals(3, $extractor->getRecordCount());

        unlink($testFile);
    }

    public function test_file_not_found_exception(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('XML file not found: /non/existent/file.xml');

        $extractor = new StreamingXmlExtractor('/non/existent/file.xml', 'Product');
        iterator_to_array($extractor->extract());
    }

    public function test_invalid_xml_exception(): void
    {
        $testFile = $this->testFilesPath . '/invalid.xml';
        file_put_contents($testFile, 'This is not valid XML');

        $extractor = new StreamingXmlExtractor($testFile, 'Product');

        try {
            // XMLReader will successfully open the file but fail when reading
            // So we need to iterate to trigger the actual parsing
            $frames = [];
            foreach ($extractor->extract() as $frame) {
                $frames[] = $frame;
            }
            // If we get here with only an end frame, that's expected for invalid XML
            $this->assertCount(1, $frames);
            $this->assertTrue($frames[0]->getEnd());
        } catch (Exception $e) {
            // This is also acceptable - different PHP versions may handle this differently
            $this->assertStringContainsString('XML', $e->getMessage());
        }

        unlink($testFile);
    }

    public function test_empty_elements_are_skipped(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product>
        <Id>1</Id>
        <Name>Product 1</Name>
    </Product>
    <Product></Product>
    <Product>
        <Id>2</Id>
        <Name>Product 2</Name>
    </Product>
</Products>';

        $testFile = $this->testFilesPath . '/empty_elements.xml';
        file_put_contents($testFile, $xmlContent);

        $extractor = new StreamingXmlExtractor($testFile, 'Product');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (!$frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(2, $results);
        $this->assertEquals('1', $results[0]['Id']);
        $this->assertEquals('2', $results[1]['Id']);

        unlink($testFile);
    }

    public function test_static_make_method(): void
    {
        $testFile = $this->testFilesPath . '/make_test.xml';
        file_put_contents($testFile, '<?xml version="1.0" encoding="UTF-8"?><root><item>test</item></root>');

        $extractor = StreamingXmlExtractor::make($testFile, 'item');
        $this->assertInstanceOf(StreamingXmlExtractor::class, $extractor);

        unlink($testFile);
    }

    public function test_end_frame_is_yielded(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product><Id>1</Id></Product>
</Products>';

        $testFile = $this->testFilesPath . '/end_frame_test.xml';
        file_put_contents($testFile, $xmlContent);

        $extractor = new StreamingXmlExtractor($testFile, 'Product');
        $frames = [];

        foreach ($extractor->extract() as $frame) {
            $frames[] = $frame;
        }

        $this->assertCount(2, $frames);
        $this->assertFalse($frames[0]->getEnd());
        $this->assertTrue($frames[1]->getEnd());

        unlink($testFile);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up any remaining test files
        $files = glob($this->testFilesPath . '/*.xml');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}