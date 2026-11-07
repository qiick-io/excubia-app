<?php

declare(strict_types=1);

use App\Mail\AlertMail;
use App\Mail\WeeklyDigestMail;

/**
 * Security review (#51): strings in the weekly digest and alert emails come from pushed reports, so Markdown in
 * them is shown as text and never becomes a link or HTML in the email.
 */
it('escapes Markdown links and HTML from pushed strings in the weekly digest', function () {
    $html = (new WeeklyDigestMail([[
        'name' => 'Shop',
        'url' => 'https://excubia.test/projects/shop',
        'newFindings' => [['severity' => 'high', 'text' => '[Reset your password](https://evil.example/login) <img src=x onerror=alert(1)>']],
        'newFindingsCount' => 1,
        'regressions' => [],
        'eol' => [],
        'exceptions' => [['class' => 'RuntimeException', 'where' => '[here](https://evil.example)', 'count' => 1]],
        'schedule' => [['task' => '![x](https://evil.example/pixel.png)', 'status' => 'missed']],
    ]]))->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->not->toContain('src="https://evil.example')
        ->not->toContain('<img src=x')
        ->toContain('href="https://excubia.test/projects/shop"');
});

it('escapes Markdown links from pushed strings in alert emails', function () {
    $html = (new AlertMail([
        'id' => 'a', 'event' => 'finding.new', 'severity' => 'high',
        'project' => ['name' => 'Shop', 'slug' => 'shop'], 'url' => 'https://excubia.test/projects/shop',
    ], 'New finding: [Reset your password](https://evil.example/login)'))->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->toContain('https://excubia.test/projects/shop');
});
