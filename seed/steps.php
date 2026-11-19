<?php

/**
 * The steps of temecms.com's seed, as one function the seed script and the
 * plugin's tests both call. Everything is made through Teme's own
 * Operations, as the administrator named, never in SQL and never as HTML.
 *
 * @package TemecmsSite
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Design\Operations\ActivateTheme;
use App\Domain\Design\Operations\Input\ActivateThemeInput;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Operations\UploadMedia;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Menus\Operations\Input\SaveNavigationItemsInput;
use App\Domain\Menus\Operations\SaveNavigationItems;
use App\Domain\Regions\Operations\CreateRegion;
use App\Domain\Regions\Operations\Input\CreateRegionInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\SessionElevation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

if (! class_exists('TemecmsSiteSeedStopped', false)) {
    /** Why the seed stopped: a refused step, a missing type, or a wrong argument. */
    final class TemecmsSiteSeedStopped extends RuntimeException {}
}

if (! function_exists('temecms_site_seed')) {
    /**
     * Runs the steps named, in their fixed order, and returns what it made or changed, by kind: empty when
     * the site already had everything. Throws TemecmsSiteSeedStopped, having changed nothing more, when a
     * step is refused or can't run.
     *
     * @param  list<string>  $steps  from temecms_site_seed_steps()
     * @param  list<string>  $force  the kinds whose existing items, regions or menus are written over
     * @param  Closure(string): void  $say  where each line of progress goes
     *
     * @return array<string, int>
     */
    function temecms_site_seed(array $steps, int $adminId, ?string $statePath = null, array $force = [], ?Closure $say = null): array
    {
        $say ??= static function (string $line): void {};
        $stop = static function (string $line): never {
            throw new TemecmsSiteSeedStopped($line);
        };
        $unknown = array_diff($steps, temecms_site_seed_steps());
        if ($steps === [] || $unknown !== []) {
            $stop('Name the steps to run, from: '.implode(' ', temecms_site_seed_steps()).'.'.($unknown !== [] ? ' Unknown: '.implode(' ', $unknown).'.' : ''));
        }
        $run = static fn (string $step): bool => in_array($step, $steps, true);

        /** The types, and the page fields, the temecms-site plugin brings: the content needs them. */
        $needs = ['doc' => ['guide', 'group', 'proof', 'order', 'soon', 'written_for'], 'plugin' => ['category', 'package', 'icon', 'asks', 'where_short', 'status'], 'theme' => ['badge', 'meta', 'status'], 'page' => ['kicker', 'emphasis']];

        /** Where the content says {{release}}, the seed writes the Teme release the site runs. */
        $release = '{{release}}';

    $content = json_decode((string) file_get_contents(__DIR__.'/content.json'), true, 512, JSON_THROW_ON_ERROR);

    // The state file lives in the install's storage, which outlives the container; the plugin's folder is read-only.
    $statePath ??= storage_path('app/temecms-site-seed.json');
    $themeState = storage_path('app/theme-temecms-seed.json'); // where the theme's seed (0.1.2) kept it, read once to carry on
    $readFrom = is_file($statePath) ? $statePath : (is_file($themeState) ? $themeState : null);
    $state = $readFrom !== null ? (array) json_decode((string) file_get_contents($readFrom), true) : [];
    $state += ['media' => [], 'content' => []];
    $save = static function () use (&$state, $statePath, $stop): void {
        if (! is_dir(dirname($statePath))) {
            @mkdir(dirname($statePath), 0755, true);
        }
        if (@file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            $stop("The seed can't write its state file {$statePath}. Set SEED_STATE to a file it may write.");
        }
    };

    $admin = User::query()->find($adminId);
    if ($admin === null) {
        $stop("There is no user {$adminId}. Make an administrator first (php artisan teme:user:create --role=administrator), then set SEED_ADMIN_ID to their ID.");
    }
    if (! $admin->hasRole('administrator')) {
        $stop("User {$adminId} isn't an administrator. Set SEED_ADMIN_ID to an administrator's ID.");
    }

    /*
     * Runs one step as the administrator, as the admin's own requests are: signed in, with core's session
     * elevation over a request whose session holds a password confirmed now. A refusal stops the seed with the
     * step and the reason. Puts back what was bound before.
     */
    $as = static function (Closure $step, string $doing) use ($admin, $stop): mixed {
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('auth.password_confirmed_at', now()->getTimestamp());
        $request->setUserResolver(fn (): User => $admin);

        $elevation = app(Elevation::class);
        $signedIn = Auth::user();
        app()->instance(Elevation::class, new SessionElevation($request, app(SettingsReader::class)));
        Auth::setUser($admin);

        try {
            return $step();
        } catch (ValidationException $refused) {
            $stop("Refused: {$doing}. ".implode(' ', array_map(strval(...), array_merge(...array_values($refused->errors())))));
        } catch (AuthorizationException|ElevationRequiredException $refused) {
            $stop("Refused: {$doing}. ".($refused->getMessage() !== '' ? $refused->getMessage() : $refused::class));
        } finally {
            app()->instance(Elevation::class, $elevation);
            $signedIn === null ? Auth::forgetUser() : Auth::setUser($signedIn);
        }
    };
    // A setting's value as text, or null when the site has no such setting yet.
    $setting = static function (string $group, string $key): ?string {
        try {
            return (string) app(SettingsReader::class)->get($group, $key);
        } catch (InvalidArgumentException) {
            return null;
        }
    };
    $aid = (string) $adminId;
    $made = []; // what this run made or changed, by kind
    $count = static function (string $kind) use (&$made): void {
        $made[$kind] = ($made[$kind] ?? 0) + 1;
    };
    $forced = static fn (string $type): bool => in_array($type, $force, true);

    // ---------------------------------------------------------------- the plugin's types, which the content needs
    $missing = [];
    $registry = app(\App\Domain\Fields\FieldRegistry::class);
    $types = app(\App\Domain\Types\ContentTypeRegistry::class);
    foreach ($needs as $type => $handles) {
        if (! $types->has($type)) {
            $missing[] = "the type $type";

            continue;
        }
        $have = array_map(static fn ($f) => $f->handle, $registry->forType($type));
        foreach (array_diff($handles, $have) as $handle) {
            $missing[] = "the field $type.$handle";
        }
    }
    if ($missing !== [] && ($run('content') || $run('menus') || $run('regions'))) {
        $stop('The site lacks '.implode(', ', $missing).'. Enable the temecms-site plugin under Site › Plugins first: it brings the types and fields the content needs.');
    }

    // ---------------------------------------------------------------- media
    if ($run('media')) {
        foreach ($content['media'] as $key => $m) {
            if (isset($state['media'][$key]) && Media::query()->whereKey($state['media'][$key])->exists()) {
                continue;
            }
            // A picture an earlier run uploaded, when the state file is gone: the same file name and title.
            $found = Media::query()->where('original_filename', $key.'.png')->where('title', $m['title'])->value('id');
            if ($found !== null) {
                $state['media'][$key] = (string) $found;
                $save();
                $say("media $key found -> $found");

                continue;
            }
            $path = __DIR__.'/media/'.$key.'.png';
            $item = $as(fn () => app(UploadMedia::class)->execute(new UploadMediaInput($path, $key.'.png', $aid, allowDuplicate: true)), "Uploading the picture $key");
            $id = is_array($item) ? $item['id'] : $item->id;
            $as(fn () => app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($id, $aid, $m['title'], $m['alt'], null, null)), "Describing the picture $key");
            $state['media'][$key] = $id;
            $save();
            $count('media');
            $say("media $key -> $id");
        }
    }

    // ---------------------------------------------------------------- theme (before content: a page picks a template only from the active theme)
    if ($run('theme')) {
        if ($setting('design', 'theme') !== 'temecms') {
            $as(fn () => app(ActivateTheme::class)->execute(new ActivateThemeInput('temecms', $aid)), 'Activating the theme temecms');
            $count('theme');
            $say('temecms is the site theme');
        }
    }

    // ---------------------------------------------------------------- content
    $fieldKeys = static function (string $type): array {
        $map = [];
        foreach (app(\App\Domain\Fields\FieldRegistry::class)->forType($type) as $f) {
            $map[$f->handle] = $f->key;
        }

        return $map;
    };
    $resolve = static function (mixed $node) use (&$resolve, &$state, $release): mixed {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = $resolve($v);
                if ($k === 'contentId' && is_string($node[$k]) && str_starts_with($node[$k], 'teme:content/')) {
                    $node[$k] = substr($node[$k], strlen('teme:content/'));
                }
            }

            return $node;
        }
        if (is_string($node)) {
            if (str_contains($node, $release)) {
                return str_replace($release, \App\Domain\Updates\TemeVersion::current(), $node);
            }
            if (str_starts_with($node, 'teme:seed/')) {
                $ref = substr($node, strlen('teme:seed/'));
                [$kind, $key] = explode(':', $ref, 2);
                $id = $state['content'][$kind.':'.$key] ?? null;
                if ($id === null) {
                    throw new RuntimeException("The seed names [$ref], which has not been made.");
                }

                return 'teme:content/'.$id;
            }
            if (str_starts_with($node, 'seed:')) {
                $key = substr($node, 5);

                return $state['media'][$key] ?? throw new RuntimeException("No picture [$key].");
            }
        }

        return $node;
    };
    if ($run('content')) {
        $items = [];
        foreach (['pages', 'posts', 'docs', 'plugins', 'themes'] as $bucket) {
            foreach ($content[$bucket] ?? [] as $it) {
                $items[] = $it;
            }
        }
        // Pass 1: every item exists as a draft with its slug, so links between items can name them.
        foreach ($items as $it) {
            $sid = $it['type'].':'.$it['key'];
            if (isset($state['content'][$sid]) && Content::query()->whereKey($state['content'][$sid])->exists()) {
                continue;
            }
            /** @var Content|null $c */
            $c = Content::query()->where('type', $it['type'])->where('title', $it['title'])->first();
            if ($c !== null) {
                // Made by an earlier run whose state file is gone.
                $state['content'][$sid] = $c->id;
                $save();
                $say("found $sid -> {$c->id}");

                continue;
            }
            $keys = $fieldKeys($it['type']);
            $fields = [];
            foreach (($it['fields'] ?? []) as $h => $v) {
                $fields[$keys[$h] ?? throw new RuntimeException("No field {$it['type']}.$h")] = $v;
            }
            if (($it['excerpt'] ?? '') !== '') {
                $fields[$keys['excerpt']] = $it['excerpt'];
            }
            if (isset($it['template'])) {
                $fields[$keys['template']] = $it['template'] === 'default' ? '' : $it['template'];
            }
            $fields = $resolve($fields);
            $c = $as(fn () => app(CreateDraft::class)->execute(new CreateDraftInput($it['type'], $it['title'], ['type' => 'doc', 'content' => [['type' => 'paragraph']]], $fields, $aid)), "Drafting {$it['title']}");
            $as(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($c->id, (string) $c->working_revision_id, $aid, slug: $it['slug'])), "Addressing {$it['title']}");
            $state['content'][$sid] = $c->id;
            $save();
            $count('drafts');
            $say("draft $sid -> {$c->id}");
        }
        // Pass 2: the body, with its links resolved to IDs, saved and published. Published items are left alone.
        foreach ($items as $it) {
            $sid = $it['type'].':'.$it['key'];
            $c = Content::query()->findOrFail($state['content'][$sid]);
            if (! empty($c->published_revision_id) && ! $forced($it['type'])) {
                continue;
            }
            $keys = $fieldKeys($it['type']);
            $fields = [];
            foreach (($it['fields'] ?? []) as $h => $v) {
                $fields[$keys[$h]] = $v;
            }
            if (($it['excerpt'] ?? '') !== '') {
                $fields[$keys['excerpt']] = $it['excerpt'];
            }
            if (isset($it['template'])) {
                $fields[$keys['template']] = $it['template'] === 'default' ? '' : $it['template'];
            }
            $fields = $resolve($fields);
            $body = $resolve($it['body']);
            $as(fn () => app(SaveDraft::class)->execute(new SaveDraftInput($c->id, $it['title'], $body, $fields, (string) $c->working_revision_id, $aid)), "Writing {$it['title']}");
            $c->refresh();
            $as(fn () => app(PublishRevision::class)->execute(new PublishRevisionInput($c->id, (string) $c->working_revision_id, $aid)), "Publishing {$it['title']}");
            if (isset($it['publishedAt'])) {
                $c->refresh();
                $as(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($c->id, (string) $c->working_revision_id, $aid, publishedAt: $it['publishedAt'])), "Dating {$it['title']}");
            }
            $count('published');
            $say("published $sid");
        }
    }

    // ---------------------------------------------------------------- regions
    if ($run('regions')) {
        foreach ($content['regions'] as $name => $body) {
            $slug = 'temecms--'.$name;
            $c = Content::query()->where('type', 'region')->where('slug', $slug)->first();
            if ($c === null) {
                $c = $as(fn () => app(CreateRegion::class)->execute(new CreateRegionInput('temecms', $name, $aid)), "Making the region $name");
            } elseif (! empty($c->published_revision_id) && ! $forced('region')) {
                continue;
            }
            $body = $resolve($body);
            $as(fn () => app(SaveDraft::class)->execute(new SaveDraftInput($c->id, $c->title, $body, [], (string) $c->working_revision_id, $aid)), "Writing the region $name");
            $c->refresh();
            $as(fn () => app(PublishRevision::class)->execute(new PublishRevisionInput($c->id, (string) $c->working_revision_id, $aid)), "Publishing the region $name");
            $count('regions');
            $say("region $name published");
        }
    }

    // ---------------------------------------------------------------- menus
    /*
     * A menu's items as stored, in the shape SaveNavigationItems takes, so the seed can tell whether a menu
     * already holds what it would save.
     */
    $storedTree = static function (string $navigationId): array {
        $rows = NavigationItem::query()->where('navigation_id', $navigationId)->orderBy('position')->get();
        $under = static function (?string $parent) use (&$under, $rows): array {
            $tree = [];
            foreach ($rows->where('parent_id', $parent) as $row) {
                $tree[] = ['kind' => $row->kind, 'target_id' => $row->target_id, 'url' => $row->url, 'label' => $row->label, 'children' => $under($row->id)];
            }

            return $tree;
        };

        return $under(null);
    };
    $sameTree = static function (array $wanted, array $stored) use (&$sameTree): bool {
        if (count($wanted) !== count($stored)) {
            return false;
        }
        foreach (array_values($wanted) as $i => $item) {
            $have = $stored[$i];
            if ($item['kind'] !== $have['kind'] || ($item['target_id'] ?? null) !== $have['target_id'] || ($item['url'] ?? null) !== $have['url']
                || ($item['label'] ?? null) !== $have['label'] || ! $sameTree($item['children'], $have['children'])) {
                return false;
            }
        }

        return true;
    };
    $placeMenu = static function (string $name, string $slot, array $tree) use ($as, $aid, $setting, $storedTree, $sameTree, $count, $say, $forced): void {
        $nav = Navigation::query()->where('name', $name)->first();
        if ($nav === null) {
            $nav = $as(fn () => app(CreateNavigation::class)->execute(new CreateNavigationInput($name, $aid)), "Making the menu $name");
            $count('menus');
        }
        $stored = $storedTree($nav->id);
        if (! $sameTree($tree, $stored) && ($stored === [] || $forced('menu'))) {
            $as(fn () => app(SaveNavigationItems::class)->execute(new SaveNavigationItemsInput($nav->id, $tree, $aid)), "Filling the menu $name");
            $count('menu fillings');
            $say("menu $name filled");
        }
        if ($setting('theme.temecms', 'nav_'.str_replace('-', '_', $slot)) !== $nav->id) {
            $as(fn () => app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('temecms', $slot, $nav->id, $aid)), "Placing the menu $name");
            $count('menu places');
            $say("menu $slot placed");
        }
    };
    if ($run('menus')) {
        $slotFor = ['primary' => 'TemeCMS: Header', 'footer-teme' => 'TemeCMS: Footer, Teme', 'footer-build' => 'TemeCMS: Footer, Build', 'footer-project' => 'TemeCMS: Footer, Project'];
        $page = static fn (string $key): string => $state['content']['page:'.$key] ?? throw new RuntimeException("No page $key");
        $trees = [
            'primary' => [['url', '/docs/', 'Docs'], ['url', '/plugins/', 'Plugins'], ['url', '/theme-directory/', 'Themes'], ['page', 'hosting', 'Hosting'], ['page', 'developers', 'Developers']],
            'footer-teme' => [['home', null, 'Home'], ['page', 'migration', 'Moving from WordPress'], ['page', 'hosting', 'Hosting'], ['page', 'roadmap', 'Roadmap']],
            'footer-build' => [['url', '/docs/', 'Docs'], ['url', '/plugins/', 'Plugins'], ['url', '/theme-directory/', 'Themes'], ['page', 'developers', 'Developers']],
            'footer-project' => [['url', 'https://github.com/temecms', 'GitHub'], ['url', 'https://github.com/temecms/teme/releases', 'Releases'], ['page', 'faq', 'FAQ'], ['page', 'blog', 'Blog']],
        ];
        foreach ($slotFor as $slot => $name) {
            $tree = [];
            foreach ($trees[$slot] as [$kind, $target, $label]) {
                $tree[] = match ($kind) {
                    'url' => ['kind' => 'url', 'url' => $target, 'label' => $label, 'children' => []],
                    'page' => ['kind' => 'content', 'target_id' => $page($target), 'label' => $label, 'children' => []],
                    'home' => ['kind' => 'home', 'label' => $label, 'children' => []],
                };
            }
            $placeMenu($name, $slot, $tree);
        }

        // The documentation's trees: one menu per guide, a heading per group, the guides under it (the docs-sync plugin will keep these).
        $guideNames = ['editors' => "TemeCMS: Docs, the editors' guide", 'operators' => "TemeCMS: Docs, the operators' guide", 'developers' => 'TemeCMS: Docs, for developers'];
        foreach ($guideNames as $guide => $name) {
            $groups = [];
            foreach ($content['docs'] as $d) {
                if (($d['fields']['guide'] ?? '') !== $guide) {
                    continue;
                }
                $groups[$d['fields']['group']][] = ['kind' => 'content', 'target_id' => $state['content']['doc:'.$d['key']], 'label' => $d['title'], 'children' => []];
            }
            $tree = [];
            foreach ($groups as $label => $children) {
                $tree[] = ['kind' => 'heading', 'label' => $label, 'children' => $children];
            }
            $placeMenu($name, 'docs-'.$guide, $tree);
        }
    }

    // ---------------------------------------------------------------- settings
    $updateGroup = static function (string $group, array $values) use ($as, $aid): void {
        $version = (int) (\App\Domain\Settings\Models\SettingsGroupRecord::query()->where('group_key', $group)->value('version') ?? 0);
        $as(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput($group, $values, [], [], $version, $aid)), "Saving the settings $group");
    };
    if ($run('settings')) {
        $general = ['site_title' => 'Teme', 'tagline' => 'A content management system for publications. Teme is Ijaw for create.'];
        $differs = array_filter($general, static fn (string $value, string $key): bool => $setting('general', $key) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($differs !== []) {
            $updateGroup('general', $general);
            $count('settings');
            $say('site title and tagline set');
        }
    }

    // ---------------------------------------------------------------- addresses: the theme directory is not at /themes/, where core serves theme assets from
    if ($run('addresses')) {
        if ($setting('addresses', 'pattern_theme') !== '/theme-directory/{slug}/' || $setting('addresses', 'archive_theme') !== 'theme-directory') {
            $as(fn () => app(\App\Domain\Addresses\Operations\ChangeAddresses::class)->execute(new \App\Domain\Addresses\Operations\Input\ChangeAddressesInput(['theme' => '/theme-directory/{slug}/'], [], null, null, null, null, $aid, ['theme' => 'theme-directory'])), 'Moving the theme directory');
            $count('addresses');
            $say('the theme directory is at /theme-directory/');
        }
    }

    // ---------------------------------------------------------------- activate (last, and only when the site is ours to switch)
    if ($run('activate')) {
        $page = static fn (string $key): string => $state['content']['page:'.$key] ?? throw new RuntimeException("No page $key");
        if ($setting('addresses', 'front_shows') !== 'page' || $setting('addresses', 'front_page_id') !== $page('home') || $setting('addresses', 'posts_page_id') !== $page('blog')) {
            $as(fn () => app(\App\Domain\Addresses\Operations\ChangeAddresses::class)->execute(new \App\Domain\Addresses\Operations\Input\ChangeAddressesInput([], [], null, 'page', $page('home'), $page('blog'), $aid)), 'Choosing the home page');
            $count('front page');
            $say('the home page is the front page, the blog page the posts page');
        }
    }

        return $made;
    }

    /**
     * The seed's steps, in the order they run.
     *
     * @return list<string>
     */
    function temecms_site_seed_steps(): array
    {
        return ['media', 'theme', 'content', 'regions', 'menus', 'settings', 'addresses', 'activate'];
    }
}
