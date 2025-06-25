<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Tests\Unit\Extractors;

use Exception;
use Jwhulette\Pipes\Extractors\XmlExtractor;
use Jwhulette\Pipes\Frame;
use PHPUnit\Framework\TestCase;

class XmlExtractorTest extends TestCase
{
    private string $testFilesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilesPath = __DIR__ . '/../../fixtures/xml';

        // Create test XML files directory if it doesn't exist
        if (! is_dir($this->testFilesPath)) {
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

        $extractor = new XmlExtractor($testFile, 'Product');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (! $frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(2, $results);
        $this->assertEquals(['Id' => '1', 'Name' => 'Product 1', 'Price' => '10.99'], $results[0]);
        $this->assertEquals(['Id' => '2', 'Name' => 'Product 2', 'Price' => '20.99'], $results[1]);

        unlink($testFile);
    }

    public function test_extract_with_xpath(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<Catalog>
    <Products>
        <Product>
            <Id>1</Id>
            <Name>Product 1</Name>
        </Product>
        <Product>
            <Id>2</Id>
            <Name>Product 2</Name>
        </Product>
    </Products>
    <Other>
        <Product>
            <Id>3</Id>
            <Name>Should not be extracted</Name>
        </Product>
    </Other>
</Catalog>';

        $testFile = $this->testFilesPath . '/xpath_products.xml';
        file_put_contents($testFile, $xmlContent);

        $extractor = new XmlExtractor($testFile, '/Catalog/Products/Product');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (! $frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(2, $results);
        $this->assertEquals('1', $results[0]['Id']);
        $this->assertEquals('2', $results[1]['Id']);

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

        $extractor = new XmlExtractor($testFile, 'Product');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (! $frame->getEnd()) {
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

    public function test_extract_with_namespaces(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<catalog xmlns:p="http://example.com/products">
    <p:Product>
        <p:Id>1</p:Id>
        <p:Name>Product 1</p:Name>
    </p:Product>
    <p:Product>
        <p:Id>2</p:Id>
        <p:Name>Product 2</p:Name>
    </p:Product>
</catalog>';

        $testFile = $this->testFilesPath . '/namespaced_products.xml';
        file_put_contents($testFile, $xmlContent);

        $extractor = new XmlExtractor($testFile, '//p:Product');
        $extractor->registerNamespace('p', 'http://example.com/products');
        $results = [];

        foreach ($extractor->extract() as $frame) {
            if (! $frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(2, $results);
        $this->assertEquals('1', $results[0]['p:Id']);
        $this->assertEquals('Product 1', $results[0]['p:Name']);

        unlink($testFile);
    }

    public function test_file_size_limit(): void
    {
        // Create a file that's just over 1 byte
        $testFile = $this->testFilesPath . '/large_file.xml';
        file_put_contents($testFile, '<?xml version="1.0"?><root>test</root>');

        $extractor = new XmlExtractor($testFile, 'root');
        $extractor->setMaxFileSize(0); // 0 MB = 0 bytes

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/XML file too large/');

        iterator_to_array($extractor->extract());

        unlink($testFile);
    }

    public function test_disable_file_size_check(): void
    {
        $testFile = $this->testFilesPath . '/any_size.xml';
        file_put_contents($testFile, '<?xml version="1.0"?><root><item>test</item></root>');

        $extractor = new XmlExtractor($testFile, 'item');
        $extractor->setMaxFileSize(0)->disableFileSizeCheck();

        $results = [];
        foreach ($extractor->extract() as $frame) {
            if (! $frame->getEnd()) {
                $results[] = $frame->getData()->toArray();
            }
        }

        $this->assertCount(1, $results);
        $this->assertEquals(['_value' => 'test'], $results[0]);

        unlink($testFile);
    }

    public function test_file_not_found_exception(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('XML file not found: /non/existent/file.xml');

        $extractor = new XmlExtractor('/non/existent/file.xml', 'Product');
        iterator_to_array($extractor->extract());
    }

    public function test_invalid_xml_exception(): void
    {
        $testFile = $this->testFilesPath . '/invalid.xml';
        file_put_contents($testFile, 'This is not valid XML');

        $extractor = new XmlExtractor($testFile, 'Product');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to parse XML file/');

        iterator_to_array($extractor->extract());

        unlink($testFile);
    }

    public function test_invalid_xpath_exception(): void
    {
        $testFile = $this->testFilesPath . '/xpath_error.xml';
        file_put_contents($testFile, '<?xml version="1.0"?><root></root>');

        // Create an invalid XPath expression
        $extractor = new XmlExtractor($testFile, '//[invalid');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Invalid XPath expression/');

        iterator_to_array($extractor->extract());

        unlink($testFile);
    }

    public function test_static_make_method(): void
    {
        $testFile = $this->testFilesPath . '/make_test.xml';
        file_put_contents($testFile, '<?xml version="1.0" encoding="UTF-8"?><root><item>test</item></root>');

        $extractor = XmlExtractor::make($testFile, 'item');
        $this->assertInstanceOf(XmlExtractor::class, $extractor);

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

        $extractor = new XmlExtractor($testFile, 'Product');
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
