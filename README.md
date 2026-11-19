# TemeCMS site: the plugin of temecms.com

The site plugin of [temecms.com](https://temecms.com), the TemeCMS project's own website. It holds what the site is, apart from how it looks:

- the content types **Documentation** (singular Guide), **Plugin directory** (singular Plugin listing), **Theme directory** (singular Theme listing) and **Showcase** (singular Showcase site), with their fields, the taxonomy **Directory categories**, and the page fields Kicker and Words to set in italics in the title;
- the directory's **Submit forms**, for plugins and themes and for the Showcase, and the **directory sync** that fills the listings from the directory's index;
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
| Showcase (Showcase site) | `showcase` | `/showcase/{slug}/` | `/admin/showcase-pages` |

Plugin and theme listings carry the directory's facts as fields: `package` (the Composer name, which the sync finds a listing by), `summary`, `icon_image`, `categories`, `picked`, `author_name`, `author_github`, `latest_version`, `works_with`, `updated_on`, `listed_on`, `link_source`, `link_issues`, `link_docs`, `link_support`, `stars`, `open_issues` and `changelog` (Markdown). `releases`, `capabilities` and `screenshots` hold JSON lists: `[{version, date, works_with, held}]` newest first, `[{key, words}]` and `[{media, caption}]`. `directory_sync` is the sync's own record; leave it as it is. The README is the listing's body. Both types take terms from **Directory categories** (`directory_category`), the first one the listing's main category.

A Showcase site's title is the site's name; its fields are `site_address`, `site_kind` (news, blog, documentation, magazine or other), `screenshot`, `theme_item` and `plugin_item_1` to `plugin_item_8` (links to the listings it runs), `owner_line` and `approved`. Publish only approved sites.

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

## The directory

### The Submit form for plugins and themes

The theme's Submit page posts its form to `/temecms-site/submit-package`, form-encoded, with the fields `repository` (a `https://github.com/owner/repo` address), `kind` (`plugin` or `theme`), `category` (a category key), `contact` (an email address) and `agree`, and a field `website` that people never see and must stay empty.

- **With a GitHub token saved**, the plugin reads the package's name from the repository's `composer.json` and opens a pull request on [`temecms/directory`](https://github.com/temecms/directory) adding `listings/<vendor>/<name>.json`.
- **Without one**, it stores the package as a submission waiting for review: a plugin or theme listing named after the repository, with its source link and category, for a person to add to the directory by hand.

The contact address goes into no pull request, no field and nothing public; only the person reviewing a stored submission sees it.

It answers with a redirect back to the page the form was on (`/submit/` when the browser doesn't say), saying what happened in the query: `?submitted=pull-request&number=N`, `?submitted=by-hand`, or `?error=` and one of `repository`, `kind`, `category`, `contact`, `agree`, `listed` (already in the directory), `github` (GitHub refused) or `unavailable` (the submission couldn't be stored). One address may post five times a minute.

To let it open pull requests, save a fine-grained GitHub token, allowed to write contents and pull requests on `temecms/directory` only, under Site › Plugins › TemeCMS site › **GitHub token for the Submit form**.

### The Showcase form

The Showcase page posts its form to `/temecms-site/submit-site`, form-encoded, with `name`, `address` (the site's https or http address), `kind` (`news`, `blog`, `documentation`, `magazine` or `other`), `screenshot` (an optional address of a picture), `theme`, `plugins`, `line` (a short line from the site's owner), `contact` (an email address) and the empty `website`. The site is stored as a Showcase site waiting for review, with Approved unticked. The screenshot's address and the theme and plugins as the visitor named them go in its body for the person who reviews it, who sets the picture, links the listings, ticks Approved and publishes it. The contact address is seen only by that person.

It answers like the plugin form: `?submitted=showcase`, or `?error=` and one of `address`, `kind`, `screenshot`, `contact` or `unavailable`. One address may post five times a minute.

### The directory sync

The job **Directory sync** runs every hour. It reads the directory's index as Teme last verified it, and each package's detail file through the same check, and makes the listings match:

- one published listing per package, found again by its package name;
- the README converted from Markdown (safe mode: raw HTML dropped, unsafe links emptied) into an editor document by Teme's own converter; the changelog kept;
- the icon and screenshots copied into Media, each picture once;
- nothing written when a package hasn't changed;
- a listing whose package has left the index taken back to a draft, never deleted; it is published again if the package comes back.

When Teme has no verified index (the directory is switched off, its key is missing, or the index has expired), the run fails and changes nothing. A package whose detail file can't be read this time keeps its listing as it is. Listings made by hand, without the sync's record, are left alone. The run's message under Tools › Jobs says what it did.

To run it once by hand, for example right after enabling the plugin, queue the due schedules from the Teme install's folder:

```bash
php artisan teme:plugins:run-due-schedules --plugin=temecms-site
```

A schedule that has never run is due at once; after that, the command queues it again only once the hour has passed. The queue worker then runs it.

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
| `words` | Brings an older seed's out-of-date passages up to date on a site that already holds them: see [Moving an existing site](#moving-an-existing-site). Never part of a first load |

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

4. **Bring the old words up to date.** The seed has changed since the pages were written: they still call Teme an alpha, say Version 1.0 and installing from the admin are still to come The `words` step rewrites those passages, and nothing else, through Teme's Operations. From the web container, as in the steps above:

   ```bash
   cd /var/www/html
   SEED_ADMIN_ID=1 php /var/teme/extensions/current/vendor/temecms/temecms-site/seed/seed.php words
   ```

   It changes a passage only where the item still holds the older seed's text exactly. A passage someone has edited, and an item with a draft waiting, are left as they are and named, so a person's edit is never written over. It lists each item it changed and each passage it left, and ends with what it changed, such as "Made or changed: words 14." Run again, it changes nothing: a passage already as it is now is passed over, and it ends with "Nothing to change".

   Check: `/faq/` answers "Can I use it now?" with Yes, `/roadmap/` lists Teme 1.2 as built and the directory as next, and `/moving-from-wordpress/` no longer says alpha.

Don't run any other step on this site. If you ever do, name only the steps you need: published items, regions and menus are left alone unless named in `SEED_FORCE`.

## Working on the plugin

The content is Tiptap JSON (never HTML), written in `seed/content/*.mjs` with the helpers in `seed/lib.mjs`, and compiled to `seed/content.json`, which is committed. After editing `seed/content/*.mjs`, with Node.js 20.11 or later:

```bash
node seed/build-content.mjs        # seed/content/*.mjs -> seed/content.json
```

Where a sentence should name the Teme release the site runs, write `{{release}}`; the seed writes the running release in its place.

When you rewrite a passage that a live site already holds, the `words` step must carry the rewrite to it: `seed/words.json` lists each passage as the older seed wrote it beside what the seed writes now, and it is made, after `build-content.mjs`, from the older seed's `content.json`:

```bash
node seed/build-words.mjs path/to/older/content.json   # -> seed/words.json
```

The seed's steps are one function, `temecms_site_seed()`, in `seed/steps.php`; `seed/seed.php` boots Teme and calls it. It runs on Teme's production image, which is installed without Composer's dev packages, so it must not use Teme's testing kit (`Teme\Testing`) or anything from PHPUnit. It acts as the administrator `SEED_ADMIN_ID` the way the admin's own requests do: that user signed in, with core's `SessionElevation` over a request whose session holds a password confirmed now. Every step must be repeatable, and a second run must change nothing: look for what exists (by slug, by title, by the state file, or by the setting's current value) before writing.

The plugin's own code, in `src/`, uses only `teme/contracts`. The seed lives outside it, in `seed/`, because it calls Teme's Operations directly, as an operator's script.

### Tests

The tests use Teme's testing kit, which a development install has. From the Teme install's folder:

```bash
TEMECMS_THEME=/path/to/temecms php artisan test /path/to/temecms-site/tests
```

They check the manifest and the types file against Teme's own rules, run the directory sync and the Submit forms on a test site (they need a Teme that offers the directory's doors, and are skipped without it), and run the seed's steps on a test site: what each step makes, that a second run changes nothing, and that it leaves a site's own content alone. `TEMECMS_THEME` names a copy of the TemeCMS theme, in a folder named `temecms`; without it the tests that need the theme are skipped.

## Licence

MIT, copyright Krafty Sprouts Media, LLC. See [LICENSE](LICENSE).
