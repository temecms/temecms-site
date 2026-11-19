# Changelog

## 1.0.0

The site plugin of temecms.com, taken out of the TemeCMS theme (0.1.x), which is only the looks from 0.2.0.

- The content types Documentation (`doc`, `/docs/{slug}/`), Plugin directory (`plugin`, `/plugins/{slug}/`) and Theme directory (`theme`, `/theme-directory/{slug}/`), with their fields and the page fields Kicker and Words to set in italics in the title, declared in `types/teme-types.json`. Their keys, admin addresses and item addresses are the ones the theme's seed made, so enabling the plugin on temecms.com takes over its types with their content.
- The types Plugins and Themes are now called Plugin directory (a Plugin listing) and Theme directory (a Theme listing), so the admin's menu no longer shows Plugins and Themes twice.
- The seed, `seed/seed.php`, loads the starting content: pictures, pages, posts, guides, plugin and theme listings, regions, menus, settings, the theme directory's address and the front page. The types and fields are no longer seed steps: the plugin brings them. Its state file is `storage/app/temecms-site-seed.json`; the theme's old one is read when it is missing.
- The seed leaves a site's own content alone: published items and regions, and menus that hold items, are written over only when their kind is named in `SEED_FORCE` (`menu` is new).
- The seeded words say what is true of Teme 1.x: nothing calls Teme an alpha. Where a page names the current release, the seed writes the release the site runs, and the history points to Releases on GitHub.
