# TemeCMS site: the plugin of temecms.com

The site plugin of [temecms.com](https://temecms.com), the TemeCMS project's own website. It holds what the site is, apart from how it looks:

- the content types **Documentation** (singular Guide), **Plugin directory** (singular Plugin listing) and **Theme directory** (singular Theme listing), with their fields, and the page fields Kicker and Words to set in italics in the title;
- the site's starting content: pages, posts, guides, plugin and theme listings, product pictures, the theme's regions, menus and settings, and the script that loads it.

The looks are the TemeCMS theme, [`temecms/theme-temecms`](https://github.com/temecms/theme-temecms). Keeping the two apart means switching themes never makes content disappear.

- Package: `temecms/temecms-site`
- Plugin code: `temecms-site`
- Licence: MIT

## Requirements

- Teme `^1.2`: the plugin declares its types in `teme-plugin.json`, under `contributes.types`, which Teme reads from 1.2.0.
- The TemeCMS theme `0.2.0` or later, to draw the site.

## What it adds

`types/teme-types.json` holds the types, in the same format Settings › Types exports:

| Type | Key | Address of an item | Admin |
|---|---|---|---|
| Documentation (Guide) | `doc` | `/docs/{slug}/` | `/admin/doc-pages` |
| Plugin directory (Plugin listing) | `plugin` | `/plugins/{slug}/` | `/admin/plugin-pages` |
| Theme directory (Theme listing) | `theme` | `/theme-directory/{slug}/` | `/admin/theme-pages` |

While the plugin is enabled, these types, their fields and taxonomies work like any other and are marked as the plugin's. A type the site already has with the same key is taken over when the plugin is enabled, with all its content. Disabled, its types leave the menu and the public site, and their items are kept. Teme refuses to uninstall it while those types hold items.

## Install

From the admin: under Site › Plugins, add a plugin from a Git address and give it

```
https://github.com/temecms/temecms-site
```

Or with Composer, in the Teme install's folder (once the package is on Packagist):

```bash
composer require temecms/temecms-site
```

Then enable it under Site › Plugins, or from the console:

```bash
php artisan teme:plugins:enable temecms/temecms-site --agree
```

To work on a local copy instead, add the plugin's folder, as an absolute path, to `TEME_PLUGIN_PATHS` in the Teme install's `.env`.

## Load the starting content

Only on a new site meant for this content. A site that already has it (temecms.com) skips this: see [Moving an existing site](#moving-an-existing-site).

A plugin can't add an `artisan` command, so the content is loaded by a script inside the plugin, `seed/seed.php`, that boots Teme as `artisan` does. It makes everything through Teme's own Operations, as an administrator would from the admin, and needs nothing that isn't in Teme's production image. It is a script for the server's operator: it is not part of the plugin's code, and it doesn't run when the plugin is enabled.

Before you start:

- the plugin is installed and enabled, so the types and fields exist;
- the TemeCMS theme is installed;
- an administrator exists. If none does yet, make one with `php artisan teme:user:create --role=administrator --name="Your Name" --email=you@example.com` and open the link it prints. You need that administrator's user ID: the first user on a new site is `1`.

Then, on Teme's Docker image (as with the recipe on Dokploy):

1. Open a terminal in the **web** container (in Dokploy: the `web` service's Docker terminal; or `docker compose exec web sh` next to the compose file). The image's `php` runs as the site's own user, so the pictures the seed uploads stay writable by the site.
2. Go to Teme's folder:

   ```bash
   cd /var/www/html
   ```

3. Run the seed. A plugin installed from the admin lives in the extensions folder, under `current/vendor/temecms/temecms-site` (installed another way, use the plugin's own folder). Set `SEED_ADMIN_ID` to your administrator's ID:

   ```bash
   SEED_ADMIN_ID=1 php /var/teme/extensions/current/vendor/temecms/temecms-site/seed/seed.php media theme content regions menus settings addresses activate
   ```

The steps run in this order, whatever order you name them in:

| Step | Does |
|---|---|
| `media` | Uploads the product pictures in `seed/media` with their alt text |
| `theme` | Makes `temecms` the site's theme (before `content`: a page picks its template from the active theme) |
| `content` | Makes and publishes every page, post, guide, plugin and theme listing, with links between them stored as IDs, and the running Teme release where a page names it |
| `regions` | Fills the theme's regions |
| `menus` | Makes the menus and puts them in the theme's slots |
| `settings` | Sets the site's title and tagline |
| `addresses` | Puts the theme directory's archive at `/theme-directory/` (core serves theme assets from `/themes/`) |
| `activate` | Makes the home page the front page and the blog page the posts page |

`theme`, `settings`, `addresses` and `activate` change the site's theme, title, addresses and front page, so run them only on a site meant for this content. Leave a step out to skip it. `content`, `regions` and `menus` stop at once, changing nothing, when the plugin's types and fields are missing.

It is safe to run again, and it leaves what a site already has alone. What the seed made before is found and reused, never duplicated, and a second run changes nothing: it ends with "Nothing to change". It remembers what it made in `storage/app/temecms-site-seed.json` (set `SEED_STATE` to keep that file elsewhere); without that file it reads the one the theme's old seed kept, `storage/app/theme-temecms-seed.json`, and otherwise finds its pictures and items by name. Published items and regions, and menus that hold items, are left as they are, even if you have edited them since. To write the seed's version over them again, name their kind in `SEED_FORCE`, as in `SEED_FORCE=page,doc` (`region` for the regions, `menu` for the menus).

If Teme refuses a step, the seed prints the step and the reason, such as `Refused: Publishing Hosting. …`, stops, and exits with a non-zero status. Fix the cause and run the same command again: it carries on where it stopped.

## Moving an existing site

temecms.com already has the types `doc`, `plugin` and `theme`, made by the TemeCMS theme's old seed (0.1.x), and all its content. Moving it to the plugin loads nothing again. Back the site up first, then:

1. **Update Teme to 1.2.0.** On Dokploy, from Tools › Updates; elsewhere, update the image and deploy.

   Check: Tools › Updates (or the site's status) names 1.2.0, and the public site and the admin answer as before. The menu still shows Documentation, Plugins and Themes; Plugins and Themes still appear twice, which the next step ends.

2. **Install and enable temecms-site.** Under Site › Plugins, add it from its Git address, wait for the installer to finish, then enable it.

   Check: the plugin is listed as enabled, with no reason given for not starting. Settings › Types shows Documentation, Plugin directory and Theme directory, marked as the plugin's. The admin's menu shows Plugin directory and Theme directory, and Plugins and Themes once each, as Teme's own screens. Open a guide, a plugin listing and a theme listing in the admin: their fields still hold their values. On the public site, `/docs/`, `/plugins/`, `/theme-directory/` and an item under each answer at the same addresses as before.

3. **Update the theme to 0.2.0.** Under Site › Themes, update the TemeCMS theme from its Git address.

   Check: the theme names 0.2.0 and is still the active theme. The documentation pages show a version label: the running release (1.2.0) when the theme's Documentation version label setting is empty, or what that setting says. If you set it to 0.7 once, clear it under Site › Themes. The home page, a blog post and the plugin and theme directories look as they did.

Don't run the seed on this site. If you ever do, name only the steps you need: published items, regions and menus are left alone unless named in `SEED_FORCE`.

## Working on the plugin

The content is Tiptap JSON (never HTML), written in `seed/content/*.mjs` with the helpers in `seed/lib.mjs`, and compiled to `seed/content.json`, which is committed. After editing `seed/content/*.mjs`, with Node.js 20.11 or later:

```bash
node seed/build-content.mjs        # seed/content/*.mjs -> seed/content.json
```

Where a sentence should name the Teme release the site runs, write `{{release}}`; the seed writes the running release in its place.

The seed's steps are one function, `temecms_site_seed()`, in `seed/steps.php`; `seed/seed.php` boots Teme and calls it. It runs on Teme's production image, which is installed without Composer's dev packages, so it must not use Teme's testing kit (`Teme\Testing`) or anything from PHPUnit. It acts as the administrator `SEED_ADMIN_ID` the way the admin's own requests do: that user signed in, with core's `SessionElevation` over a request whose session holds a password confirmed now. Every step must be repeatable, and a second run must change nothing: look for what exists (by slug, by title, by the state file, or by the setting's current value) before writing.

The plugin's own code, in `src/`, uses only `teme/contracts`. The seed lives outside it, in `seed/`, because it calls Teme's Operations directly, as an operator's script.

### Tests

The tests use Teme's testing kit, which a development install has. From the Teme install's folder:

```bash
TEMECMS_THEME=/path/to/temecms php artisan test /path/to/temecms-site/tests
```

They check the manifest and the types file against Teme's own rules, and run the seed's steps on a test site: what each step makes, that a second run changes nothing, and that it leaves a site's own content alone. `TEMECMS_THEME` names a copy of the TemeCMS theme, in a folder named `temecms`; without it the tests that need the theme are skipped.

## Licence

MIT, copyright Krafty Sprouts Media, LLC. See [LICENSE](LICENSE).
