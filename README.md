![Fangtao MD IO banner](fangtao-md-io_banner_1544x500.jpg)

# Fangtao MD IO
English | [简体中文](docs/README.zh-CN.md)

Fangtao MD IO is a WordPress plugin for moving content between Markdown files and WordPress. It can import a standalone Markdown document or a ZIP archive containing multiple Markdown files and local media assets. It can also export individual or multiple WordPress posts as portable Markdown ZIP packages.

## Features

- Supported Markdown file extensions (case-insensitive): .md, .markdown, .mdown, .mkdn, .mkd, .mdwn, .mdtxt, .mdtext, .文本, and .txt.
- Import ZIP archives containing multiple Markdown documents.
- Queue multiple selected files in the browser and import them one at a time to avoid a single oversized upload request.
- Select which safe image, video, audio, and PDF extensions may be imported from ZIP packages.
- Upload referenced local assets to the WordPress Media Library and replace their relative paths with attachment URLs.
- Use the first imported local image as the featured image when no featured image is specified.
- Choose the destination post type, post status, precise post date and time, private visibility or password protection, and a category for standard posts.
- Configure the default post status used by the import form.
- Choose one of five Markdown parsers covering Traditional, GitHub, and Extra syntax flavors.
- Configure the default parser while retaining a per-import override.
- Optionally download remote HTTP(S) images into the WordPress Media Library.
- Follow the effective PHP upload limit by default or configure separate ZIP, extracted-content, Markdown, asset, and entry-count limits.
- Import Front Matter dates, statuses, passwords, permalinks, categories, tags, and Media Library featured image IDs.
- Export a single post from its row action.
- Export multiple posts from WordPress bulk actions.
- Export all matching content by post type, category, and tag, using any supported Markdown text filename extension.
- Convert WordPress HTML and block content to GitHub Flavored Markdown.
- Include local Media Library images in `images/` and linked audio, video, or PDF files in `media/`, using relative Markdown paths.
- Include supported post metadata in Front Matter.
- Preserve normal OSS processing when an OSS plugin is configured, while falling back to local media storage when the detected OSS integration is incomplete.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- PHP ZIP is preferred for ZIP import and export; both operations fall back to the WordPress-bundled PclZip library when it is unavailable
- PHP zlib extension when the PclZip fallback is used
- PHP DOM extension for HTML-to-Markdown export
- Native PHP mbstring is recommended for performance; a bundled compatibility layer is used when it is unavailable
- A writable WordPress uploads directory

Import limits follow the effective PHP and WordPress upload limit by default. Administrators can set lower or higher plugin limits, but PHP and the web server can still reject a request before WordPress receives it.

## Languages

Fangtao MD IO follows the active WordPress site or user language automatically. Chinese is the default source language, and an `en_US` translation is bundled with the plugin. Translators can use `languages/fangtao-md-io.pot` to create additional locale files without changing plugin code.

## Installation

### WordPress Dashboard

1. Download or build a ZIP containing the `fangtao-md-io` plugin directory.
2. Open **Plugins > Add Plugin > Upload Plugin**.
3. Select the ZIP package and install it.
4. Activate **Fangtao MD IO**.
5. Open the new **Markdown** menu in the WordPress Dashboard.

### Manual Installation

1. Upload the `fangtao-md-io` directory to `wp-content/plugins/`.
2. Activate the plugin from **Plugins > Installed Plugins**.

## Importing Markdown

Open **Markdown > Markdown Import**.

1. Select one or more supported Markdown text files or `.zip` archives.
2. Choose the destination content type.
3. When importing standard posts, optionally choose a destination category.
4. Choose **Draft** or **Publish immediately**. Leave the date empty to use each Markdown file's ZIP modification time, or set an exact manual publication date and time. You can also choose private visibility or an access password.
5. Choose a Markdown parser.
6. Click **Upload and Import**.

Each Markdown file creates one WordPress content item. The import screen supports standard posts, pages, and public custom post types that the current user can edit.

ZIP archives that do not contain a supported Markdown document are skipped and do not import their bundled assets.

Date priority is: manual publication date, ZIP Markdown modification time, Front Matter `date`, then the current WordPress time.

