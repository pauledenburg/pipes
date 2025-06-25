<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Tests\Unit\Transformers;

use Jwhulette\Pipes\Frame;
use Jwhulette\Pipes\Transformers\XmlToArrayTransformer;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class XmlToArrayTransformerTest extends TestCase
{
    public function test_transform_simple_xml_string(): void
    {
        $transformer = new XmlToArrayTransformer();

        $frame = new Frame();
        $frame->setData([
            'id' => 1,
            'xml_data' => '<product><name>Test Product</name><price>10.99</price></product>',
            'normal_field' => 'unchanged',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertEquals(1, $data['id']);
        $this->assertEquals('unchanged', $data['normal_field']);
        $this->assertIsArray($data['xml_data']);
        $this->assertEquals('Test Product', $data['xml_data']['name']);
        $this->assertEquals('10.99', $data['xml_data']['price']);
    }

    public function test_transform_xml_with_attributes(): void
    {
        $transformer = new XmlToArrayTransformer();

        $frame = new Frame();
        $frame->setData([
            'xml_data' => '<product id="123" active="true"><name>Test</name><price currency="USD">10.99</price></product>',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertEquals('123', $data['xml_data']['@id']);
        $this->assertEquals('true', $data['xml_data']['@active']);
        $this->assertEquals('Test', $data['xml_data']['name']);
        $this->assertIsArray($data['xml_data']['price']);
        $this->assertEquals('USD', $data['xml_data']['price']['@currency']);
        $this->assertEquals('10.99', $data['xml_data']['price']['_value']);
    }

    public function test_transform_without_attributes(): void
    {
        $transformer = new XmlToArrayTransformer();
        $transformer->includeAttributes(false);

        $frame = new Frame();
        $frame->setData([
            'xml_data' => '<product id="123"><name>Test</name></product>',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertArrayNotHasKey('@id', $data['xml_data']);
        $this->assertEquals('Test', $data['xml_data']['name']);
    }

    public function test_transform_nested_xml(): void
    {
        $transformer = new XmlToArrayTransformer();

        $xml = '
        <product>
            <name>Test Product</name>
            <categories>
                <category>Electronics</category>
                <category>Computers</category>
            </categories>
        </product>';

        $frame = new Frame();
        $frame->setData(['xml_data' => $xml]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertEquals('Test Product', $data['xml_data']['name']);
        $this->assertIsArray($data['xml_data']['categories']['category']);
        $this->assertCount(2, $data['xml_data']['categories']['category']);
        $this->assertEquals('Electronics', $data['xml_data']['categories']['category'][0]);
        $this->assertEquals('Computers', $data['xml_data']['categories']['category'][1]);
    }

    public function test_transform_with_namespace(): void
    {
        $transformer = new XmlToArrayTransformer();
        $transformer->registerNamespace('p', 'http://example.com/product');

        $xml = '
        <root xmlns:p="http://example.com/product">
            <p:product>
                <p:name>Test Product</p:name>
                <p:price>10.99</p:price>
            </p:product>
        </root>';

        $frame = new Frame();
        $frame->setData(['xml_data' => $xml]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertArrayHasKey('p:product', $data['xml_data']);
        $this->assertEquals('Test Product', $data['xml_data']['p:product']['p:name']);
        $this->assertEquals('10.99', $data['xml_data']['p:product']['p:price']);
    }

    public function test_transform_simplexmlelement(): void
    {
        $transformer = new XmlToArrayTransformer();

        $xml = new SimpleXMLElement('<product><name>Test</name><price>10.99</price></product>');

        $frame = new Frame();
        $frame->setData(['xml_element' => $xml]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertIsArray($data['xml_element']);
        $this->assertEquals('Test', $data['xml_element']['name']);
        $this->assertEquals('10.99', $data['xml_element']['price']);
    }

    public function test_non_xml_string_unchanged(): void
    {
        $transformer = new XmlToArrayTransformer();

        $frame = new Frame();
        $frame->setData([
            'not_xml' => 'This is not XML',
            'also_not_xml' => '<incomplete',
            'number' => 123,
            'array' => ['test' => 'value'],
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertEquals('This is not XML', $data['not_xml']);
        $this->assertEquals('<incomplete', $data['also_not_xml']);
        $this->assertEquals(123, $data['number']);
        $this->assertEquals(['test' => 'value'], $data['array']);
    }

    public function test_custom_attribute_prefix(): void
    {
        $transformer = new XmlToArrayTransformer();
        $transformer->setAttributePrefix('attr_');

        $frame = new Frame();
        $frame->setData([
            'xml_data' => '<product id="123"><name>Test</name></product>',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertArrayNotHasKey('@id', $data['xml_data']);
        $this->assertEquals('123', $data['xml_data']['attr_id']);
    }

    public function test_custom_value_key(): void
    {
        $transformer = new XmlToArrayTransformer();
        $transformer->setValueKey('text');

        $frame = new Frame();
        $frame->setData([
            'xml_data' => '<price currency="USD">10.99</price>',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertArrayNotHasKey('_value', $data['xml_data']);
        $this->assertEquals('10.99', $data['xml_data']['text']);
    }

    public function test_do_not_flatten_single_elements(): void
    {
        $transformer = new XmlToArrayTransformer();
        $transformer->flattenSingleElements(false);

        $frame = new Frame();
        $frame->setData([
            'xml_data' => '<product><category>Electronics</category></product>',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertIsArray($data['xml_data']['category']);
        $this->assertCount(1, $data['xml_data']['category']);
        $this->assertEquals('Electronics', $data['xml_data']['category'][0]);
    }

    public function test_empty_xml_element(): void
    {
        $transformer = new XmlToArrayTransformer();

        $frame = new Frame();
        $frame->setData([
            'xml_data' => '<product><empty/></product>',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        $this->assertArrayHasKey('empty', $data['xml_data']);
        $this->assertEquals('', $data['xml_data']['empty']);
    }

    public function test_static_make_method(): void
    {
        $transformer = XmlToArrayTransformer::make();
        $this->assertInstanceOf(XmlToArrayTransformer::class, $transformer);
    }

    public function test_mixed_content(): void
    {
        $transformer = new XmlToArrayTransformer();

        $frame = new Frame();
        $frame->setData([
            'xml_data' => '<note>This is <bold>important</bold> text</note>',
        ]);

        $result = $transformer($frame);
        $data = $result->getData()->toArray();

        // Mixed content is challenging - the parser will handle this as best it can
        $this->assertArrayHasKey('bold', $data['xml_data']);
        $this->assertEquals('important', $data['xml_data']['bold']);
    }
}
