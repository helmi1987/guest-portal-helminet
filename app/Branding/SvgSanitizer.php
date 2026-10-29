<?php

namespace App\Branding;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanitizes uploaded SVG logos/favicons (docs/branding.md). Active or external content is rejected
 * (not silently fixed): scripts, foreignObject and other embedding elements, event handler
 * attributes, javascript:/external references, DOCTYPE/entities, @import. Harmless editor
 * metadata (comments, processing instructions, foreign-namespace elements/attributes such as
 * Inkscape's, animation and other unknown elements) is removed. The result is re-serialized.
 */
final class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** Elements that can run code or load other documents: the file is rejected. */
    private const DANGEROUS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener',
        'audio', 'video', 'frame', 'frameset', 'applet', 'base', 'link', 'meta'];

    /** Elements kept; any other element (incl. animate/set, a) is removed with its content. */
    private const ALLOWED = ['svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse',
        'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'pattern', 'marker', 'image', 'style', 'switch', 'filter', 'feblend', 'fecolormatrix',
        'fecomponenttransfer', 'fecomposite', 'feflood', 'fegaussianblur', 'femerge', 'femergenode', 'feoffset',
        'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fedropshadow', 'femorphology', 'fetile', 'feturbulence'];

    /**
     * @throws BrandingException invalid (not an SVG) or unsafe
     */
    public static function sanitize(string $svg): string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $svg)) {
            throw new BrandingException('svg_unsafe');
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT / DTDLOAD: entities are rejected above, nothing is fetched (NONET).
            $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $doc->documentElement;
        if (! $loaded || $root === null || $root->namespaceURI !== self::SVG_NS || $root->localName !== 'svg') {
            throw new BrandingException('invalid_type');
        }

        self::clean($root);

        return $doc->saveXML($root)."\n";
    }

    private static function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attr) {
            /** @var DOMAttr $attr */
            self::checkAttribute($element, $attr);
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            /** @var DOMNode $child */
            if ($child instanceof DOMElement) {
                $name = strtolower($child->localName);
                if (in_array($name, self::DANGEROUS, true)) {
                    throw new BrandingException('svg_unsafe');
                }
                if ($child->namespaceURI !== self::SVG_NS || ! in_array($name, self::ALLOWED, true)) {
                    $element->removeChild($child);

                    continue;
                }
                if ($name === 'style') {
                    self::checkCss($child->textContent);
                }
                self::clean($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $element->removeChild($child);
            } elseif ($child->nodeType === XML_ENTITY_REF_NODE) {
                throw new BrandingException('svg_unsafe');
            }
        }
    }

    private static function checkAttribute(DOMElement $element, DOMAttr $attr): void
    {
        $name = strtolower($attr->localName);
        // Control characters and whitespace are ignored by browsers inside URL schemes ("java\tscript:").
        $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $attr->value));

        if (str_starts_with($name, 'on')) {
            throw new BrandingException('svg_unsafe');
        }
        if (preg_match('/(java|vb)script:|data:text\/html|data:image\/svg/', $value)) {
            throw new BrandingException('svg_unsafe');
        }
        if ($name === 'href') {
            if (! self::isLocalReference($value)) {
                throw new BrandingException('svg_unsafe');
            }

            return;
        }
        if ($attr->namespaceURI !== null && $attr->namespaceURI !== self::XLINK_NS) {
            // Editor attributes (inkscape:*, sodipodi:*), xml:space etc. are not needed for rendering.
            if ($attr->namespaceURI !== 'http://www.w3.org/XML/1998/namespace') {
                $element->removeAttributeNode($attr);
            }

            return;
        }
        self::checkCss($attr->value);
    }

    /** Fragment reference ("#id") or embedded raster image. */
    private static function isLocalReference(string $value): bool
    {
        return str_starts_with($value, '#') || preg_match('/^data:image\/(png|jpeg|gif|webp);base64,/', $value) === 1;
    }

    /** Style element/attribute or presentation attribute: no @import, url() only to "#id" or embedded images. */
    private static function checkCss(string $css): void
    {
        $css = strtolower(CssPolicy::decode($css));
        if (str_contains($css, '@import') || str_contains($css, 'expression(')) {
            throw new BrandingException('svg_unsafe');
        }
        foreach (CssPolicy::urls($css) as $url) {
            if (! self::isLocalReference(strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url)))) {
                throw new BrandingException('svg_unsafe');
            }
        }
    }
}