When multiple ZIP files are imported without a manual publication date, every Markdown document uses its own ZIP modification time. Different archives and documents can therefore create posts with different publication dates.

Private visibility and password protection are separate WordPress modes, so the import form allows only one of them at a time.

Administrators can set the import form's initial post status and Markdown parser, enable remote image imports, choose allowed ZIP asset formats, and configure import limits under **Import Settings**. A blank or zero size limit follows the current PHP/WordPress upload limit. Remote image importing is disabled by default.

### Markdown Parsers

The importer includes five parser choices and three Markdown syntax flavors:

- **Parsedown** - GitHub flavor
- **Parsedown Extra** - Extra flavor
- **Cebe Markdown** - Traditional flavor
- **Cebe Markdown GitHub** - GitHub flavor
- **Cebe Markdown Extra** - Extra flavor

Every parser result is sanitized through WordPress before it is stored. Parsedown parsers also run in safe mode.

### Standalone Markdown

A standalone Markdown file can be uploaded directly when it does not depend on bundled local assets. Remote image URLs remain remote unless an administrator enables remote image importing.

### ZIP Import

Package Markdown files and their local assets together:

```text
articles/
  living-room.md
  images/
    living-room.jpg
  media/
    room-tour.mp4
```

Reference the image relative to the Markdown file:

```markdown
![Living room](images/living-room.jpg)
```

During import, supported local images are added to the WordPress Media Library and their references in the generated post are updated.

Use a normal Markdown link for downloadable assets, or a WordPress media shortcode for playable local video and audio:

```markdown
[Download catalog](media/catalog.pdf)
[video src="media/room-tour.mp4"]
[audio src="media/interview.mp3"]
```

Only referenced assets are added to the Media Library. Raw executable formats are never accepted, even when import formats are configured.

## Front Matter

The importer accepts a safe subset of single-line YAML-style Front Matter:

```yaml
---
title: A Calm Living Room
slug: calm-living-room
excerpt: A practical guide to creating a calm and comfortable space.
date: 2026-07-10T12:00:00+08:00
status: draft
password: optional-password
categories: Furniture News, Buying Guides
tags: oak, living room
featured_image: images/cover.jpg
---
```

| Key | Import | Export | Notes |
| --- | --- | --- | --- |
| `title` | Yes | Yes | Falls back to the first level-one heading or filename. |
| `slug` | Yes | Yes | Configures the WordPress post slug. |
| `permalink` | Yes | Yes | The final path segment is used as the slug when `slug` is absent. |
| `excerpt` | Yes | Yes | `description` is also accepted during import. |
| `featured_image` | Yes | Yes | Accepts a bundled path, Media Library URL, remote URL when enabled, or attachment ID. `cover` and `image` are aliases. |
| `featured_image_id` | Yes | No | Uses an existing Media Library image attachment. |
| `date` | Yes | Yes | Accepts a date understood by PHP and stores it in the WordPress site timezone. |
| `password` | Yes | No | Password-protects the imported post. |
| `categories` | Yes | Yes | Comma-separated names or IDs for standard posts. `category` is an alias. |
| `tags` | Yes | Yes | Comma-separated names for standard posts. `tag` is an alias. |
| `post_type` | Selected in the UI | Yes | Import destination is controlled by the import form. |
| `status` | Yes | Yes | Supports draft, pending, private, publish, and future subject to user capabilities. |

Complex or nested YAML values are not currently supported.

## Exporting Markdown

Open **Markdown > Markdown Export** to see the available content types.

The export screen can create a ZIP from all matching content. Choose a content type, optionally filter standard posts by category and tag, and select any supported Markdown text document extension.

### Single Export

1. Open a WordPress content list.
2. Hover over an item.
3. Click **Export Markdown**.

The downloaded ZIP has this structure:

```text
article.md
images/
  article-image.jpg
```

### Bulk Export

1. Open a WordPress content list.
2. Select multiple items.
3. Choose **Export Markdown ZIP** from **Bulk actions**.
4. Apply the action.

Each exported item receives its own directory:

```text
article-one-123/
  article.md
  images/
article-two-456/
  article.md
  images/
```

