<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Extractors;

use Generator;
use Jwhulette\Pipes\Contracts\ExtractorInterface;
use Jwhulette\Pipes\Frame;
use XMLReader;

final class StreamingXmlExtractor implements ExtractorInterface
{
    protected Frame $frame;
    protected string $file;
    protected string $elementPath;
    protected array $namespaces = [];
    protected ?int $memoryLimit = null;
    protected int $recordCount = 0;
    /** @var callable|null */
    protected $progressCallback = null;

    /**
     * @param string $file Path to XML file
     * @param string $elementPath XML element to extract (e.g., 'Product', 'Products/Product')
     */
    public function __construct(string $file, string $elementPath)
    {
        $this->file = $file;
        $this->elementPath = $elementPath;
        $this->frame = new Frame();
    }

    public function extract(): Generator
    {
        if (!file_exists($this->file)) {
            throw new \Exception("XML file not found: {$this->file}");
        }

        $reader = new XMLReader();
        
        // Suppress warnings for invalid XML
        $previousErrorReporting = error_reporting();
        error_reporting($previousErrorReporting & ~E_WARNING);
        
        try {
            if (!$reader->open($this->file)) {
                throw new \Exception("Failed to open XML file: {$this->file}");
            }
            
            // Restore error reporting
            error_reporting($previousErrorReporting);

            // Set memory limit if specified
            if ($this->memoryLimit !== null) {
                $reader->setParserProperty(XMLReader::SUBST_ENTITIES, true);
            }

            // Move to first element
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $this->isTargetElement($reader)) {
                    // Parse the element
                    $data = $this->parseElement($reader);
                    
                    if (!empty($data)) {
                        $this->recordCount++;
                        
                        // Call progress callback if set
                        if ($this->progressCallback !== null) {
                            ($this->progressCallback)($this->recordCount);
                        }
                        
                        $newFrame = clone $this->frame;
                        yield $newFrame->setData($data);
                    }
                }
            }
            
            // Mark end of extraction
            $endFrame = clone $this->frame;
            $endFrame->setEnd();
            yield $endFrame;
            
        } catch (\Exception $e) {
            error_reporting($previousErrorReporting);
            throw $e;
        } finally {
            $reader->close();
        }
    }

    /**
     * Check if current element matches target path
     */
    protected function isTargetElement(XMLReader $reader): bool
    {
        $currentPath = $reader->name;
        $targetElement = $this->getTargetElement();
        
        return $currentPath === $targetElement;
    }

    /**
     * Get the target element name from the path
     */
    protected function getTargetElement(): string
    {
        $parts = explode('/', $this->elementPath);
        return end($parts);
    }

    /**
     * Parse XML element to array
     */
    protected function parseElement(XMLReader $reader): array
    {
        $element = $reader->expand();
        
        if ($element === false) {
            return [];
        }

        $result = $this->elementToArray($element);
        
        // Always return array
        if (is_string($result)) {
            return ['_value' => $result];
        }
        
        return $result;
    }

    /**
     * Convert DOMNode to array
     * @return array|string
     */
    protected function elementToArray(\DOMNode $node)
    {
        $array = [];

        // Handle attributes
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attr) {
                $array['@' . $attr->nodeName] = $attr->nodeValue;
            }
        }

        // Handle child nodes
        if ($node->hasChildNodes()) {
            $groups = [];
            $hasTextContent = false;
            $textContent = '';
            
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    if (trim($child->nodeValue) !== '') {
                        $hasTextContent = true;
                        $textContent = trim($child->nodeValue);
                    }
                } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                    $value = $this->elementToArray($child);
                    
                    if (!isset($groups[$child->nodeName])) {
                        $groups[$child->nodeName] = [];
                    }
                    
                    $groups[$child->nodeName][] = $value;
                }
            }

            // If only text content and no attributes or elements, return string
            if ($hasTextContent && empty($groups) && empty($array)) {
                return $textContent;
            }
            
            // If has text content with other elements/attributes
            if ($hasTextContent && (!empty($groups) || !empty($array))) {
                $array['_value'] = $textContent;
            }

            // Flatten single-element arrays
            foreach ($groups as $name => $group) {
                if (count($group) === 1) {
                    $array[$name] = $group[0];
                } else {
                    $array[$name] = $group;
                }
            }
        }

        return $array;
    }

    /**
     * Set memory limit for XMLReader
     */
    public function setMemoryLimit(int $megabytes): self
    {
        $this->memoryLimit = $megabytes * 1024 * 1024;
        return $this;
    }

    /**
     * Register XML namespace
     */
    public function registerNamespace(string $prefix, string $uri): self
    {
        $this->namespaces[$prefix] = $uri;
        return $this;
    }

    /**
     * Set progress callback
     * @param callable $callback Function that receives record count
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Get total records processed
     */
    public function getRecordCount(): int
    {
        return $this->recordCount;
    }

    /**
     * Create instance
     */
    public static function make(string $file, string $elementPath): static
    {
        return new static($file, $elementPath);
    }
}