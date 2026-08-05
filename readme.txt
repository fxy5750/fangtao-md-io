=== Fangtao Markdown Import & Export ===
Contributors: fangtao
Tags: markdown, import, export, migration, media
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Markdown and ZIP archives with local images, then export WordPress content as portable Markdown ZIP packages.

== Description ==

Fangtao Markdown Import & Export provides a focused workflow for moving content between Markdown files and WordPress.

= Import =

* Import standalone `.md` and `.markdown` files.
* Import ZIP archives containing multiple Markdown documents.
* Import JPG, JPEG, PNG, GIF, WebP, and AVIF images referenced with relative paths.
* Add imported local images to the WordPress Media Library.
* Select the destination post type and post status.
* Assign imported standard posts to an optional category.
* Configure the default post status used by the import form.
* Optionally import remote HTTP(S) images through WordPress safe HTTP handling.
* Import Front Matter titles, slugs, permalinks, excerpts, dates, statuses, categories, tags, and featured images.

= Export =

* Export an individual post from its row action.
* Export selected posts with WordPress bulk actions.
* Export all matching content by post type, category, and tag.
* Select `.md` or `.markdown` as the exported document extension.
* Convert common WordPress HTML and block content to GitHub Flavored Markdown.
* Package local Media Library images inside an `images` directory.
* Use relative image references in exported Markdown.
* Include title, slug, permalink, excerpt, date, post type, status, categories, tags, and featured image Front Matter.

= ZIP Structure =

Single export:

`
article.md
images/
  article-image.jpg
`

Bulk exports place every item in a separate directory with the same internal structure.

For complete English documentation, see `README.md`.
For Simplified Chinese documentation, see `README.zh-CN.md`.

== Installation ==

1. Upload the `fangtao-markdown-zip-importer` directory to `/wp-content/plugins/`, or install the plugin ZIP from the WordPress Dashboard.
2. Activate the plugin through the **Plugins** screen.
3. Open the **Markdown** menu in the WordPress Dashboard.
4. Use **Markdown Import** or **Markdown Export**.

== Frequently Asked Questions ==

= What are the server requirements? =

The plugin requires WordPress 6.0 or later and PHP 7.4 or later. ZIP import and export fall back to the PclZip library bundled with WordPress when the PHP ZIP extension is unavailable. The PclZip fallback requires PHP zlib. Markdown conversion uses a bundled compatibility layer when native PHP mbstring is unavailable. HTML-to-Markdown export requires the PHP DOM extension.

= How should I package local images? =

Place Markdown and image files in the same ZIP while preserving their relative paths:

`
articles/
  living-room.md
  images/
    living-room.jpg
`

Reference the image in Markdown as:

`
![Living room](images/living-room.jpg)
`

= Which Front Matter fields are supported during import? =

The importer supports single-line `title`, `slug`, `permalink`, `excerpt`, `date`, `status`, `categories`, `tags`, `featured_image`, and `featured_image_id` values. `description`, `category`, `tag`, `cover`, and `image` are accepted aliases. Complex or nested YAML values are not supported.

= Are remote images downloaded? =

Not by default. Administrators can enable remote image importing under **Import Settings**. Downloads use WordPress safe HTTP handling and the Media Library pipeline.

= Why was an imported image stored locally instead of OSS? =

When a detected OSS integration is enabled but has incomplete bucket and credential or role settings, the importer uses normal WordPress local media storage to prevent fatal upload failures. Complete the OSS configuration to restore its normal processing.

= Does import overwrite existing posts? =

No. Every Markdown document creates a new WordPress content item.

== Changelog ==

= 1.4.0 =

* Added extended Front Matter import and export metadata.
* Added optional safe remote image importing.
* Added filtered mass export and selectable Markdown file extensions.

= 1.3.0 =

* Added an optional category selector when importing standard posts.
* Added a persistent Draft or Publish immediately default for the import form.

= 1.2.0 =

* Added a WordPress PclZip fallback for individual and bulk Markdown ZIP exports.
* Preserved the existing `article.md` and relative `images/` package structure without requiring PHP ZIP.

= 1.1.3 =

* Added a bundled mbstring compatibility layer for Markdown conversion on servers without the PHP mbstring extension.

= 1.1.2 =

* Added a WordPress PclZip fallback for ZIP imports when the PHP ZIP extension is unavailable.
* Kept archive path, entry count, and extracted-size checks on the fallback path.

= 1.1.1 =

* Prevented incomplete OSS configuration from causing fatal errors during image import.
* Added readable handling for media import exceptions.

= 1.1.0 =

* Added individual and bulk Markdown ZIP export.
* Added HTML-to-Markdown conversion and relative image packaging.
* Added exported Front Matter data.

= 1.0.0 =

* Initial Markdown and ZIP import release.
* Added local image import and featured image handling.