Images that belong to the local WordPress uploads directory are copied into the matching `images/` directory. External images remain external URLs.

## Supported Content

The Markdown converter supports common WordPress content structures, including:

- Headings and paragraphs
- Bold, italic, and strikethrough text
- Links and images
- Ordered and unordered lists
- Blockquotes
- Inline code and fenced code blocks
- GitHub Flavored Markdown tables
- Image captions
- Basic audio, video, and embedded media links

Shortcodes are processed before export. Output from complex blocks or third-party shortcodes may be simplified during HTML-to-Markdown conversion.

## Import Limits and Security

The importer applies the following safeguards:

- Archive, extracted-content, Markdown, and per-asset limits follow the effective PHP/WordPress upload limit unless an administrator enters a manual MB value
- ZIP entry count defaults to 500 and can be configured up to 10,000
- Only administrator-selected extensions from the plugin's safe image, video, audio, and PDF allowlist are extracted
- ZIP path traversal and symbolic links are rejected
- Unsupported archive entries are ignored
- Imported HTML is sanitized by WordPress
- Import and export actions require WordPress capabilities and nonces

## Known Limitations

- The plugin does not provide a Markdown editor or live preview.
- The plugin does not add tools to the Gutenberg or Classic Editor sidebar.
- A document management system and REST API are not included.
- Exported HTML from complex page builders may not have a lossless Markdown representation.

## Frequently Asked Questions

### Can I import ZIP files without the PHP ZIP extension?

Yes. The importer prefers the PHP ZIP extension and falls back to the PclZip library bundled with WordPress when that extension is unavailable.

### Can I import Markdown without the PHP mbstring extension?

Yes. The plugin includes an mbstring compatibility layer for servers without the native extension. Enabling native mbstring is still recommended for better performance.

### Why is export unavailable?

HTML-to-Markdown export requires the PHP DOM extension. Creating the ZIP requires either PHP ZIP or the WordPress PclZip fallback with PHP zlib.

### Why was an imported image stored locally instead of OSS?

If the detected OSS integration is enabled but does not have a complete bucket and credential or role configuration, the importer uses normal WordPress local media storage to avoid a fatal upload failure. Complete the OSS plugin configuration to restore its normal processing.

### Why did an image remain an external URL?

Remote image URLs are preserved by default. An administrator can enable **Automatically import remote images** under **Import Settings**. Downloads use the WordPress safe HTTP and Media Library pipelines and follow the configured per-asset limit.

### Does the plugin modify existing posts during import?

No. Each imported Markdown document creates a new content item.

## Changelog

### 1.9.15

- Centered the Current time and Clear action icons in the publication date picker.

### 1.9.14

- Anchored the publication date popover to its input and combined date and time in one precise control.

### 1.9.13

- Made the publication date popup open reliably above or below its input, with aligned action icons.

### 1.9.12

- Added a compact publication date field with a popup for typing or selecting date and time, plus Current time and Clear controls.

### 1.9.11

- Made the Markdown import and export pages use the available WordPress admin content width.

### 1.9.10

- Added persistent import statistics and recent import logs.
- Added an Advanced action that clears only import statistics and logs after confirmation.

### 1.9.9

- Added a clear selected files button to the Markdown and ZIP upload field.

### 1.9.8

- Refined the import donut chart with percentage callouts, a horizontal legend, and segment highlighting on hover or keyboard focus.

### 1.9.7

- Replaced the import statistics bar with a live color-coded donut chart and processed total.

### 1.9.6

- Restored visible dropdown arrows for plugin select fields in light and dark admin themes.

### 1.9.5

- Refined the Markdown import and export interfaces with a shared full-width workspace, clearer form sections, improved controls, and responsive export cards.

### 1.9.4

- Reworked the batch import sidebar with a visible import statistics dashboard, color-coded result bar, per-file task progress, and reset control.

### 1.9.3

- Kept the import queue visible, expanded the desktop layout, and protected the import form from being compressed on narrower admin screens.

### 1.9.2

- Added a sequential browser upload queue for multiple Markdown and ZIP files, with per-file progress, success, skip, and failure feedback.

### 1.9.1

