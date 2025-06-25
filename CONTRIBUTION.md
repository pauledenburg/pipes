# Contributing to Pipes

Thank you for considering contributing to Pipes! This guide will help you create custom Extractors, Transformers, and Loaders for the ETL pipeline.

## Table of Contents
- [Creating an Extractor](#creating-an-extractor)
- [Creating a Transformer](#creating-a-transformer)
- [Creating a Loader](#creating-a-loader)
- [Testing Your Components](#testing-your-components)
- [Submitting Your Contribution](#submitting-your-contribution)

## Creating an Extractor

Extractors are responsible for reading data from various sources. They must implement the `ExtractorInterface`.

### Basic Structure

```php
<?php

namespace Jwhulette\Pipes\Extractors;

use Generator;
use Jwhulette\Pipes\Contracts\ExtractorInterface;
use Jwhulette\Pipes\Frame;

class YourExtractor implements ExtractorInterface
{
    public function extract(): Generator
    {
        // Your extraction logic here
    }
}
```

### Example: JSON File Extractor

Here's a complete example of a JSON file extractor:

```php
<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Extractors;

use Generator;
use Jwhulette\Pipes\Contracts\ExtractorInterface;
use Jwhulette\Pipes\Frame;

final class JsonExtractor implements ExtractorInterface
{
    protected Frame $frame;
    protected string $filePath;
    protected bool $hasHeaders = false;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->frame = new Frame();
    }

    public function extract(): Generator
    {
        if (!file_exists($this->filePath)) {
            throw new \Exception("File not found: {$this->filePath}");
        }

        $content = file_get_contents($this->filePath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON: " . json_last_error_msg());
        }

        // Set headers from first row if needed
        if (!$this->hasHeaders && !empty($data)) {
            $this->frame->setHeader(array_keys($data[0]));
            $this->hasHeaders = true;
        }

        // Yield each row as a Frame
        foreach ($data as $row) {
            yield $this->frame->setData($row);
        }

        // Signal end of data
        $this->frame->setEnd();
    }

    public static function make(string $filePath): static
    {
        return new static($filePath);
    }
}
```

### Key Points for Extractors
1. **Use Generators**: Always use `yield` to return data for memory efficiency
2. **Frame Object**: Each row should be wrapped in a Frame object
3. **Headers**: Set headers using `$frame->setHeader()` if your data has headers
4. **End Signal**: Call `$frame->setEnd()` when extraction is complete
5. **Error Handling**: Add appropriate error handling for your data source

## Creating a Transformer

Transformers modify data as it passes through the pipeline. They must implement the `TransformerInterface`.

### Basic Structure

```php
<?php

namespace Jwhulette\Pipes\Transformers;

use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\Frame;

class YourTransformer implements TransformerInterface
{
    public function __invoke(Frame $frame): Frame
    {
        // Your transformation logic here
        return $frame;
    }
}
```

### Example: Email Validator Transformer

Here's a complete example that validates and cleans email addresses:

```php
<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Transformers;

use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\Frame;

final class EmailValidatorTransformer implements TransformerInterface
{
    protected array $columns = [];
    protected bool $removeInvalid = false;

    public function __invoke(Frame $frame): Frame
    {
        $frame->getData()->transform(function ($item, $key) {
            // Only transform specified columns
            if (in_array($key, $this->columns)) {
                // Clean the email
                $email = $this->cleanEmail($item);
                
                // Validate the email
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return strtolower($email);
                }
                
                // Return null for invalid emails if removeInvalid is true
                return $this->removeInvalid ? null : $item;
            }
            
            return $item;
        });

        return $frame;
    }

    /**
     * Specify which columns contain email addresses
     */
    public function transformColumn(string $column): self
    {
        $this->columns[] = $column;
        return $this;
    }

    /**
     * Remove invalid emails instead of keeping original value
     */
    public function removeInvalidEmails(): self
    {
        $this->removeInvalid = true;
        return $this;
    }

    /**
     * Clean email string
     */
    private function cleanEmail($value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        // Remove whitespace and common issues
        $email = trim((string) $value);
        $email = str_replace(' ', '', $email);
        
        return $email;
    }

    public static function make(): static
    {
        return new static();
    }
}
```

### Example: Hash Transformer

Here's another example that hashes sensitive data:

```php
<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Transformers;

use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\Frame;

final class HashTransformer implements TransformerInterface
{
    protected array $columns = [];
    protected string $algorithm = 'sha256';
    protected ?string $salt = null;

    public function __invoke(Frame $frame): Frame
    {
        $frame->getData()->transform(function ($item, $key) {
            if (in_array($key, $this->columns)) {
                if (is_null($item) || $item === '') {
                    return $item;
                }
                
                $value = (string) $item;
                if ($this->salt) {
                    $value .= $this->salt;
                }
                
                return hash($this->algorithm, $value);
            }
            
            return $item;
        });

        return $frame;
    }

    public function transformColumn(string $column): self
    {
        $this->columns[] = $column;
        return $this;
    }

    public function setAlgorithm(string $algorithm): self
    {
        if (!in_array($algorithm, hash_algos())) {
            throw new \InvalidArgumentException("Invalid hash algorithm: {$algorithm}");
        }
        
        $this->algorithm = $algorithm;
        return $this;
    }

    public function setSalt(string $salt): self
    {
        $this->salt = $salt;
        return $this;
    }

    public static function make(): static
    {
        return new static();
    }
}
```

### Key Points for Transformers
1. **Immutability**: Always return the Frame object, even if unchanged
2. **Column Selection**: Allow users to specify which columns to transform
3. **Type Safety**: Check data types before transforming
4. **Fluent Interface**: Return `$this` from configuration methods
5. **Null Handling**: Handle null and empty values appropriately

## Creating a Loader

Loaders write the transformed data to a destination. They must implement the `LoaderInterface`.

### Basic Structure

```php
<?php

namespace Jwhulette\Pipes\Loaders;

use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;

class YourLoader implements LoaderInterface
{
    public function load(Frame $frame): void
    {
        // Your loading logic here
    }
}
```

### Example: JSON File Loader

Here's a complete example of a JSON file loader:

```php
<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Loaders;

use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;

final class JsonLoader implements LoaderInterface
{
    protected string $filePath;
    protected array $buffer = [];
    protected int $bufferSize = 1000;
    protected bool $prettyPrint = false;
    protected bool $hasWrittenHeader = false;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function load(Frame $frame): void
    {
        // Initialize file with array opening
        if (!$this->hasWrittenHeader) {
            file_put_contents($this->filePath, '[' . PHP_EOL);
            $this->hasWrittenHeader = true;
        }

        // Add data to buffer
        $this->buffer[] = $frame->getData()->toArray();

        // Write buffer when it reaches the specified size
        if (count($this->buffer) >= $this->bufferSize) {
            $this->writeBuffer();
        }

        // Write remaining data and close array when done
        if ($frame->end === true) {
            $this->writeBuffer(true);
            
            // Close the JSON array
            $content = file_get_contents($this->filePath);
            $content = rtrim($content, ',' . PHP_EOL) . PHP_EOL . ']';
            file_put_contents($this->filePath, $content);
        }
    }

    protected function writeBuffer(bool $isFinal = false): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $options = $this->prettyPrint ? JSON_PRETTY_PRINT : 0;
        
        foreach ($this->buffer as $row) {
            $json = json_encode($row, $options);
            file_put_contents($this->filePath, $json . ',' . PHP_EOL, FILE_APPEND);
        }

        $this->buffer = [];
    }

    public function setBufferSize(int $size): self
    {
        $this->bufferSize = $size;
        return $this;
    }

    public function setPrettyPrint(bool $pretty = true): self
    {
        $this->prettyPrint = $pretty;
        return $this;
    }

    public static function make(string $filePath): static
    {
        return new static($filePath);
    }
}
```

### Example: API Loader

Here's another example that sends data to an API:

```php
<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Loaders;

use Jwhulette\Pipes\Contracts\LoaderInterface;
use Jwhulette\Pipes\Frame;
use Illuminate\Support\Facades\Http;

final class ApiLoader implements LoaderInterface
{
    protected string $endpoint;
    protected array $headers = [];
    protected array $buffer = [];
    protected int $batchSize = 100;
    protected int $timeout = 30;

    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;
    }

    public function load(Frame $frame): void
    {
        // Add to buffer
        $this->buffer[] = $frame->getData()->toArray();

        // Send batch when buffer is full
        if (count($this->buffer) >= $this->batchSize) {
            $this->sendBatch();
        }

        // Send remaining data when done
        if ($frame->end === true && !empty($this->buffer)) {
            $this->sendBatch();
        }
    }

    protected function sendBatch(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $response = Http::withHeaders($this->headers)
                ->timeout($this->timeout)
                ->post($this->endpoint, [
                    'data' => $this->buffer
                ]);

            if (!$response->successful()) {
                throw new \Exception(
                    "API request failed: {$response->status()} - {$response->body()}"
                );
            }

            // Clear buffer after successful send
            $this->buffer = [];
            
        } catch (\Exception $e) {
            // Log error and decide whether to retry or fail
            \Log::error('API Loader Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function withAuth(string $token): self
    {
        $this->headers['Authorization'] = 'Bearer ' . $token;
        return $this;
    }

    public function setBatchSize(int $size): self
    {
        $this->batchSize = $size;
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public static function make(string $endpoint): static
    {
        return new static($endpoint);
    }
}
```

### Key Points for Loaders
1. **Handle End Signal**: Check `$frame->end` to know when to finalize
2. **Buffering**: Implement buffering for better performance
3. **Error Handling**: Handle write errors gracefully
4. **Resource Management**: Close files/connections when done
5. **Configuration**: Provide options for output formatting

## Testing Your Components

Always include tests for your custom components:

```php
<?php

namespace Tests\Unit\Extractors;

use Tests\TestCase;
use Jwhulette\Pipes\Extractors\JsonExtractor;
use Jwhulette\Pipes\Frame;

class JsonExtractorTest extends TestCase
{
    public function test_it_extracts_json_data()
    {
        // Create test file
        $testFile = 'test.json';
        $testData = [
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25]
        ];
        file_put_contents($testFile, json_encode($testData));

        // Test extraction
        $extractor = new JsonExtractor($testFile);
        $results = [];
        
        foreach ($extractor->extract() as $frame) {
            if (!$frame->end) {
                $results[] = $frame->getData()->toArray();
            }
        }

        // Assertions
        $this->assertCount(2, $results);
        $this->assertEquals('John', $results[0]['name']);
        $this->assertEquals(25, $results[1]['age']);

        // Cleanup
        unlink($testFile);
    }
}
```

## Submitting Your Contribution

1. **Fork the repository** and create a new branch for your feature
2. **Write your component** following the examples above
3. **Add tests** with at least 80% coverage
4. **Update documentation** if needed
5. **Run tests and static analysis**:
   ```bash
   composer ts
   ```
6. **Submit a pull request** with a clear description of your changes

### Coding Standards
- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`
- Add proper PHPDoc comments
- Make classes `final` unless extension is intended
- Use meaningful variable and method names

Thank you for contributing to Pipes!