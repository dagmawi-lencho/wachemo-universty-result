<?php

declare(strict_types=1);

/**
 * Small DOM helpers that reproduce the BeautifulSoup calls used by the portal scraper.
 */
final class Html
{
    private const SKIPPED_TEXT_PARENTS = ['script', 'style', 'template'];

    /**
     * @return array{0: DOMDocument, 1: DOMXPath}
     */
    public static function load(string $html): array
    {
        if (trim($html) === '') {
            $html = '<html></html>';
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $flags = LIBXML_NONET | LIBXML_COMPACT | (defined('LIBXML_PARSEHUGE') ? LIBXML_PARSEHUGE : 0);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return [$dom, new DOMXPath($dom)];
    }

    /**
     * @return DOMElement[]
     */
    public static function query(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $nodes = $context === null ? $xpath->query($expression) : $xpath->query($expression, $context);
        $result = [];
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $result[] = $node;
                }
            }
        }
        return $result;
    }

    /**
     * Equivalent of BeautifulSoup's get_text(separator, strip=True).
     */
    public static function text(DOMNode $node, string $separator = ''): string
    {
        $strings = [];
        self::collectStrings($node, $strings);
        $out = [];
        foreach ($strings as $string) {
            $string = Text::strip($string);
            if ($string !== '') {
                $out[] = $string;
            }
        }
        return implode($separator, $out);
    }

    /**
     * Equivalent of BeautifulSoup's Tag.string: the only string inside a single-child chain.
     */
    public static function singleString(DOMNode $node): ?string
    {
        if (!$node->hasChildNodes() || $node->childNodes->length !== 1) {
            return null;
        }
        $child = $node->firstChild;
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            return $child->nodeValue;
        }
        return $child->nodeType === XML_ELEMENT_NODE ? self::singleString($child) : null;
    }

    public static function hasClass(DOMElement $element, string $class): bool
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        return in_array($class, $classes, true);
    }

    public static function firstWithClass(DOMXPath $xpath, string $tag, string $class): ?DOMElement
    {
        foreach (self::query($xpath, '//' . $tag) as $element) {
            if (self::hasClass($element, $class)) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Resolve a reference against a base URL (RFC 3986), like Python's urljoin.
     */
    public static function urljoin(string $base, ?string $reference): string
    {
        if ($reference === null || $reference === '') {
            return $base;
        }
        $ref = parse_url($reference);
        $b = parse_url($base);
        if ($ref === false || $b === false || !isset($b['scheme'], $b['host'])) {
            return $reference;
        }
        if (isset($ref['scheme'])) {
            return $reference;
        }

        $authority = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (Text::startsWith($reference, '//')) {
            return $b['scheme'] . ':' . $reference;
        }

        $basePath = $b['path'] ?? '/';
        $refPath = $ref['path'] ?? '';
        if ($refPath === '') {
            $path = $basePath;
            $query = $ref['query'] ?? ($b['query'] ?? null);
        } else {
            if ($refPath[0] === '/') {
                $path = self::removeDotSegments($refPath);
            } else {
                $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
                $path = self::removeDotSegments(($directory === '' ? '/' : $directory) . $refPath);
            }
            $query = $ref['query'] ?? null;
        }

        return $b['scheme'] . '://' . $authority . $path
            . ($query !== null ? '?' . $query : '')
            . (isset($ref['fragment']) ? '#' . $ref['fragment'] : '');
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private static function removeDotSegments(string $path): string
    {
        $output = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);
        $last = substr($path, -3) === '/..' || substr($path, -2) === '/.';
        if ($last && substr($result, -1) !== '/') {
            $result .= '/';
        }
        return $result === '' ? '/' : $result;
    }

    /**
     * @param string[] $strings
     */
    private static function collectStrings(DOMNode $node, array &$strings): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $strings[] = $child->nodeValue;
            } elseif ($child->nodeType === XML_ELEMENT_NODE
                && !in_array(strtolower($child->nodeName), self::SKIPPED_TEXT_PARENTS, true)) {
                self::collectStrings($child, $strings);
            }
        }
    }
}
