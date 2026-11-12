<?php

declare(strict_types=1);

namespace App\Services;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Removes everything that can run script or reach another origin from an uploaded SVG (project
 * logos, #287): script-like elements, event handler attributes, `javascript:` values and links
 * that leave the document. A file with a DOCTYPE is refused outright, so entity expansion and
 * external entities never reach the parser.
 *
 * ponytail: a blocklist on PHP's DOM, chosen to avoid a new dependency. Its ceiling is SVG or
 * browser features it does not know about; that is why a logo is only ever shown through
 * `<img>` (which runs no script in any case) and served with a `sandbox` CSP and `nosniff`
 * (ProjectLogoController). Upgrade path: an allowlist sanitiser (enshrined/svg-sanitize) or
 * rasterising with Imagick, both with the maintainer's OK.
 */
final class SvgSanitizerService
{
    /** Lower-case local names of elements removed with everything inside them. */
    private const array FORBIDDEN_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'canvas',
        'handler', 'listener', 'set', 'animate', 'animatecolor', 'animatemotion', 'animatetransform',
    ];

    /** Link targets kept: fragments inside the file and embedded raster images. */
    private const string SAFE_LINK_PATTERN = '/^(#|data:image\/(png|jpe?g|gif|webp);)/i';

    /**
     * @return string|null the cleaned SVG, or null when the input is not a well-formed SVG
     *                     document or carries a DOCTYPE
     */
    public function sanitize(string $svgSource): ?string
    {
        if (stripos($svgSource, '<!DOCTYPE') !== false || stripos($svgSource, '<!ENTITY') !== false) {
            return null;
        }

        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch anything while parsing; no LIBXML_NOENT, so no entity expansion.
        $isLoaded = $document->loadXML($svgSource, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        $rootElement = $document->documentElement;

        if (! $isLoaded || $rootElement === null || strtolower($rootElement->localName ?? '') !== 'svg') {
            return null;
        }

        $this->removeUnsafeNodes($document);
        $cleanedSvg = $document->saveXML($rootElement);

        return $cleanedSvg === false ? null : $cleanedSvg;
    }

    private function removeUnsafeNodes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        /** @var list<DOMNode> $nodesToRemove */
        $nodesToRemove = [];

        // Processing instructions (such as xml-stylesheet) and comments carry nothing a logo needs.
        foreach ($xpath->query('//processing-instruction() | //comment()') ?: [] as $node) {
            if ($node instanceof DOMNode) {
                $nodesToRemove[] = $node;
            }
        }

        foreach ($xpath->query('//*') ?: [] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($element->localName ?? ''), self::FORBIDDEN_ELEMENTS, true)) {
                $nodesToRemove[] = $element;

                continue;
            }

            $this->removeUnsafeAttributes($element);
        }

        foreach ($nodesToRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function removeUnsafeAttributes(DOMElement $element): void
    {
        /** @var list<DOMAttr> $attributesToRemove */
        $attributesToRemove = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->localName ?? $attribute->name);
            $value = preg_replace('/\s+/', '', $attribute->value) ?? '';
            $isEventHandler = str_starts_with($name, 'on');
            $isUnsafeLink = $name === 'href' && preg_match(self::SAFE_LINK_PATTERN, $value) !== 1;
            $isScriptValue = stripos($value, 'javascript:') !== false;

            if ($isEventHandler || $isUnsafeLink || $isScriptValue) {
                $attributesToRemove[] = $attribute;
            }
        }

        foreach ($attributesToRemove as $attribute) {
            $element->removeAttributeNode($attribute);
        }
    }
}
