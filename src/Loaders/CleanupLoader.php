<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Loaders;

use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;

final class CleanupLoader implements LoaderInterface
{
    protected LoaderInterface $innerLoader;
    /** @var array<int, callable> */
    protected array $cleanupCallbacks = [];
    protected bool $cleanupOnEnd = true;
    protected bool $cleanupOnError = true;
    protected bool $hasProcessedEndFrame = false;

    /**
     * @param LoaderInterface $innerLoader The actual loader to use
     */
    public function __construct(LoaderInterface $innerLoader)
    {
        $this->innerLoader = $innerLoader;
    }

    public function load(Frame $frame): void
    {
        try {
            // Forward the frame to the inner loader
            $this->innerLoader->load($frame);
            
            // If this is the end frame, run cleanup
            if ($frame->getEnd() && $this->cleanupOnEnd && !$this->hasProcessedEndFrame) {
                $this->hasProcessedEndFrame = true;
                $this->runCleanup();
            }
        } catch (\Throwable $e) {
            // Run cleanup on error if enabled
            if ($this->cleanupOnError && !$this->hasProcessedEndFrame) {
                $this->hasProcessedEndFrame = true;
                $this->runCleanup();
            }
            
            // Re-throw the exception
            throw $e;
        }
    }

    /**
     * Add a cleanup callback to be executed
     * @param callable $callback Function to call during cleanup
     */
    public function addCleanupCallback(callable $callback): self
    {
        $this->cleanupCallbacks[] = $callback;
        return $this;
    }

    /**
     * Set whether to run cleanup on end frame
     */
    public function setCleanupOnEnd(bool $cleanup): self
    {
        $this->cleanupOnEnd = $cleanup;
        return $this;
    }

    /**
     * Set whether to run cleanup on error
     */
    public function setCleanupOnError(bool $cleanup): self
    {
        $this->cleanupOnError = $cleanup;
        return $this;
    }

    /**
     * Run all cleanup callbacks
     */
    public function runCleanup(): void
    {
        foreach ($this->cleanupCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // Silently continue with other cleanups
                // Could optionally log to a file or use a logger if provided
            }
        }
        
        // Clear callbacks after running
        $this->cleanupCallbacks = [];
    }

    /**
     * Get the inner loader
     */
    public function getInnerLoader(): LoaderInterface
    {
        return $this->innerLoader;
    }

    /**
     * Create instance with inner loader
     */
    public static function wrap(LoaderInterface $loader): static
    {
        return new static($loader);
    }

    /**
     * Destructor - run cleanup if not already done
     */
    public function __destruct()
    {
        if (!$this->hasProcessedEndFrame && !empty($this->cleanupCallbacks)) {
            $this->runCleanup();
        }
    }
}