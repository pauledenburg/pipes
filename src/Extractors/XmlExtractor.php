<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Extractors;

use Generator;
use Jwhulette\Pipes\Contracts\ExtractorInterface;
use Jwhulette\Pipes\Frame;
use SimpleXMLElement;

final class XmlExtractor implements ExtractorInterface
{
    protected Frame $frame;

    protected string $file;

    protected string $elementPath;

    protected ?int $maxFileSize = null;

    protected array $namespaces = [];

    /**
     * @param string $file Path to XML file
     * @param string $elementPath XML element to extract (e.g., 'Product', '//Product')
     */
    public function __construct(string $file, string $elementPath)
    {
        $this->file = $file;
        $this->elementPath = $elementPath;
        $this->frame = new Frame();

        // Default max file size for SimpleXML (10MB)
        $this->maxFileSize = 10 * 1024 * 1024;
    }

    public function extract(): Generator
    {
        if (! file_exists($this->file)) {
            throw new \Exception("XML file not found: {$this->file}");
        }

        $fileSize = filesize($this->file);

        // Check if file is too large for SimpleXML
        if ($this->maxFileSize !== null && $fileSize > $this->maxFileSize) {
            throw new \Exception(
                "XML file too large ({$fileSize} bytes). Maximum size is {$this->maxFileSize} bytes. " .
                'Use StreamingXmlExtractor for large files.'
            );
        }

        // Load XML content
        $xmlContent = file_get_contents($this->file);

        if ($xmlContent === false) {
            throw new \Exception("Failed to read XML file: {$this->file}");
        }

        // Parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMessage = "Failed to parse XML file: {$this->file}";

            if (! empty($errors)) {
                $errorMessage .= ' - ' . $errors[0]->message;
            }

            libxml_clear_errors();
            throw new \Exception($errorMessage);
        }

        // Register namespaces
        foreach ($this->namespaces as $prefix => $uri) {
            $xml->registerXPathNamespace($prefix, $uri);
        }

        // Extract elements using XPath
        $xpath = $this->buildXPath();
        $elements = $xml->xpath($xpath);

        if ($elements === false) {
            throw new \Exception("Invalid XPath expression: {$xpath}");
        }

        // Yield each element as Frame
        foreach ($elements as $element) {
            $data = $this->elementToArray($element);

            if (! empty($data)) {
                // Ensure data is always an array
                if (! is_array($data)) {
                    $data = ['_value' => $data];
                }

                $newFrame = clone $this->frame;
                yield $newFrame->setData($data);
            }
        }

        // Mark end of extraction
        $endFrame = clone $this->frame;
        $endFrame->setEnd();
        yield $endFrame;
    }

    /**
     * Build XPath expression from element path.
     */
    protected function buildXPath(): string
    {
        // If already an XPath expression, return as-is
        if (str_starts_with($this->elementPath, '/') || str_starts_with($this->elementPath, '//')) {
            return $this->elementPath;
        }

        // Otherwise, search for element anywhere in document
        return '//' . $this->elementPath;
    }

    /**
     * Convert SimpleXMLElement to array.
     * @return array|string
     */
    protected function elementToArray(SimpleXMLElement $element)
    {
        $array = [];

        // Handle attributes
        foreach ($element->attributes() as $key => $value) {
            $array['@' . $key] = (string) $value;
        }

        // Handle namespaced attributes
        foreach ($this->namespaces as $prefix => $uri) {
            foreach ($element->attributes($uri) as $key => $value) {
                $array['@' . $prefix . ':' . $key] = (string) $value;
            }
        }

        // Handle child elements
        $children = [];
        $hasTextContent = false;
        $textContent = '';

        // Check for text content
        $nodeValue = trim((string) $element);
        if ($nodeValue !== '' && count($element->children()) === 0) {
            // Element has only text content
            if (empty($array)) {
                return $nodeValue;
            } else {
                $array['_value'] = $nodeValue;

                return $array;
            }
        }

        // Process children
        foreach ($element->children() as $child) {
            $childName = $child->getName();
            $childValue = $this->elementToArray($child);

            if (! isset($children[$childName])) {
                $children[$childName] = [];
            }

            $children[$childName][] = $childValue;
        }

        // Process namespaced children
        foreach ($this->namespaces as $prefix => $uri) {
            foreach ($element->children($uri) as $child) {
                $childName = $prefix . ':' . $child->getName();
                $childValue = $this->elementToArray($child);

                if (! isset($children[$childName])) {
                    $children[$childName] = [];
                }

                $children[$childName][] = $childValue;
            }
        }

        // Flatten single-element arrays
        foreach ($children as $name => $values) {
            if (count($values) === 1) {
                $array[$name] = $values[0];
            } else {
                $array[$name] = $values;
            }
        }

        // If element has text content and children/attributes
        if ($nodeValue !== '' && (! empty($children) || ! empty($array))) {
            $array['_value'] = $nodeValue;
        }

        return $array;
    }

    /**
     * Set maximum file size for SimpleXML processing.
     * @param int $megabytes Maximum file size in megabytes
     */
    public function setMaxFileSize(int $megabytes): self
    {
        $this->maxFileSize = $megabytes * 1024 * 1024;

        return $this;
    }

    /**
     * Disable file size check.
     */
    public function disableFileSizeCheck(): self
    {
        $this->maxFileSize = null;

        return $this;
    }

    /**
     * Register XML namespace.
     */
    public function registerNamespace(string $prefix, string $uri): self
    {
        $this->namespaces[$prefix] = $uri;

        return $this;
    }

    /**
     * Create instance.
     */
    public static function make(string $file, string $elementPath): static
    {
        return new static($file, $elementPath);
    }
}
