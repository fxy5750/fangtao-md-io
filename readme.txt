=== Fangtao MD IO ===
Contributors: fangtao
Tags: markdown, import, export, migration, media
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Markdown and ZIP archives with local media assets, then export WordPress content as portable Markdown ZIP packages.

== Description ==

Fangtao MD IO provides a focused workflow for moving content between Markdown files and WordPress.

= Import =

* Supported Markdown file extensions (case-insensitive): .md, .markdown, .mdown, .mkdn, .mkd, .mdwn, .mdtxt, .mdtext, .文本, and .txt.
* Import ZIP archives containing multiple Markdown documents.
* Select safe image, video, audio, and PDF extensions allowed inside ZIP imports.
* Add referenced local assets to the WordPress Media Library.
* Configure ZIP, extracted-content, Markdown, asset, and entry-count limits, following PHP limits by default.
* Select the destination post type and post status.
* Assign imported standard posts to an optional category.
* Configure the default post status used by the import form.
* Choose from five Markdown parsers covering Traditional, GitHub, and Extra syntax flavors.
* Configure the default parser while retaining a per-import override.
* Optionally import remote HTTP(S) images through WordPress safe HTTP handling.
* Import Front Matter titles, slugs, permalinks, excerpts, dates, statuses, categories, tags, and featured images.

= Export =

* Export an individual post from its row action.
* Export selected posts with WordPress bulk actions.
* Export all matching content by post type, category, and tag.
* Select any supported Markdown text extension for exported documents.
* Convert common WordPress HTML and block content to GitHub Flavored Markdown.
* Package local Media Library images inside `images` and linked media assets inside `media`.
* Use relative asset references in exported Markdown.
* Include title, slug, permalink, excerpt, date, post type, status, categories, tags, and featured image Front Matter.

= ZIP Structure =

Single export:

`
article.md
images/
  article-image.jpg
media/
  room-tour.mp4
  catalog.pdf
`

Bulk exports place every item in a separate directory with the same internal structure.

For complete English documentation, see `README.md`.
For Simplified Chinese documentation, see `README.zh-CN.md`.

Parsedown, Parsedown Extra, and cebe/markdown are bundled under the MIT License.

== Installation ==

1. Upload the `fangtao-md-io` directory to `/wp-content/plugins/`, or install the plugin ZIP from the WordPress Dashboard.
2. Activate the plugin through the **Plugins** screen.
3. Open the **Markdown** menu in the WordPress Dashboard.
4. Use **Markdown Import** or **Markdown Export**.

== Frequently Asked Questions ==

= What are the server requirements? =

The plugin requires WordPress 6.0 or later and PHP 7.4 or later. ZIP import and export fall back to the PclZip library bundled with WordPress when the PHP ZIP extension is unavailable. The PclZip fallback requires PHP zlib. Markdown conversion uses a bundled compatibility layer when native PHP mbstring is unavailable. HTML-to-Markdown export requires the PHP DOM extension.

= How should I package local assets? =

Place Markdown and asset files in the same ZIP while preserving their relative paths:

`
articles/
  living-room.md
  images/
    living-room.jpg
  media/
    room-tour.mp4
    catalog.pdf
`

Reference the assets in Markdown as:

`
![Living room](images/living-room.jpg)
[video src="media/room-tour.mp4"]
[Download catalog](media/catalog.pdf)
`

Only referenced assets are imported. Administrators can choose the allowed safe image, video, audio, and PDF extensions under **Import Settings**.

= Which Front Matter fields are supported during import? =

The importer supports single-line `title`, `slug`, `permalink`, `excerpt`, `date`, `status`, `categories`, `tags`, `featured_image`, and `featured_image_id` values. `description`, `category`, `tag`, `cover`, and `image` are accepted aliases. Complex or nested YAML values are not supported.

= Are remote images downloaded? =

Not by default. Administrators can enable remote image importing under **Import Settings**. Downloads use WordPress safe HTTP handling and the Media Library pipeline.

= Why was an imported image stored locally instead of OSS? =

When a detected OSS integration is enabled but has incomplete bucket and credential or role settings, the importer uses normal WordPress local media storage to prevent fatal upload failures. Complete the OSS configuration to restore its normal processing.

= Does import overwrite existing posts? =

No. Every Markdown document creates a new WordPress content item.

== Changelog ==

= 1.7.0 =

* Added configurable safe ZIP asset formats for images, video, audio, and PDF files.
* Added local linked-asset and WordPress video/audio shortcode imports through the Media Library.
* Added configurable import limits that follow PHP/WordPress upload limits by default.
* Added relative `media/` packaging for supported local assets during export.

= 1.6.1 =

* Hardened ZIP extraction, upload and download size enforcement, import capabilities, and local image export path validation.
* Updated Parsedown to 1.8.0 to address a reported regular-expression denial-of-service issue.

= 1.6.0 =

* Renamed the plugin, directory, main plugin file, admin page slugs, text domain, and Composer package to Fangtao MD IO (`fangtao-md-io`).
* Preserved existing `ftmzi_*` settings and internal identifiers for upgrade compatibility.

= 1.5.1 =

* Added case-insensitive support for additional Markdown text file extensions in direct uploads, ZIP imports, and exports.

= 1.5.0 =

* Added five selectable Markdown parsers: Parsedown, Parsedown Extra, Cebe Markdown, Cebe Markdown GitHub, and Cebe Markdown Extra.
* Added Traditional, GitHub, and Extra syntax flavor labels.
* Added a persistent default parser and a per-import parser override.
* Bundled the parser dependencies for portable installation.

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
