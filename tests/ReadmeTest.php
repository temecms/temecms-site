<?php

/**
 * A listing's README, from Markdown to the document stored as its body:
 * safe HTML first, with raw HTML and unsafe links dropped, then Teme's own
 * converter.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

use Teme\Contracts\Content\HtmlToDocument;
use Teme\Testing\TemeTestCase;
use Temecms\Site\Directory\ReadmeToDocument;

require_once __DIR__.'/Support/helpers.php';

uses(TemeTestCase::class);

it('turns Markdown into HTML with GitHub’s tables and task lists', function (): void {
    $html = ReadmeToDocument::html("## Features\n\n- [x] Audits\n- [ ] Reports\n\n| Setting | Default |\n|---|---|\n| Depth | 3 |\n\n```php\necho 1;\n```\n");

    expect($html)->toContain('<h2>Features</h2>')
        ->toContain('type="checkbox"')
        ->toContain('<table>')
        ->toContain('<td>Depth</td>')
        ->toContain('<code class="language-php">');
});

it('drops raw HTML, scripts and unsafe link addresses', function (): void {
    $html = ReadmeToDocument::html("Hello <b>bold</b>\n\n<script>alert(1)</script>\n\n<iframe src=\"https://example.com\"></iframe>\n\n[click](javascript:alert(1)) and ![x](data:image/png;base64,AAAA) and [ok](https://example.com/a)\n");

    expect($html)->not->toContain('<script')
        ->not->toContain('<iframe')
        ->not->toContain('<b>')
        ->not->toContain('javascript:')
        ->not->toContain('data:image')
        ->toContain('<a href="https://example.com/a">ok</a>');
});

it('reads no more than 100 KB of a README', function (): void {
    $html = ReadmeToDocument::html(str_repeat('word ', 30_000));

    expect(strlen(strip_tags($html)))->toBeLessThanOrEqual(ReadmeToDocument::MOST_BYTES);
});

it('stores the README as an editor document through Teme’s converter', function (): void {
    $document = (new ReadmeToDocument(temecmsSiteHtmlToDocument()))->convert("## Install\n\nRun the command.\n\n<script>alert(1)</script>\n");

    expect($document['type'])->toBe('doc')
        ->and(json_encode($document))->toContain('Install')
        ->toContain('Run the command.')
        ->not->toContain('alert');
})->skip(fn (): bool => ! interface_exists(HtmlToDocument::class), 'needs Teme’s HtmlToDocument (the directory source)');
