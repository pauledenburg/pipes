<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Transformers;

use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\Frame;
use SimpleXMLElement;

final class XmlToArrayTransformer implements TransformerInterface
{
    protected bool $includeAttributes = true;

    protected bool $flattenSingleElements = true;

    protected string $attributePrefix = '@';

    protected string $valueKey = '_value';

    protected array $namespaces = [];

    public function __invoke(Frame $frame): Frame
    {
        $data = $frame->getData()->toArray();

        // Transform each field that contains XML
        $transformedData = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->isXml($value)) {
                $transformedData[$key] = $this->xmlToArray($value);
            } elseif ($value instanceof SimpleXMLElement) {
                $transformedData[$key] = $this->elementToArray($value);
            } else {
                $transformedData[$key] = $value;
            }
        }

        $frame->setData($transformedData);

        return $frame;
    }

    /**
     * Check if a string is valid XML.
     */
    protected function isXml(string $string): bool
    {
        $string = trim($string);
        if (empty($string)) {
            return false;
        }

        // Quick check for XML-like structure
        if ($string[0] !== '<' || substr($string, -1) !== '>') {
            return false;
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($string);
        libxml_clear_errors();

        return $doc !== false;
    }

    /**
     * Convert XML string to array.
     */
    protected function xmlToArray(string $xml): array|string
    {
        libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml);
        libxml_clear_errors();

        if ($element === false) {
            // Return original string if not valid XML
            return $xml;
        }

        // Register namespaces
        foreach ($this->namespaces as $prefix => $uri) {
            $element->registerXPathNamespace($prefix, $uri);
        }

        return $this->elementToArray($element);
    }

    /**
     * Convert SimpleXMLElement to array.
     * @return array|string
     */
    protected function elementToArray(SimpleXMLElement $element)
    {
        $array = [];

        // Handle attributes
        if ($this->includeAttributes) {
            foreach ($element->attributes() as $key => $value) {
                $array[$this->attributePrefix . $key] = (string) $value;
            }

            // Handle namespaced attributes
            foreach ($this->namespaces as $prefix => $uri) {
                foreach ($element->attributes($uri) as $key => $value) {
                    $array[$this->attributePrefix . $prefix . ':' . $key] = (string) $value;
                }
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
                $array[$this->valueKey] = $nodeValue;

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

        // Flatten single-element arrays if enabled
        if ($this->flattenSingleElements) {
            foreach ($children as $name => $values) {
                if (count($values) === 1) {
                    $array[$name] = $values[0];
                } else {
                    $array[$name] = $values;
                }
            }
        } else {
            $array = array_merge($array, $children);
        }

        // If element has text content and children/attributes
        if ($nodeValue !== '' && (! empty($children) || ! empty($array))) {
            $array[$this->valueKey] = $nodeValue;
        }

        return empty($array) ? '' : $array;
    }

    /**
     * Set whether to include attributes in the array.
     */
    public function includeAttributes(bool $include = true): self
    {
        $this->includeAttributes = $include;

        return $this;
    }

    /**
     * Set whether to flatten single-element arrays.
     */
    public function flattenSingleElements(bool $flatten = true): self
    {
        $this->flattenSingleElements = $flatten;

        return $this;
    }

    /**
     * Set the prefix for attribute keys.
     */
    public function setAttributePrefix(string $prefix): self
    {
        $this->attributePrefix = $prefix;

        return $this;
    }

    /**
     * Set the key name for text content when mixed with attributes/elements.
     */
    public function setValueKey(string $key): self
    {
        $this->valueKey = $key;

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
    public static function make(): static
    {
        return new static();
    }
}
