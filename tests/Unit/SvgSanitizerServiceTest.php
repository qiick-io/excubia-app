<?php

declare(strict_types=1);

use App\Services\SvgSanitizerService;

/**
 * Uploaded project logos (#287): an SVG keeps its drawing and loses everything that can run
 * script or reach another origin.
 */
function sanitizeSvg(string $svgSource): ?string
{
    return (new SvgSanitizerService)->sanitize($svgSource);
}

it('keeps the drawing of a harmless SVG', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><defs><linearGradient id="g"/></defs><rect width="10" height="10" fill="url(#g)"/><use href="#g"/></svg>';

    $cleaned = sanitizeSvg($svg);

    expect($cleaned)->toContain('<rect width="10" height="10" fill="url(#g)"/>')
        ->and($cleaned)->toContain('<use href="#g"/>');
});

it('removes script, foreign content and animations', function (string $unsafeMarkup, string $removedFragment) {
    $cleaned = sanitizeSvg('<svg xmlns="http://www.w3.org/2000/svg">'.$unsafeMarkup.'<circle r="4"/></svg>');

    expect($cleaned)->not->toBeNull()
        ->and(strtolower((string) $cleaned))->not->toContain($removedFragment)
        ->and($cleaned)->toContain('<circle r="4"/>');
})->with([
    'script' => ['<script>alert(1)</script>', 'alert'],
    'namespaced script' => ['<svg:script xmlns:svg="http://www.w3.org/2000/svg">alert(1)</svg:script>', 'alert'],
    'foreignObject' => ['<foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://example.com"/></foreignObject>', 'iframe'],
    'set to javascript' => ['<a href="#x"><set attributeName="href" to="javascript:alert(1)"/></a>', 'javascript'],
    'animate' => ['<animate attributeName="href" values="javascript:alert(1)"/>', 'javascript'],
    'processing instruction' => ['<?xml-stylesheet href="https://example.com/x.css"?>', 'xml-stylesheet'],
]);

it('removes event handlers, script values and links that leave the file', function () {
    $cleaned = (string) sanitizeSvg(
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
        .'<a href="javascript:alert(1)"><rect onclick="alert(2)" width="1"/></a>'
        .'<image xlink:href="https://example.com/track.png"/>'
        .'<image href="data:image/png;base64,iVBORw0KGgo="/>'
        .'<rect style="fill: url(javascript:alert(3))"/>'
        .'</svg>',
    );

    expect($cleaned)->not->toContain('onload')
        ->and($cleaned)->not->toContain('onclick')
        ->and($cleaned)->not->toContain('javascript')
        ->and($cleaned)->not->toContain('example.com')
        ->and($cleaned)->toContain('data:image/png;base64');
});

it('refuses documents with a DOCTYPE, malformed XML and other roots', function (string $svgSource) {
    expect(sanitizeSvg($svgSource))->toBeNull();
})->with([
    'entity expansion' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaa">]><svg xmlns="http://www.w3.org/2000/svg">&a;</svg>'],
    'external entity' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>'],
    'malformed' => ['<svg><rect></svg>'],
    'html root' => ['<html><body><script>alert(1)</script></body></html>'],
    'not xml' => ['plain text'],
]);
