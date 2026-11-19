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
use Illuminate\Support\Facades\Artisan;
use Teme\Testing\Person;
use Teme\Testing\TemeTestCase;

if (! function_exists('temecmsSiteRoot')) {
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
            $test->plugin(temecmsSiteRoot())->enable($admin);

            return;
        }

        $status = Artisan::call('teme:types:import', ['file' => temecmsSiteRoot().'/types/teme-types.json']);
        expect($status)->toBe(0, Artisan::output());
        app(PluginRegistry::class)->flush();
    }

    /** The TemeCMS theme's folder named by TEMECMS_THEME, or null. */
    function temecmsSiteTheme(): ?string
    {
        $folder = getenv('TEMECMS_THEME');

        return is_string($folder) && is_file($folder.'/teme-theme.json') ? (string) realpath($folder) : null;
    }
}
