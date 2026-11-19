# Changelog

## 1.1.0

The free directory's content on temecms.com.

- Plugin and theme listings gain the directory's fields: summary, icon picture, categories, picked by the Teme team, author and their GitHub account, latest version, works with, last updated, listed, source, issues, documentation and support links, GitHub stars and open issues, releases, permissions, screenshots and changelog. Releases, permissions and screenshots are JSON lists in long-text fields. Both types keep their keys, addresses and existing fields. The theme listing gains a package field.
- A new taxonomy, Directory categories (`directory_category`, hierarchical), on plugin and theme listings; a listing's first category is its main one.
- A new content type, Showcase (`showcase`, `/showcase/{slug}/`): the site's name as its title, its address, kind (news, blog, documentation, magazine or other), screenshot, the theme listing and up to eight plugin listings it runs, its owner's line, and whether it is approved.
- The Directory sync job, every hour: one published listing per package in the directory's verified index, found again by its package name; the README converted from Markdown (safe mode, raw HTML dropped) into an editor document; the changelog kept; the icon and screenshots copied into Media; nothing changed when the package hasn't changed. A listing whose package left the index goes back to a draft, never deleted. Without a verified index nothing changes.
- The Submit form for a plugin or theme posts to `/temecms-site/submit-package`. With the GitHub token saved in the plugin's settings it opens a pull request on `temecms/directory` adding the listing file; without one it stores the package as a submission for a person to add by hand.
- The Showcase form posts to `/temecms-site/submit-site` and stores the site as an unapproved Showcase site waiting for review, with its screenshot's address for the reviewer.
- Contact addresses are seen only by the person reviewing a submission, never published. A hidden field catches robots, and one address may post to each form five times a minute.
- Needs a Teme that offers the directory's doors: the verified index, unpublishing, and visitors' submissions.

## 1.0.0

The site plugin of temecms.com, taken out of the TemeCMS theme (0.1.x), which is only the looks from 0.2.0.

- The content types Documentation (`doc`, `/docs/{slug}/`), Plugin directory (`plugin`, `/plugins/{slug}/`) and Theme directory (`theme`, `/theme-directory/{slug}/`), with their fields and the page fields Kicker and Words to set in italics in the title, declared in `types/teme-types.json`. Their keys, admin addresses and item addresses are the ones the theme's seed made, so enabling the plugin on temecms.com takes over its types with their content.
- The types Plugins and Themes are now called Plugin directory (a Plugin listing) and Theme directory (a Theme listing), so the admin's menu no longer shows Plugins and Themes twice.
- The seed, `seed/seed.php`, loads the starting content: pictures, pages, posts, guides, plugin and theme listings, regions, menus, settings, the theme directory's address and the front page. The types and fields are no longer seed steps: the plugin brings them. Its state file is `storage/app/temecms-site-seed.json`; the theme's old one is read when it is missing.
- The seed leaves a site's own content alone: published items and regions, and menus that hold items, are written over only when their kind is named in `SEED_FORCE` (`menu` is new).
- The seeded words say what is true of Teme 1.x: nothing calls Teme an alpha. Where a page names the current release, the seed writes the release the site runs, and the history points to Releases on GitHub.
