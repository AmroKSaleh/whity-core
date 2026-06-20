<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

use DOMAttr;
use DOMDocument;
use DOMElement;

/**
 * Hardened SVG sanitizer (Tenant Branding — security-critical).
 *
 * Layered defense:
 *  1) Reject any DOCTYPE/DTD up front (kills XXE, billion-laughs, entity
 *     expansion) and parse with LIBXML_NONET + external entity loading disabled.
 *  2) Walk the DOM: drop any element not on the allowlist; drop every attribute
 *     not on the allowlist; drop on* handlers, javascript:/data:/external hrefs
 *     (keep same-document #fragment refs only), and dangerous CSS in style.
 *  3) Re-serialize the cleaned DOM — the STORED bytes are the sanitized output,
 *     never the original upload.
 *
 * (The frontend additionally renders SVGs via <img> only; this is the backstop.)
 */
final class SvgSanitizer
{
    /** @var array<string, true> Allowed element local-names (lowercased). */
    private const ALLOWED_ELEMENTS = [
        'svg' => true, 'g' => true, 'path' => true, 'rect' => true, 'circle' => true,
        'ellipse' => true, 'line' => true, 'polyline' => true, 'polygon' => true,
        'text' => true, 'tspan' => true, 'defs' => true, 'lineargradient' => true,
        'radialgradient' => true, 'stop' => true, 'clippath' => true, 'mask' => true,
        'use' => true, 'title' => true, 'desc' => true, 'symbol' => true,
    ];

    /** @var array<string, true> Allowed attribute local-names (lowercased). */
    private const ALLOWED_ATTRS = [
        'id' => true, 'class' => true, 'd' => true, 'fill' => true, 'fill-opacity' => true,
        'fill-rule' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true,
        'stroke-linejoin' => true, 'stroke-dasharray' => true, 'stroke-opacity' => true,
        'opacity' => true, 'transform' => true, 'viewbox' => true, 'width' => true,
        'height' => true, 'x' => true, 'y' => true, 'x1' => true, 'y1' => true, 'x2' => true,
        'y2' => true, 'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true,
        'points' => true, 'offset' => true, 'stop-color' => true, 'stop-opacity' => true,
        'gradientunits' => true, 'gradienttransform' => true, 'patternunits' => true,
        'clip-path' => true, 'clip-rule' => true, 'mask' => true, 'preserveaspectratio' => true,
        'xmlns' => true, 'version' => true, 'style' => true, 'text-anchor' => true,
        'font-family' => true, 'font-size' => true, 'font-weight' => true,
    ];

    public function sanitize(string $svg): string
    {
        // (1) Hard-reject DOCTYPE/DTD before any parsing.
        if (preg_match('/<!DOCTYPE/i', $svg) === 1 || preg_match('/<!ENTITY/i', $svg) === 1) {
            throw new SvgRejectedException('SVG with a DOCTYPE/DTD is not allowed.');
        }

        // Pre-parse text pass: attribute values containing data:/javascript: URIs
        // may have unescaped characters that make the XML not well-formed.
        // Strip them before DOM parsing so the document can be safely parsed then
        // further cleaned by the DOM walk.
        $svg = preg_replace('/\s+(href|src|action|xlink:href)\s*=\s*"[^"]*(?:data:|javascript:)[^"]*"/i', '', $svg) ?? $svg;
        $svg = preg_replace("/\s+(href|src|action|xlink:href)\s*=\s*'[^']*(?:data:|javascript:)[^']*'/i", '', $svg) ?? $svg;

        $previous = libxml_use_internal_errors(true);
        // Disable any network/entity loading on supported versions.
        if (function_exists('libxml_set_external_entity_loader')) {
            libxml_set_external_entity_loader(static fn () => null);
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        $ok = $doc->loadXML($svg, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok === false || $doc->documentElement === null) {
            throw new SvgRejectedException('SVG is not well-formed XML.');
        }
        if (strtolower($doc->documentElement->localName ?? '') !== 'svg') {
            throw new SvgRejectedException('Root element must be <svg>.');
        }

        $this->cleanElement($doc->documentElement);

        $out = $doc->saveXML($doc->documentElement);
        if ($out === false) {
            throw new SvgRejectedException('Failed to re-serialize sanitized SVG.');
        }
        return $out;
    }

    private function cleanElement(DOMElement $el): void
    {
        // Remove disallowed child elements first (iterate over a static copy).
        $children = [];
        foreach ($el->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if (!isset(self::ALLOWED_ELEMENTS[strtolower($child->localName ?? '')])) {
                    $el->removeChild($child);
                    continue;
                }
                $this->cleanElement($child);
            }
        }

        // Strip disallowed / dangerous attributes (iterate over a static copy).
        $attrs = [];
        foreach ($el->attributes ?? [] as $attr) {
            $attrs[] = $attr;
        }
        foreach ($attrs as $attr) {
            if ($attr instanceof DOMAttr) {
                $this->cleanAttribute($el, $attr);
            }
        }
    }

    private function cleanAttribute(DOMElement $el, DOMAttr $attr): void
    {
        $name = strtolower($attr->localName ?? $attr->name);
        $value = $attr->value;

        // Event handlers.
        if (str_starts_with($name, 'on')) {
            $el->removeAttributeNode($attr);
            return;
        }

        // href / xlink:href: only same-document fragments survive.
        if ($name === 'href') {
            $trimmed = ltrim($value);
            if (!str_starts_with($trimmed, '#')) {
                $el->removeAttributeNode($attr);
            }
            return;
        }

        if (!isset(self::ALLOWED_ATTRS[$name])) {
            $el->removeAttributeNode($attr);
            return;
        }

        // style: reject expression()/url()/@import/javascript:.
        if ($name === 'style') {
            if (preg_match('/expression\s*\(|url\s*\(|@import|javascript:/i', $value) === 1) {
                $el->removeAttributeNode($attr);
            }
            return;
        }

        // Any remaining value carrying a javascript:/data: scheme is dropped.
        if (preg_match('/javascript:|data:/i', $value) === 1) {
            $el->removeAttributeNode($attr);
        }
    }
}
