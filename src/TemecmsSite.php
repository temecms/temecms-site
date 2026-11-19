<?php

/**
 * The temecms-site plugin's own class. The site's content types, fields and
 * taxonomies come from types/teme-types.json, and the Submit form's route
 * and setting from teme-plugin.json, so there is nothing to subscribe to yet.
 *
 * @package TemecmsSite
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * What temecms.com does on its own, beside its theme. Teme builds it once the
 * plugin is enabled; today it listens to no hook.
 *
 * @since 1.0.0
 */
final class TemecmsSite implements Plugin
{
    /**
     * Subscribes to nothing: the plugin's types are declared in its manifest.
     *
     * @since 1.0.0
     */
    public function register(Hooks $hooks): void {}
}
