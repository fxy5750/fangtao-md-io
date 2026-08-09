=== Fangtao MD IO ===
Contributors: fangtao
Tags: markdown, import, export, migration, media
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.9.10
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Markdown and ZIP archives with local media assets, then export WordPress content as portable Markdown ZIP packages.

== Description ==

Fangtao MD IO provides a focused workflow for moving content between Markdown files and WordPress.

= Import =

* Supported Markdown file extensions (case-insensitive): .md, .markdown, .mdown, .mkdn, .mkd, .mdwn, .mdtxt, .mdtext, .文本, and .txt.
* Select multiple Markdown files and ZIP archives in one import batch.
* Queue multiple selected files in the browser and import them one at a time, with per-file status feedback.
* ZIP archives without a supported Markdown document are skipped without importing their assets.
* Select safe image, video, audio, and PDF extensions allowed inside ZIP imports.
* Add referenced local assets to the WordPress Media Library.
* Configure ZIP, extracted-content, Markdown, asset, and entry-count limits, following PHP limits by default.
* Use each Markdown file's ZIP modification time by default, or set an exact manual publication date and time.
* When importing multiple ZIP files without a manual publication date, preserve each Markdown document's own ZIP modification time.
* Select the destination post type, post status, private visibility, or an optional password.
* Assign imported standard posts to an optional category.
* Configure the default post status used by the import form.
* Choose from five Markdown parsers covering Traditional, GitHub, and Extra syntax flavors.
* Configure the default parser while retaining a per-import override.
* Optionally import remote HTTP(S) images through WordPress safe HTTP handling.
* Import Front Matter titles, slugs, permalinks, excerpts, dates, statuses, passwords, categories, tags, and featured images.

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

For complete English documentation, see `docs/README.md`.
For Simplified Chinese documentation, see `docs/README.zh-CN.md`.

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

The importer supports single-line `title`, `slug`, `permalink`, `excerpt`, `date`, `status`, `password`, `categories`, `tags`, `featured_image`, and `featured_image_id` values. `description`, `category`, `tag`, `cover`, and `image` are accepted aliases. Complex or nested YAML values are not supported.

Private posts and password-protected posts are separate WordPress visibility modes and cannot be combined.

= Are remote images downloaded? =

Not by default. Administrators can enable remote image importing under **Import Settings**. Downloads use WordPress safe HTTP handling and the Media Library pipeline.

= Why was an imported image stored locally instead of OSS? =

When a detected OSS integration is enabled but has incomplete bucket and credential or role settings, the importer uses normal WordPress local media storage to prevent fatal upload failures. Complete the OSS configuration to restore its normal processing.

= Does import overwrite existing posts? =

No. Every Markdown document creates a new WordPress content item.

== Changelog ==

= 1.9.10 =

* Added persistent import statistics and recent import logs.
* Added a confirmed Advanced action that clears only import statistics and logs.

= 1.9.9 =

* Added a clear selected files button to the Markdown and ZIP upload field.

= 1.9.8 =

* Refined the import donut chart with percentage callouts, a horizontal legend, and segment highlighting on hover or keyboard focus.

= 1.9.7 =

* Replaced the import statistics bar with a live color-coded donut chart and processed total.

= 1.9.6 =

* Restored visible dropdown arrows for plugin select fields in light and dark admin themes.

= 1.9.5 =

* Refined the Markdown import and export interfaces with a shared full-width workspace, clearer form sections, improved controls, and responsive export cards.

= 1.9.4 =

* Reworked the batch import sidebar with a visible import statistics dashboard, color-coded result bar, per-file task progress, and reset control.

= 1.9.3 =

* Kept the import queue visible, expanded the desktop layout, and protected the import form from being compressed on narrower admin screens.

= 1.9.2 =

* Added a sequential browser upload queue for multiple Markdown and ZIP files, with per-file progress, success, skip, and failure feedback.

= 1.9.1 =

* Fixed imported Media Library images being left with an empty attachment title and appearing as unfinished uploads.
* Use each Markdown file's ZIP modification time when no manual publication date is set.

= 1.9.0 =

* Added optional import date and time controls with second-level precision.
* Added private visibility and password-protected post controls.
* Added Front Matter password support.

= 1.8.1 =

* Fixed the Markdown admin menu icon alignment and refreshed its stylesheet version.

= 1.8.0 =

* Added WordPress locale-aware English translations and a translation template.
* Fixed Markdown menu icon centering in the WordPress admin sidebar.
* Validate imported image metadata before completion to avoid unfinished Media Library items.
* Moved GitHub documentation to the `docs/` directory and excluded `.gitattributes` from archive exports.

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