- Fixed imported Media Library images being left with an empty attachment title and appearing as unfinished uploads.
- Use each Markdown document's ZIP modification time when no manual publication date is set.

### 1.9.0

- Added optional import date and time controls with second-level precision.
- Added private visibility and password-protected post controls.
- Added Front Matter password support.

### 1.8.1

- Fixed vertical alignment for the Markdown admin menu icon and refreshed its stylesheet version.

### 1.8.0

- Added WordPress locale-aware English translations and a translation template.
- Fixed Markdown menu icon centering in the WordPress admin sidebar.
- Validate imported image metadata before completion to avoid unfinished Media Library items.
- Moved GitHub documentation to `docs/` and excluded `.gitattributes` from archive exports.

### 1.7.0

- Added configurable safe ZIP asset formats for images, video, audio, and PDF documents.
- Added Media Library import and relative-path rewriting for linked assets and WordPress video/audio shortcodes.
- Added configurable archive, extracted-content, Markdown, per-asset, and archive-entry limits that follow PHP/WordPress limits by default.
- Packaged supported local linked media in the exported ZIP `media/` directory.

### 1.6.1

- Restricted ZIP fallback extraction to validated Markdown and image entries to prevent temporary-directory exhaustion from unrelated archive contents.
- Enforced direct-upload, per-file, aggregate extraction, and remote-download size limits using actual bytes written.
- Required Media Library upload permission for imports and blocked exported image paths that resolve outside the WordPress uploads directory.
- Updated Parsedown from 1.7.4 to 1.8.0 to address a reported regular-expression denial-of-service issue.

### 1.6.0

- Renamed the plugin, directory, main plugin file, admin page slugs, text domain, and Composer package to Fangtao MD IO (`fangtao-md-io`).
- Kept existing `ftmzi_*` settings and internal identifiers compatible so upgrades retain saved configuration.

### 1.5.1

- Added import, ZIP detection, and export support for these Markdown file extensions (case-insensitive): .md, .markdown, .mdown, .mkdn, .mkd, .mdwn, .mdtxt, .mdtext, .文本, and .txt.

### 1.5.0

- Added five selectable Markdown parsers: Parsedown, Parsedown Extra, Cebe Markdown, Cebe Markdown GitHub, and Cebe Markdown Extra.
- Added Traditional, GitHub, and Extra syntax flavor labels with live parser feedback.
- Added a persistent default parser and a per-import parser override.
- Bundled the parser dependencies for portable WordPress installation.

### 1.4.0

- Added Front Matter import for dates, statuses, permalinks, categories, tags, and existing featured image IDs.
- Added optional safe remote image importing for body and featured images.
- Added filtered mass export by content type, category, and tag.
- Added `.md` and `.markdown` export extension selection.
- Added permalink, category, and tag Front Matter to exported documents.

### 1.3.0

- Added an optional category selector when importing standard posts.
- Added a persistent Draft or Publish immediately default for the import form.

### 1.2.0

- Added a WordPress PclZip fallback for individual and bulk Markdown ZIP exports.
- Preserved the existing `article.md` and relative `images/` package structure without requiring PHP ZIP.

### 1.1.3

- Added a bundled mbstring compatibility layer for Markdown conversion on servers without the PHP mbstring extension.

### 1.1.2

- Added a WordPress PclZip fallback for ZIP imports when the PHP ZIP extension is unavailable.
- Kept archive path, entry count, and extracted-size checks on the fallback path.

### 1.1.1

- Prevented incomplete OSS configuration from causing fatal errors during image import.
- Added readable handling for media import exceptions.

### 1.1.0

- Added individual and bulk Markdown ZIP export.
- Added HTML-to-Markdown conversion and relative image packaging.
- Added exported Front Matter data.

### 1.0.0

- Initial Markdown and ZIP import release.
- Added local image import and featured image handling.

## License

GPL-2.0-or-later. See [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).

Bundled parser libraries are distributed under the MIT License: [Parsedown](https://github.com/erusev/parsedown), [Parsedown Extra](https://github.com/erusev/parsedown-extra), and [cebe/markdown](https://github.com/cebe/markdown).

## Author

Fangtao
