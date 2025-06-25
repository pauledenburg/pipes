<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Loaders;

use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;

final class JsonLoader implements LoaderInterface
{
    protected string $filePath;

    /** @var array<int, array<string, mixed>> */
    protected array $buffer = [];

    protected int $bufferSize = 1000;

    protected bool $prettyPrint = false;

    protected bool $hasWrittenHeader = false;

    protected bool $appendToFile = false;

    protected int $jsonFlags = 0;

    protected bool $wrapInArray = true;

    /** @var resource|null */
    protected $fileHandle = null;

    protected int $recordCount = 0;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function load(Frame $frame): void
    {
        // Handle end frame
        if ($frame->getEnd()) {
            $this->writeBuffer();
            $this->finalize();

            return;
        }

        // Add data to buffer
        $this->buffer[] = $frame->getData()->toArray();

        // Write buffer when it reaches the specified size
        if (count($this->buffer) >= $this->bufferSize) {
            $this->writeBuffer();
        }
    }

    /**
     * Initialize the file for writing.
     */
    protected function initialize(): void
    {
        if ($this->hasWrittenHeader) {
            return;
        }

        $mode = $this->appendToFile ? 'a' : 'w';
        $handle = fopen($this->filePath, $mode);

        if ($handle === false) {
            throw new \Exception("Unable to open file for writing: {$this->filePath}");
        }

        $this->fileHandle = $handle;

        if ($this->wrapInArray && ! $this->appendToFile) {
            fwrite($this->fileHandle, '[' . PHP_EOL);
        }

        $this->hasWrittenHeader = true;
    }

    /**
     * Write buffer to file.
     */
    protected function writeBuffer(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        if (! $this->hasWrittenHeader) {
            $this->initialize();
        }

        $options = $this->jsonFlags;
        if ($this->prettyPrint) {
            $options |= JSON_PRETTY_PRINT;
        }

        foreach ($this->buffer as $row) {
            if ($this->wrapInArray) {
                // Add comma if not the first record
                if ($this->recordCount > 0 && $this->fileHandle !== null) {
                    fwrite($this->fileHandle, ',' . PHP_EOL);
                }

                $json = json_encode($row, $options);

                if ($json === false) {
                    throw new \Exception('JSON encoding error: ' . json_last_error_msg());
                }

                if ($this->fileHandle !== null) {
                    fwrite($this->fileHandle, $json);
                }
            } else {
                // Write as newline-delimited JSON (NDJSON/JSONL)
                $json = json_encode($row, $options);

                if ($json === false) {
                    throw new \Exception('JSON encoding error: ' . json_last_error_msg());
                }

                if ($this->fileHandle !== null) {
                    fwrite($this->fileHandle, $json . PHP_EOL);
                }
            }

            $this->recordCount++;
        }

        $this->buffer = [];
    }

    /**
     * Finalize the JSON file.
     */
    protected function finalize(): void
    {
        if ($this->wrapInArray && $this->fileHandle !== null) {
            fwrite($this->fileHandle, PHP_EOL . ']');
        }

        if ($this->fileHandle !== null) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Set the buffer size (number of records to accumulate before writing).
     */
    public function setBufferSize(int $size): self
    {
        $this->bufferSize = $size;

        return $this;
    }

    /**
     * Enable pretty printing of JSON.
     */
    public function setPrettyPrint(bool $pretty = true): self
    {
        $this->prettyPrint = $pretty;

        return $this;
    }

    /**
     * Append to existing file instead of overwriting.
     */
    public function appendToFile(bool $append = true): self
    {
        $this->appendToFile = $append;

        return $this;
    }

    /**
     * Set custom JSON encoding flags.
     */
    public function setJsonFlags(int $flags): self
    {
        $this->jsonFlags = $flags;

        return $this;
    }

    /**
     * Write as newline-delimited JSON (NDJSON/JSONL) instead of array.
     */
    public function asNdjson(): self
    {
        $this->wrapInArray = false;

        return $this;
    }

    /**
     * Write as JSON Lines format (alias for asNdjson).
     */
    public function asJsonLines(): self
    {
        return $this->asNdjson();
    }

    /**
     * Add common JSON flags for web APIs.
     */
    public function forWebApi(): self
    {
        $this->jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return $this;
    }

    /**
     * Add flags for numeric preservation.
     */
    public function preserveNumeric(): self
    {
        $this->jsonFlags |= JSON_NUMERIC_CHECK;

        return $this;
    }

    /**
     * Create a new instance.
     */
    public static function make(string $filePath): static
    {
        return new static($filePath);
    }

    /**
     * Clean up resources if needed.
     */
    public function __destruct()
    {
        if ($this->fileHandle !== null) {
            $this->finalize();
        }
    }
}
