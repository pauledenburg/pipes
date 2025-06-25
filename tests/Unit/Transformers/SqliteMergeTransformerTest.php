<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Tests\Unit\Transformers;

use Exception;
use Jwhulette\Pipes\Frame;
use Jwhulette\Pipes\Transformers\SqliteMergeTransformer;
use PHPUnit\Framework\TestCase;

class SqliteMergeTransformerTest extends TestCase
{
    private string $testDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDbPath = sys_get_temp_dir() . '/test_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
    }

    public function test_single_table_transform(): void
    {
        $transformer = new SqliteMergeTransformer();
        $transformer->defineTable('products', [
            'id' => 'integer',
            'name' => 'text',
            'price' => 'real'
        ], 'id');

        $frame1 = new Frame();
        $frame1->setData(['id' => 1, 'name' => 'Product 1', 'price' => 10.99]);
        $frame1->setAttribute(['table' => 'products']);

        $frame2 = new Frame();
        $frame2->setData(['id' => 2, 'name' => 'Product 2', 'price' => 20.99]);
        $frame2->setAttribute(['table' => 'products']);

        $endFrame = new Frame();
        $endFrame->setEnd();

        // Process frames
        $transformer($frame1);
        $transformer($frame2);
        $transformer($endFrame);

        // Check merged data
        $mergedData = iterator_to_array($transformer->getMergedData());
        $this->assertCount(2, $mergedData);
        $this->assertEquals(1, $mergedData[0]['id']);
        $this->assertEquals('Product 1', $mergedData[0]['name']);
        $this->assertEquals(2, $mergedData[1]['id']);
    }

    public function test_multiple_tables_merge(): void
    {
        $transformer = new SqliteMergeTransformer();
        
        $transformer->defineTable('products', [
            'guid' => 'text',
            'name' => 'text',
            'category_id' => 'integer'
        ], 'guid');
        
        $transformer->defineTable('stock', [
            'guid' => 'text',
            'quantity' => 'integer',
            'warehouse' => 'text'
        ], 'guid');

        // Add product data
        $productFrame = new Frame();
        $productFrame->setData(['guid' => 'ABC123', 'name' => 'Product 1', 'category_id' => 1]);
        $productFrame->setAttribute(['table' => 'products']);
        $transformer($productFrame);

        // Add stock data
        $stockFrame = new Frame();
        $stockFrame->setData(['guid' => 'ABC123', 'quantity' => 100, 'warehouse' => 'Main']);
        $stockFrame->setAttribute(['table' => 'stock']);
        $transformer($stockFrame);

        // End frame
        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        // Check merged data
        $mergedData = iterator_to_array($transformer->getMergedData());
        $this->assertCount(1, $mergedData);
        $this->assertEquals('ABC123', $mergedData[0]['guid']);
        $this->assertEquals('Product 1', $mergedData[0]['name']);
        $this->assertEquals(100, $mergedData[0]['quantity']);
        $this->assertEquals('Main', $mergedData[0]['warehouse']);
    }

    public function test_batch_insert(): void
    {
        $transformer = new SqliteMergeTransformer();
        $transformer->setBatchSize(3);
        $transformer->defineTable('items', ['id' => 'integer', 'value' => 'text'], 'id');

        // Insert 5 items (should trigger batch at 3)
        for ($i = 1; $i <= 5; $i++) {
            $frame = new Frame();
            $frame->setData(['id' => $i, 'value' => "Item {$i}"]);
            $frame->setAttribute(['table' => 'items']);
            $transformer($frame);
        }

        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        $count = $transformer->getMergedCount();
        $this->assertEquals(5, $count);
    }

    public function test_progress_callback(): void
    {
        $progressCalls = [];
        
        $transformer = new SqliteMergeTransformer();
        $transformer->setBatchSize(1); // Force immediate inserts
        $transformer->setProgressCallback(function ($count) use (&$progressCalls) {
            $progressCalls[] = $count;
        });
        
        $transformer->defineTable('items', ['id' => 'integer'], 'id');

        // Insert enough items to trigger progress callback
        for ($i = 1; $i <= 150; $i++) {
            $frame = new Frame();
            $frame->setData(['id' => $i]);
            $frame->setAttribute(['table' => 'items']);
            $transformer($frame);
        }

        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        // Progress callback should be called at 100
        $this->assertContains(100, $progressCalls);
    }

    public function test_auto_table_detection(): void
    {
        $transformer = new SqliteMergeTransformer();
        
        $transformer->defineTable('products', [
            'product_id' => 'integer',
            'product_name' => 'text',
            'product_price' => 'real'
        ], 'product_id');
        
        $transformer->defineTable('orders', [
            'order_id' => 'integer',
            'order_date' => 'text',
            'total' => 'real'
        ], 'order_id');

        // Initialize tables before processing
        $initFrame = new Frame();
        $initFrame->setData(['product_id' => 0, 'product_name' => '', 'product_price' => 0.0]);
        $initFrame->setAttribute(['table' => 'products']);
        $transformer($initFrame);

        // Frame with product-like data (no explicit table metadata)
        $frame = new Frame();
        $frame->setData([
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_price' => 9.99
        ]);

        $result = $transformer($frame);
        $this->assertInstanceOf(Frame::class, $result);

        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        $mergedData = iterator_to_array($transformer->getMergedData());
        $this->assertCount(2, $mergedData); // Init frame + actual frame
        $this->assertEquals('Test Product', $mergedData[1]['product_name']);
    }

    public function test_file_based_database(): void
    {
        $transformer = new SqliteMergeTransformer($this->testDbPath);
        $transformer->defineTable('test', ['id' => 'integer', 'data' => 'text'], 'id');

        $frame = new Frame();
        $frame->setData(['id' => 1, 'data' => 'test']);
        $frame->setAttribute(['table' => 'test']);
        $transformer($frame);

        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        $this->assertFileExists($this->testDbPath);
        
        // Cleanup should remove the file
        $transformer->cleanup();
        $this->assertFileDoesNotExist($this->testDbPath);
    }

    public function test_disable_auto_cleanup(): void
    {
        $transformer = new SqliteMergeTransformer($this->testDbPath);
        $transformer->disableAutoCleanup();
        $transformer->defineTable('test', ['id' => 'integer'], 'id');

        $frame = new Frame();
        $frame->setData(['id' => 1]);
        $frame->setAttribute(['table' => 'test']);
        $transformer($frame);

        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        // Destroy transformer
        unset($transformer);

        // File should still exist
        $this->assertFileExists($this->testDbPath);
    }

    public function test_complex_merge_with_multiple_keys(): void
    {
        $transformer = new SqliteMergeTransformer();
        
        $transformer->defineTable('products', [
            'company_id' => 'integer',
            'product_id' => 'integer',
            'name' => 'text'
        ], ['company_id', 'product_id']);
        
        $transformer->defineTable('inventory', [
            'company_id' => 'integer',
            'product_id' => 'integer',
            'stock' => 'integer'
        ], ['company_id', 'product_id']);

        // Add products
        $frame1 = new Frame();
        $frame1->setData(['company_id' => 1, 'product_id' => 100, 'name' => 'Product A']);
        $frame1->setAttribute(['table' => 'products']);
        $transformer($frame1);

        // Add inventory
        $frame2 = new Frame();
        $frame2->setData(['company_id' => 1, 'product_id' => 100, 'stock' => 50]);
        $frame2->setAttribute(['table' => 'inventory']);
        $transformer($frame2);

        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        $mergedData = iterator_to_array($transformer->getMergedData());
        $this->assertCount(1, $mergedData);
        $this->assertEquals('Product A', $mergedData[0]['name']);
        $this->assertEquals(50, $mergedData[0]['stock']);
    }

    public function test_unknown_table_exception(): void
    {
        $transformer = new SqliteMergeTransformer();
        $transformer->defineTable('known_table', ['id' => 'integer'], 'id');

        $frame = new Frame();
        $frame->setData(['unknown_field' => 'value']);
        // No table metadata and data doesn't match any defined table

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown table for frame data');

        $transformer($frame);
    }

    public function test_static_make_method(): void
    {
        $transformer = SqliteMergeTransformer::make();
        $this->assertInstanceOf(SqliteMergeTransformer::class, $transformer);
    }

    public function test_set_output_table(): void
    {
        $transformer = new SqliteMergeTransformer();
        $transformer->setOutputTable('custom_output');
        $transformer->defineTable('test', ['id' => 'integer'], 'id');

        $frame = new Frame();
        $frame->setData(['id' => 1]);
        $frame->setAttribute(['table' => 'test']);
        $transformer($frame);

        $endFrame = new Frame();
        $endFrame->setEnd();
        $transformer($endFrame);

        // The merged data should still be accessible
        $count = $transformer->getMergedCount();
        $this->assertEquals(1, $count);
    }
}