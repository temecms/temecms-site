<?php

/**
 * The directory sync on a test site: it makes a published listing for each
 * package, changes one when its package changes, leaves everything alone
 * on a second run, and reports the listings whose package left the index.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Seams\PluginServices;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\HtmlToDocument;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Media\MediaWriter;
use Teme\Testing\Acting;
use Teme\Testing\TemeTestCase;
use Temecms\Site\Directory\DirectorySync;
use Temecms\Site\Directory\ReadmeToDocument;
use Temecms\Site\Directory\SyncReport;

require_once __DIR__.'/Support/helpers.php';

uses(TemeTestCase::class);

beforeEach(function (): void {
    if (! interface_exists(HtmlToDocument::class)) {
        $this->markTestSkipped('needs Teme’s HtmlToDocument (the directory source)');
    }

    $this->admin = $this->person('administrator');
    temecmsSiteTypes($this, $this->admin);

    $this->images = 0;
    Http::fake(function (Request $request) {
        if (parse_url($request->url(), PHP_URL_HOST) === 'temecms.github.io') {
            $this->images++;

            return Http::response(temecmsSitePng(), 200, ['Content-Type' => 'image/png']);
        }

        return Http::response('', 404);
    });

    $doors = app(PluginServices::class)->for('temecms/temecms-site', 'temecms-site', ['content.read', 'content.write', 'media.write'], ['temecms.github.io']);
    $this->reader = $doors[ContentReader::class];
    $this->sync = new DirectorySync($doors[ContentReader::class], $doors[ContentWriter::class], $doors[MediaWriter::class], new ReadmeToDocument(temecmsSiteHtmlToDocument()));
    $this->run = fn (array $packages): SyncReport => (new Acting)->as($this->admin, fn (): SyncReport => $this->sync->run($packages, (string) $this->admin->id), 'Syncing the directory');
    $this->ids = function (): array {
        $ids = [];
        foreach (DB::table('contents')->whereIn('type', ['plugin', 'theme'])->get(['id', 'fields']) as $row) {
            foreach ((array) json_decode((string) $row->fields, true) as $value) {
                if (is_string($value) && preg_match('~^[a-z0-9-]+/[a-z0-9-]+$~', $value) === 1) {
                    $ids[$value] = (string) $row->id;
                }
            }
        }

        return $ids;
    };
    $this->listing = function (string $package): ?array {
        return (new Acting)->as($this->admin, function () use ($package): ?array {
            foreach ($this->reader->published(new ItemQuery(types: ['plugin', 'theme'], limit: 200))->items as $ref) {
                $draft = $this->reader->draft($ref->id, (string) $this->admin->id);

                if (($draft->fields['package'] ?? null) === $package) {
                    return ['ref' => $ref, 'draft' => $draft];
                }
            }

            return null;
        }, 'Reading the listings');
    };
});

it('makes a published listing for each package, with its fields, categories, README and pictures', function (): void {
    $report = ($this->run)([temecmsSitePackage('temecms/teme-seo'), temecmsSitePackage('acme/ledger', ['kind' => 'theme', 'label' => 'Ledger'])]);

    expect($report->created)->toBe(['temecms/teme-seo', 'acme/ledger'])
        ->and($report->skipped)->toBe([]);

    $seo = ($this->listing)('temecms/teme-seo');
    $fields = $seo['draft']->fields;

    expect($seo['ref'])->toBeInstanceOf(ItemRef::class)
        ->and($seo['ref']->type)->toBe('plugin')
        ->and($seo['ref']->status)->toBe('published')
        ->and($seo['ref']->title)->toBe('Teme SEO')
        ->and($seo['draft']->slug)->toBe('teme-seo')
        ->and($seo['draft']->terms['directory_category'] ?? [])->toBe(['seo', 'analytics'])
        ->and($fields['summary'])->toBe('Titles, descriptions and search checks.')
        ->and($fields['categories'])->toBe('seo, analytics')
        ->and($fields['picked'])->toBeTrue()
        ->and($fields['author_name'])->toBe('TemeCMS')
        ->and($fields['author_github'])->toBe('temecms')
        ->and($fields['latest_version'])->toBe('1.0.1')
        ->and($fields['works_with'])->toBe('1.1, 1.2')
        ->and($fields['updated_on'])->toBe('2026-11-18')
        ->and($fields['listed_on'])->toBe('2026-10-01')
        ->and($fields['link_source'])->toBe('https://github.com/temecms/teme-seo')
        ->and($fields['link_issues'])->toBe('https://github.com/temecms/teme-seo/issues')
        ->and((int) $fields['stars'])->toBe(42)
        ->and((int) $fields['open_issues'])->toBe(3)
        ->and(json_decode($fields['releases'], true))->toBe([
            ['version' => '1.0.1', 'date' => '2026-11-18', 'works_with' => ['1.1', '1.2'], 'held' => false],
            ['version' => '1.0.0', 'date' => '2026-10-01', 'works_with' => ['1.1'], 'held' => false],
        ])
        ->and(json_decode($fields['capabilities'], true))->toBe([['key' => 'content.read', 'words' => 'Read your published content']])
        ->and($fields['changelog'])->toContain('## 1.0.1')
        ->and($fields['icon_image']['id'] ?? null)->toBeString()
        ->and(json_decode($fields['screenshots'], true))->toHaveCount(1)
        ->and(json_decode($fields['screenshots'], true)[0]['caption'])->toBe('The audit table')
        ->and(json_encode($seo['draft']->body))->toContain('Install')
        ->and(json_encode($seo['draft']->body))->not->toContain('alert')
        ->and($this->images)->toBe(4)
        ->and(($this->listing)('acme/ledger')['ref']->type)->toBe('theme');
});

it('changes nothing when run twice with the same index', function (): void {
    $packages = [temecmsSitePackage('temecms/teme-seo')];
    ($this->run)($packages);
    $before = ($this->listing)('temecms/teme-seo')['draft']->revisionId;
    $fetched = $this->images;

    $report = ($this->run)($packages);

    expect($report->unchanged)->toBe(['temecms/teme-seo'])
        ->and($report->created)->toBe([])
        ->and($report->updated)->toBe([])
        ->and(($this->listing)('temecms/teme-seo')['draft']->revisionId)->toBe($before)
        ->and($this->images)->toBe($fetched);
});

it('updates a listing when its package changes, keeping the pictures it already copied', function (): void {
    ($this->run)([temecmsSitePackage('temecms/teme-seo')]);
    $fetched = $this->images;
    $icon = ($this->listing)('temecms/teme-seo')['draft']->fields['icon_image']['id'];

    $report = ($this->run)([temecmsSitePackage('temecms/teme-seo', ['latest' => '1.1.0', 'label' => 'Teme SEO 2', 'categories' => ['analytics']])]);
    $seo = ($this->listing)('temecms/teme-seo');

    expect($report->updated)->toBe(['temecms/teme-seo'])
        ->and($seo['ref']->status)->toBe('published')
        ->and($seo['ref']->title)->toBe('Teme SEO 2')
        ->and($seo['draft']->fields['latest_version'])->toBe('1.1.0')
        ->and($seo['draft']->terms['directory_category'] ?? [])->toBe(['analytics'])
        ->and($seo['draft']->fields['icon_image']['id'])->toBe($icon)
        ->and($this->images)->toBe($fetched);
});

it('takes a listing whose package left the index back to a draft, never deleting it', function (): void {
    ($this->run)([temecmsSitePackage('temecms/teme-seo'), temecmsSitePackage('acme/forms')]);

    $report = ($this->run)([temecmsSitePackage('temecms/teme-seo')]);
    $forms = (new Acting)->as($this->admin, fn () => $this->reader->draft(($this->ids)()['acme/forms'], (string) $this->admin->id), 'Reading the listing');

    expect($report->left)->toBe(['acme/forms'])
        ->and(($this->listing)('acme/forms'))->toBeNull()
        ->and($forms->item->status)->toBe('draft')
        ->and($forms->fields['package'])->toBe('acme/forms');
});

it('keeps a listing whose package is still in the index when its detail can’t be read', function (): void {
    ($this->run)([temecmsSitePackage('temecms/teme-seo'), temecmsSitePackage('acme/forms')]);

    $report = (new Acting)->as($this->admin, fn (): SyncReport => $this->sync->run([temecmsSitePackage('temecms/teme-seo')], (string) $this->admin->id, ['acme/forms']), 'Syncing the directory');

    expect($report->left)->toBe([])
        ->and(($this->listing)('acme/forms')['ref']->status)->toBe('published');
});

it('publishes a listing again when its package comes back to the index', function (): void {
    ($this->run)([temecmsSitePackage('acme/forms')]);
    ($this->run)([]);

    $report = ($this->run)([temecmsSitePackage('acme/forms')]);

    expect($report->updated)->toBe(['acme/forms'])
        ->and(($this->listing)('acme/forms')['ref']->status)->toBe('published');
});

it('skips a package it can’t read and leaves listings it didn’t make alone', function (): void {
    $own = (new Acting)->as($this->admin, fn (): ItemRef => app(PluginServices::class)
        ->for('temecms/temecms-site', 'temecms-site', ['content.write'], [])[ContentWriter::class]
        ->create(new NewItem('plugin', 'Planned', ['type' => 'doc', 'content' => []], ['package' => 'teme/planned']), (string) $this->admin->id), 'Making a listing by hand');

    $report = ($this->run)([['name' => 'Not A Name', 'kind' => 'plugin', 'label' => 'X'], temecmsSitePackage('acme/forms', ['kind' => 'widget'])]);

    expect($report->created)->toBe([])
        ->and(array_keys($report->skipped))->toBe(['#1', '#2'])
        ->and($report->left)->toBe([])
        ->and($own->status)->toBe('draft');
});

it('drops a picture it can’t fetch instead of leaving it broken', function (): void {
    Http::swap(new Factory);
    Http::fake(fn () => Http::response('', 404));

    $report = ($this->run)([temecmsSitePackage('temecms/teme-seo')]);
    $fields = ($this->listing)('temecms/teme-seo')['draft']->fields;

    expect($report->created)->toBe(['temecms/teme-seo'])
        ->and($fields['icon_image'] ?? null)->toBeNull()
        ->and(json_decode($fields['screenshots'], true))->toBe([]);
});
