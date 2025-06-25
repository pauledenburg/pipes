<?php

declare(strict_types=1);

namespace Tests\Unit\Loaders;

use Illuminate\Support\Collection;
use Jwhulette\Pipes\Frame;
use Jwhulette\Pipes\Loaders\JsonLoader;
use Tests\TestCase;

class JsonLoaderTest extends TestCase
{
    protected string $testFile = 'test_output.json';

    protected function tearDown(): void
    {
        // Clean up test file
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }

        parent::tearDown();
    }

    public function test_json_loader_writes_array_format(): void
    {
        $loader = JsonLoader::make($this->testFile);

        // Create test frames
        $frame1 = new Frame();
        $frame1->setData(['name' => 'John', 'age' => 30]);

        $frame2 = new Frame();
        $frame2->setData(['name' => 'Jane', 'age' => 25]);

        $frameEnd = new Frame();
        $frameEnd->setData(['name' => 'Bob', 'age' => 35]);
        $frameEnd->setEnd();

        // Load data
        $loader->load($frame1);
        $loader->load($frame2);
        $loader->load($frameEnd);

        // Verify file exists
        $this->assertFileExists($this->testFile);

        // Verify content
        $content = file_get_contents($this->testFile);
        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertCount(3, $data);
        $this->assertEquals('John', $data[0]['name']);
        $this->assertEquals(25, $data[1]['age']);
        $this->assertEquals('Bob', $data[2]['name']);
    }

    public function test_json_loader_with_pretty_print(): void
    {
        $loader = JsonLoader::make($this->testFile)
            ->setPrettyPrint(true)
            ->setBufferSize(1); // Write immediately

        $frame = new Frame();
        $frame->setData(['name' => 'Test', 'nested' => ['key' => 'value']]);
        $frame->setEnd();

        $loader->load($frame);

        $content = file_get_contents($this->testFile);

        // Pretty print should have multiple lines
        $this->assertStringContainsString("\n    ", $content);
    }

    public function test_json_loader_as_ndjson(): void
    {
        $loader = JsonLoader::make($this->testFile)
            ->asNdjson();

        $frame1 = new Frame();
        $frame1->setData(['id' => 1, 'value' => 'first']);

        $frame2 = new Frame();
        $frame2->setData(['id' => 2, 'value' => 'second']);
        $frame2->setEnd();

        $loader->load($frame1);
        $loader->load($frame2);

        $content = file_get_contents($this->testFile);
        $lines = explode("\n", trim($content));

        $this->assertCount(2, $lines);

        $line1 = json_decode($lines[0], true);
        $line2 = json_decode($lines[1], true);

        $this->assertEquals(1, $line1['id']);
        $this->assertEquals('second', $line2['value']);
    }

    public function test_json_loader_configuration(): void
    {
        $loader = JsonLoader::make($this->testFile);

        $configured = $loader
            ->setBufferSize(500)
            ->setPrettyPrint()
            ->forWebApi()
            ->preserveNumeric();

        $this->assertInstanceOf(JsonLoader::class, $configured);
    }

    public function test_json_loader_handles_special_characters(): void
    {
        $loader = JsonLoader::make($this->testFile)
            ->forWebApi();

        $frame = new Frame();
        $frame->setData([
            'url' => 'https://example.com/path',
            'unicode' => 'こんにちは',
            'special' => "Line 1\nLine 2",
        ]);
        $frame->setEnd();

        $loader->load($frame);

        $content = file_get_contents($this->testFile);

        // Check that slashes are not escaped
        $this->assertStringContainsString('https://example.com/path', $content);
        // Check that unicode is preserved
        $this->assertStringContainsString('こんにちは', $content);
    }
}
