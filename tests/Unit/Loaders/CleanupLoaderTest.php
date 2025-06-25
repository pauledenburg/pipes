<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Tests\Unit\Loaders;

use Exception;
use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;
use Jwhulette\Pipes\Loaders\CleanupLoader;
use PHPUnit\Framework\TestCase;

class CleanupLoaderTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFile = sys_get_temp_dir() . '/cleanup_test_' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function test_cleanup_on_end_frame(): void
    {
        // Create a mock inner loader
        $innerLoader = $this->createMock(LoaderInterface::class);
        $innerLoader->expects($this->exactly(2))->method('load');

        $cleanupLoader = new CleanupLoader($innerLoader);
        
        // Track if cleanup was called
        $cleanupCalled = false;
        $cleanupLoader->addCleanupCallback(function () use (&$cleanupCalled) {
            $cleanupCalled = true;
        });

        // Load regular frame
        $frame1 = new Frame();
        $frame1->setData(['test' => 'data']);
        $cleanupLoader->load($frame1);
        
        $this->assertFalse($cleanupCalled);

        // Load end frame
        $endFrame = new Frame();
        $endFrame->setEnd();
        $cleanupLoader->load($endFrame);
        
        $this->assertTrue($cleanupCalled);
    }

    public function test_cleanup_on_error(): void
    {
        // Create a mock inner loader that throws an exception
        $innerLoader = $this->createMock(LoaderInterface::class);
        $innerLoader->method('load')->willThrowException(new Exception('Test error'));

        $cleanupLoader = new CleanupLoader($innerLoader);
        
        // Track if cleanup was called
        $cleanupCalled = false;
        $cleanupLoader->addCleanupCallback(function () use (&$cleanupCalled) {
            $cleanupCalled = true;
        });

        // Try to load frame (should throw exception)
        $frame = new Frame();
        $frame->setData(['test' => 'data']);
        
        try {
            $cleanupLoader->load($frame);
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Test error', $e->getMessage());
        }
        
        $this->assertTrue($cleanupCalled);
    }

    public function test_multiple_cleanup_callbacks(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = new CleanupLoader($innerLoader);
        
        $callbackOrder = [];
        
        $cleanupLoader->addCleanupCallback(function () use (&$callbackOrder) {
            $callbackOrder[] = 'first';
        });
        
        $cleanupLoader->addCleanupCallback(function () use (&$callbackOrder) {
            $callbackOrder[] = 'second';
        });
        
        $cleanupLoader->addCleanupCallback(function () use (&$callbackOrder) {
            $callbackOrder[] = 'third';
        });

        $endFrame = new Frame();
        $endFrame->setEnd();
        $cleanupLoader->load($endFrame);
        
        $this->assertEquals(['first', 'second', 'third'], $callbackOrder);
    }

    public function test_cleanup_with_file_deletion(): void
    {
        // Create a test file
        file_put_contents($this->testFile, 'test content');
        $this->assertFileExists($this->testFile);

        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = new CleanupLoader($innerLoader);
        
        // Add cleanup callback to delete file
        $cleanupLoader->addCleanupCallback(function () {
            if (file_exists($this->testFile)) {
                unlink($this->testFile);
            }
        });

        $endFrame = new Frame();
        $endFrame->setEnd();
        $cleanupLoader->load($endFrame);
        
        $this->assertFileDoesNotExist($this->testFile);
    }

    public function test_disable_cleanup_on_end(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = new CleanupLoader($innerLoader);
        $cleanupLoader->setCleanupOnEnd(false);
        
        $cleanupCalled = false;
        $cleanupLoader->addCleanupCallback(function () use (&$cleanupCalled) {
            $cleanupCalled = true;
        });

        $endFrame = new Frame();
        $endFrame->setEnd();
        $cleanupLoader->load($endFrame);
        
        $this->assertFalse($cleanupCalled);
    }

    public function test_disable_cleanup_on_error(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $innerLoader->method('load')->willThrowException(new Exception('Test error'));

        $cleanupLoader = new CleanupLoader($innerLoader);
        $cleanupLoader->setCleanupOnError(false);
        
        $cleanupCalled = false;
        $cleanupLoader->addCleanupCallback(function () use (&$cleanupCalled) {
            $cleanupCalled = true;
        });

        $frame = new Frame();
        $frame->setData(['test' => 'data']);
        
        try {
            $cleanupLoader->load($frame);
        } catch (Exception $e) {
            // Expected
        }
        
        $this->assertFalse($cleanupCalled);
    }

    public function test_cleanup_only_runs_once(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = new CleanupLoader($innerLoader);
        
        $cleanupCount = 0;
        $cleanupLoader->addCleanupCallback(function () use (&$cleanupCount) {
            $cleanupCount++;
        });

        // First end frame
        $endFrame1 = new Frame();
        $endFrame1->setEnd();
        $cleanupLoader->load($endFrame1);
        
        $this->assertEquals(1, $cleanupCount);

        // Second end frame - cleanup should not run again
        $endFrame2 = new Frame();
        $endFrame2->setEnd();
        $cleanupLoader->load($endFrame2);
        
        $this->assertEquals(1, $cleanupCount);
    }

    public function test_cleanup_callback_error_handling(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = new CleanupLoader($innerLoader);
        
        $callbackResults = [];
        
        // Add callback that throws exception
        $cleanupLoader->addCleanupCallback(function () {
            throw new Exception('Cleanup error');
        });
        
        // Add callback that should still run
        $cleanupLoader->addCleanupCallback(function () use (&$callbackResults) {
            $callbackResults[] = 'success';
        });

        $endFrame = new Frame();
        $endFrame->setEnd();
        
        // Suppress error output for this test
        $oldErrorReporting = error_reporting(0);
        
        // Should not throw exception
        $cleanupLoader->load($endFrame);
        
        // Restore error reporting
        error_reporting($oldErrorReporting);
        
        $this->assertEquals(['success'], $callbackResults);
    }

    public function test_get_inner_loader(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = new CleanupLoader($innerLoader);
        
        $this->assertSame($innerLoader, $cleanupLoader->getInnerLoader());
    }

    public function test_static_wrap_method(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = CleanupLoader::wrap($innerLoader);
        
        $this->assertInstanceOf(CleanupLoader::class, $cleanupLoader);
        $this->assertSame($innerLoader, $cleanupLoader->getInnerLoader());
    }

    public function test_manual_cleanup(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        $cleanupLoader = new CleanupLoader($innerLoader);
        
        $cleanupCalled = false;
        $cleanupLoader->addCleanupCallback(function () use (&$cleanupCalled) {
            $cleanupCalled = true;
        });

        // Manually run cleanup
        $cleanupLoader->runCleanup();
        
        $this->assertTrue($cleanupCalled);
        
        // Callbacks should be cleared after running
        $cleanupCalled = false;
        $cleanupLoader->runCleanup();
        $this->assertFalse($cleanupCalled);
    }

    public function test_destructor_cleanup(): void
    {
        $innerLoader = $this->createMock(LoaderInterface::class);
        
        // Track cleanup in a file since object will be destroyed
        $trackingFile = $this->testFile . '.tracking';
        
        $cleanupLoader = new CleanupLoader($innerLoader);
        $cleanupLoader->addCleanupCallback(function () use ($trackingFile) {
            file_put_contents($trackingFile, 'cleaned');
        });
        
        // Destroy object to trigger destructor
        unset($cleanupLoader);
        
        // Check if cleanup ran
        $this->assertFileExists($trackingFile);
        $this->assertEquals('cleaned', file_get_contents($trackingFile));
        
        // Clean up tracking file
        unlink($trackingFile);
    }
}