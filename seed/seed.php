<?php

/**
 * Loads temecms.com's starting content into a Teme install through Teme's own Operations (never raw SQL,
 * never HTML): the product pictures, pages, posts, guides, plugin and theme listings, the theme's regions,
 * its menus and the site's settings. The content types, their fields and taxonomies are not made here: the
 * temecms-site plugin brings them when it is enabled. Run from the Teme install's folder:
 *
 *   SEED_ADMIN_ID=1 php /path/to/temecms-site/seed/seed.php media theme content regions menus settings addresses activate
 *
 * A site an older seed filled takes one more step, `words`, which rewrites the passages that went out of
 * date where they still read as the older seed wrote them (see the README, "Moving an existing site").
 *
 * A plugin can't add an artisan command (Teme leaves a plugin's Laravel providers out), so this is a script
 * that boots Teme as artisan does. SEED_ADMIN_ID names the administrator the content is made as; it
 * defaults to 1. The steps run in the order above, whatever order they are named in, and need nothing
 * beyond what a production install has: no testing kit and no test framework.
 *
 * Steps are repeatable: what exists (by slug, by title, or by the state file) is reused, never duplicated,
 * and what a site already has is left as it is: a published item, region or a menu that holds items is
 * written over only when its kind is named in SEED_FORCE. The state file is
 * storage/app/temecms-site-seed.json, or SEED_STATE: never the plugin's own folder, which is read-only on a
 * live site. A refused step prints what was refused and why, and the seed stops with a non-zero exit.
 *
 * @package TemecmsSite
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

$stop = static function (string $line): never {
    fwrite(STDERR, $line."\n");
    exit(1);
};

$teme = getcwd();
if ($teme === false || ! is_file($teme.'/vendor/autoload.php') || ! is_file($teme.'/bootstrap/app.php')) {
    $stop("Run the seed from the Teme install's folder (on Teme's Docker image: cd /var/www/html).");
}

// As artisan does: Composer's autoloader, then the plugins and themes installed from the admin, then Laravel.
require $teme.'/vendor/autoload.php';
if (is_file($teme.'/bootstrap/extensions.php')) {
    require $teme.'/bootstrap/extensions.php';
}
$app = require $teme.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require_once __DIR__.'/steps.php';

try {
    $made = temecms_site_seed(
        array_values(array_slice($argv, 1)),
        (int) (getenv('SEED_ADMIN_ID') ?: 1),
        getenv('SEED_STATE') ?: null,
        array_values(array_filter(explode(',', (string) getenv('SEED_FORCE')))),
        static function (string $line): void {
            fwrite(STDOUT, $line."\n");
        },
    );
} catch (TemecmsSiteSeedStopped $stopped) {
    $stop($stopped->getMessage());
}

fwrite(STDOUT, ($made === []
    ? 'Nothing to change: the site already has everything the seed makes.'
    : 'Made or changed: '.implode(', ', array_map(static fn (string $kind, int $n): string => "{$kind} {$n}", array_keys($made), array_values($made))).'.')."\n");
