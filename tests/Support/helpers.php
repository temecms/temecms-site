<?php

/**
 * What the plugin's tests share: its folder, its JSON files, whether the
 * running Teme reads a plugin's types, and the types brought to a test site.
 *
 * @package TemecmsSite
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seams\PluginServices;
use Illuminate\Support\Facades\Artisan;
use Teme\Contracts\Content\HtmlToDocument;
use Teme\Testing\Person;
use Teme\Testing\TemeTestCase;

if (! function_exists('temecmsSiteRoot')) {
    // The plugin's own classes, for tests that use them before Teme has enabled it.
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Temecms\\Site\\')) {
            $file = dirname(__DIR__, 2).'/src/'.str_replace('\\', '/', substr($class, strlen('Temecms\\Site\\'))).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    /** The plugin's folder. */
    function temecmsSiteRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * A JSON file of the plugin's, read.
     *
     * @return array<string, mixed>
     */
    function temecmsSiteJson(string $file): array
    {
        return json_decode((string) file_get_contents(temecmsSiteRoot().'/'.$file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Whether the running Teme reads `contributes.types` (Teme 1.2 and later). */
    function temecmsSiteSocket(): bool
    {
        $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, 512, JSON_THROW_ON_ERROR);

        return isset($schema['properties']['contributes']['properties']['types']);
    }

    /**
     * Brings the plugin's types and fields to the test's site: by enabling the plugin where Teme reads its
     * types, or else by importing its types file as the operator does, which is what the old theme seed did.
     */
    function temecmsSiteTypes(TemeTestCase $test, Person $admin): void
    {
        if (temecmsSiteSocket()) {
            temecmsSiteRelease();
            $test->plugin(temecmsSiteRoot())->enable($admin);

            return;
        }

        $status = Artisan::call('teme:types:import', ['file' => temecmsSiteRoot().'/types/teme-types.json']);
        expect($status)->toBe(0, Artisan::output());
        app(PluginRegistry::class)->flush();
    }

    /**
     * Runs the test's Teme as 1.2.0 at least, the release the plugin requires: a development build of the
     * socket's branch still calls itself by the release before it.
     */
    function temecmsSiteRelease(): void
    {
        if (version_compare((string) config('teme.version'), '1.2.0', '<')) {
            config(['teme.version' => '1.2.0']);
            app(PluginRegistry::class)->flush();
        }
    }

    /**
     * The fields the plugin and theme listings gain for the directory, in the order the types file gives them.
     *
     * @return list<string>
     */
    function temecmsSiteListingFields(): array
    {
        return [
            'summary', 'icon_image', 'categories', 'picked', 'author_name', 'author_github', 'latest_version', 'works_with',
            'updated_on', 'listed_on', 'link_source', 'link_issues', 'link_docs', 'link_support', 'stars', 'open_issues',
            'releases', 'capabilities', 'screenshots', 'changelog', 'directory_sync',
        ];
    }

    /**
     * A package's detail file as the directory's index publishes it, with these values changed.
     *
     * @param  array<string, mixed>  $changes
     *
     * @return array<string, mixed>
     */
    function temecmsSitePackage(string $name, array $changes = []): array
    {
        [$vendor, $short] = explode('/', $name);
        $assets = "https://temecms.github.io/directory/assets/{$name}/1.0.1";

        return [
            'name' => $name,
            'kind' => 'plugin',
            'categories' => ['seo', 'analytics'],
            'picked' => true,
            'label' => 'Teme SEO',
            'summary' => 'Titles, descriptions and search checks.',
            'author' => ['name' => 'TemeCMS', 'github' => 'temecms'],
            'latest' => '1.0.1',
            'updated' => '2026-11-18T10:00:00Z',
            'listed' => '2026-10-01',
            'works_with' => ['1.1', '1.2'],
            'icon' => "{$assets}/icon.png",
            'readme' => "Search checks for every article.\n\n## Install\n\nAdd it under Plugins.\n\n<script>alert(1)</script>\n",
            'changelog' => "# Changelog\n\n## 1.0.1 (2026-11-18)\n\n- Fixes.\n\n## 1.0.0 (2026-10-01)\n\n- First release.\n",
            'screenshots' => [['url' => "{$assets}/audit.png", 'caption' => 'The audit table']],
            'capabilities' => [['key' => 'content.read', 'words' => 'Read your published content']],
            'links' => ['source' => "https://github.com/{$vendor}/{$short}", 'issues' => "https://github.com/{$vendor}/{$short}/issues", 'docs' => null, 'support' => null],
            'stars' => 42,
            'issues' => 3,
            'releases' => [
                ['version' => '1.0.0', 'date' => '2026-10-01', 'works_with' => ['1.1'], 'dist' => ['url' => 'https://api.github.com/x', 'sha256' => str_repeat('a', 64)], 'held' => false],
                ['version' => '1.0.1', 'date' => '2026-11-18', 'works_with' => ['1.1', '1.2'], 'dist' => ['url' => 'https://api.github.com/y', 'sha256' => str_repeat('b', 64)], 'held' => false],
            ],
            ...$changes,
        ];
    }

    /** A small PNG, for pictures the tests fetch. */
    function temecmsSitePng(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 40));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * Teme's own HTML-to-document converter, as a plugin is given it.
     *
     * @return HtmlToDocument
     */
    function temecmsSiteHtmlToDocument(): object
    {
        $doors = app(PluginServices::class)->for('temecms/temecms-site', 'temecms-site', [], []);

        return $doors[HtmlToDocument::class];
    }

    /** The TemeCMS theme's folder named by TEMECMS_THEME, or null. */
    function temecmsSiteTheme(): ?string
    {
        $folder = getenv('TEMECMS_THEME');

        return is_string($folder) && is_file($folder.'/teme-theme.json') ? (string) realpath($folder) : null;
    }
}
