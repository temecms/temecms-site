<?php

/**
 * The Showcase form: a site sent by a visitor is stored as an unapproved
 * Showcase site waiting for review, with its screenshot's address for the
 * person who approves it, and nothing personal in what is published.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

use Teme\Contracts\Content\Submissions;
use Teme\Testing\TemeTestCase;

require_once __DIR__.'/Support/helpers.php';

uses(TemeTestCase::class);

beforeEach(function (): void {
    if (! interface_exists(Submissions::class)) {
        $this->markTestSkipped('needs Teme’s Submissions door (the directory source)');
    }

    temecmsSiteRelease();
    $this->admin = $this->person('administrator');
    $this->site = $this->plugin(temecmsSiteRoot())->enable($this->admin);

    $this->send = fn (array $form) => $this->fetch('/temecms-site/submit-site', 'POST', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Host' => 'localhost',
        'Origin' => 'http://localhost',
        'Referer' => 'http://localhost/showcase/',
    ], http_build_query($form));

    $this->form = [
        'name' => 'The Daily Example',
        'address' => 'https://daily.example',
        'kind' => 'news',
        'screenshot' => 'https://daily.example/shot.png',
        'theme' => 'Teme Default',
        'plugins' => 'Teme SEO, Contents',
        'line' => 'A city paper, on Teme since spring.',
        'contact' => 'editor@daily.example',
        'website' => '',
    ];
});

it('stores the site as an unapproved Showcase site waiting for review', function (): void {
    ($this->send)($this->form)->assertStatus(303)->assertHeader('Location', '/showcase/?submitted=showcase');

    $stored = temecmsSiteSubmissions($this->admin);

    expect($stored)->toHaveCount(1)
        ->and($stored[0]->item->type)->toBe('showcase')
        ->and($stored[0]->item->status)->toBe('review')
        ->and($stored[0]->item->title)->toBe('The Daily Example')
        ->and($stored[0]->fields['site_address'])->toBe('https://daily.example')
        ->and($stored[0]->fields['site_kind'])->toBe('news')
        ->and($stored[0]->fields['owner_line'])->toBe('A city paper, on Teme since spring.')
        ->and((bool) $stored[0]->fields['approved'])->toBeFalse()
        ->and(json_encode($stored[0]->body, JSON_UNESCAPED_SLASHES))->toContain('https://daily.example/shot.png')
        ->toContain('Teme SEO, Contents')
        ->not->toContain('editor@daily.example')
        ->and(json_encode($stored[0]->fields))->not->toContain('editor@daily.example');
});

it('names the first wrong field and stores nothing', function (array $change, string $field): void {
    ($this->send)([...$this->form, ...$change])->assertStatus(303)->assertHeader('Location', '/showcase/?error='.$field);

    expect(temecmsSiteSubmissions($this->admin))->toBe([]);
})->with([
    'no address' => [['address' => 'daily.example'], 'address'],
    'a kind it doesn’t know' => [['kind' => 'shop'], 'kind'],
    'a screenshot that isn’t an address' => [['screenshot' => 'javascript:alert(1)'], 'screenshot'],
    'no contact' => [['contact' => 'nobody'], 'contact'],
]);

it('stores nothing when the honeypot is filled', function (): void {
    ($this->send)([...$this->form, 'website' => 'https://spam.example'])->assertStatus(303);

    expect(temecmsSiteSubmissions($this->admin))->toBe([]);
});

it('answers at most five posts a minute from one address', function (): void {
    foreach (range(1, 5) as $post) {
        ($this->send)([...$this->form, 'kind' => 'shop'])->assertStatus(303);
    }

    ($this->send)($this->form)->assertStatus(429);
});
