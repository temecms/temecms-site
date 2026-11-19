<?php

/**
 * The plugin's manifest, its Composer file and its types file, checked
 * against Teme's own rules: the manifest's schema and validator, and the
 * reader Settings › Types imports with.
 *
 * @package TemecmsSite
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\CodeScan;
use App\Domain\Plugins\ManifestValidator;
use App\Domain\Types\TypesFile;
use Teme\Testing\TemeTestCase;

require_once __DIR__.'/Support/helpers.php';

uses(TemeTestCase::class);

it('gives every key the manifest schema requires, and only keys it knows', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $manifest = temecmsSiteJson('teme-plugin.json');

    expect(array_diff($schema['required'], array_keys($manifest)))->toBe([])
        ->and(array_diff(array_keys($manifest), array_keys($schema['properties'])))->toBe([])
        ->and($manifest['code'])->toMatch('~'.$schema['properties']['code']['pattern'].'~')
        ->and($manifest['name'])->toMatch('~'.$schema['properties']['name']['pattern'].'~')
        ->and($manifest['version'])->toMatch('~'.$schema['properties']['version']['pattern'].'~')
        ->and($manifest['contributes']['types'])->toBe('types/teme-types.json');
});

it('passes the validator Teme reads a plugin with', function (): void {
    temecmsSiteRelease();
    $errors = app(ManifestValidator::class)->errors(temecmsSiteJson('teme-plugin.json'), temecmsSiteRoot(), temecmsSiteJson('composer.json'));

    expect($errors)->toBe([]);
})->skip(fn (): bool => ! temecmsSiteSocket(), 'needs a Teme that reads contributes.types and offers the directory’s doors');

it('names the same package and version in its manifest and its Composer file', function (): void {
    $manifest = temecmsSiteJson('teme-plugin.json');
    $composer = temecmsSiteJson('composer.json');

    expect($composer['name'])->toBe($manifest['name'])
        ->and($composer['version'])->toBe($manifest['version'])
        ->and($composer['type'])->toBe('teme-plugin');
});

it('declares types Settings › Types would import', function (): void {
    $file = temecmsSiteJson('types/teme-types.json');

    expect(fn () => TypesFile::definitions($file))->not->toThrow(Throwable::class);
});

it('keeps the live site’s type keys and addresses, and renames only what people read', function (): void {
    $types = [];
    foreach (TypesFile::definitions(temecmsSiteJson('types/teme-types.json')) as $type) {
        $types[$type->key] = [$type->singular, $type->plural, $type->segment, $type->pattern];
    }

    expect($types)->toBe([
        'doc' => ['Guide', 'Documentation', 'doc-pages', '/docs/{slug}/'],
        'plugin' => ['Plugin listing', 'Plugin directory', 'plugin-pages', '/plugins/{slug}/'],
        'theme' => ['Theme listing', 'Theme directory', 'theme-pages', '/theme-directory/{slug}/'],
        'showcase' => ['Showcase site', 'Showcase', 'showcase-pages', '/showcase/{slug}/'],
    ]);
});

it('gives each type the fields its templates and the seed read', function (): void {
    $fields = [];
    foreach (temecmsSiteJson('types/teme-types.json')['fields'] as $field) {
        $fields[$field['type']][] = $field['handle'];
    }

    expect($fields)->toBe([
        'page' => ['kicker', 'emphasis'],
        'doc' => ['guide', 'group', 'proof', 'order', 'soon', 'written_for'],
        'plugin' => ['category', 'package', 'icon', 'asks', 'where_short', 'status', ...temecmsSiteListingFields()],
        'theme' => ['badge', 'meta', 'status', 'package', ...temecmsSiteListingFields()],
        'showcase' => ['site_address', 'site_kind', 'screenshot', 'theme_item', 'plugin_item_1', 'plugin_item_2', 'plugin_item_3', 'plugin_item_4', 'plugin_item_5', 'plugin_item_6', 'plugin_item_7', 'plugin_item_8', 'owner_line', 'approved'],
    ]);
});

it('files plugin and theme listings under the directory’s categories, the first one the main', function (): void {
    $file = temecmsSiteJson('types/teme-types.json');
    $taxonomies = array_column($file['taxonomies'], null, 'key');
    $types = array_column($file['types'], null, 'key');

    expect(array_keys($taxonomies))->toBe(['directory_category'])
        ->and($taxonomies['directory_category']['hierarchical'])->toBeTrue()
        ->and($taxonomies['directory_category']['public'])->toBeTrue()
        ->and($types['plugin']['taxonomies'])->toBe(['directory_category'])
        ->and($types['theme']['taxonomies'])->toBe(['directory_category'])
        ->and($types['showcase']['taxonomies'])->toBe([]);
});

it('gives the listing fields the kinds the directory sync writes', function (): void {
    $kinds = [];
    foreach (temecmsSiteJson('types/teme-types.json')['fields'] as $field) {
        if (in_array($field['type'], ['plugin', 'theme'], true) && in_array($field['handle'], temecmsSiteListingFields(), true)) {
            $kinds[$field['type']][$field['handle']] = $field['fieldType'].(($field['settings']['dateOnly'] ?? false) === true ? ':date' : '');
        }
    }

    $expected = [
        'summary' => 'text', 'icon_image' => 'media', 'categories' => 'text', 'picked' => 'boolean',
        'author_name' => 'text', 'author_github' => 'text', 'latest_version' => 'text', 'works_with' => 'text',
        'updated_on' => 'datetime:date', 'listed_on' => 'datetime:date',
        'link_source' => 'url', 'link_issues' => 'url', 'link_docs' => 'url', 'link_support' => 'url',
        'stars' => 'number', 'open_issues' => 'number',
        'releases' => 'textarea', 'capabilities' => 'textarea', 'screenshots' => 'textarea', 'changelog' => 'textarea',
        'directory_sync' => 'textarea',
    ];

    expect($kinds)->toBe(['plugin' => $expected, 'theme' => $expected]);
});

it('keeps its own code to Teme’s contracts', function (): void {
    $scan = app(CodeScan::class);
    $files = $scan->sourceFiles(temecmsSiteRoot(), temecmsSiteJson('composer.json'));

    expect($files)->not->toBe([]);
    foreach ($files as $file) {
        expect((string) file_get_contents($file))->not->toMatch('/\buse App\\\\/');
    }
});

it('opens every PHP file with the plugin’s file header', function (): void {
    $files = [...glob(temecmsSiteRoot().'/src/*.php'), ...glob(temecmsSiteRoot().'/src/*/*.php'), ...glob(temecmsSiteRoot().'/seed/*.php'), ...glob(temecmsSiteRoot().'/tests/*.php')];

    foreach ($files as $file) {
        $head = (string) file_get_contents($file, length: 4000);

        expect($head)->toStartWith("<?php\n\n/**")
            ->toContain('@package TemecmsSite')
            ->toMatch('/@since   1\\.\\d+\\.\\d+\n/')
            ->toContain('@license MIT');
    }
});
