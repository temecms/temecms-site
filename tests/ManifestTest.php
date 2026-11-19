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
})->skip(fn (): bool => ! temecmsSiteSocket(), 'needs the plugin types socket (Teme 1.2), which reads contributes.types');

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
        'plugin' => ['category', 'package', 'icon', 'asks', 'where_short', 'status'],
        'theme' => ['badge', 'meta', 'status'],
    ]);
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
    $files = [...glob(temecmsSiteRoot().'/src/*.php'), ...glob(temecmsSiteRoot().'/seed/*.php'), ...glob(temecmsSiteRoot().'/tests/*.php')];

    foreach ($files as $file) {
        $head = (string) file_get_contents($file, length: 4000);

        expect($head)->toStartWith("<?php\n\n/**")
            ->toContain('@package TemecmsSite')
            ->toContain('@since   1.0.0')
            ->toContain('@license MIT');
    }
});
