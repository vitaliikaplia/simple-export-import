# Simple Export & Import

A WordPress plugin for exporting and importing posts as JSON with **full Gutenberg / ACF / multilingual** support.

## Description

Simple Export & Import exports any post to a portable JSON file and imports it back on another site without losing data. It correctly preserves:

- Gutenberg blocks (including InnerBlocks and nested structures)
- ACF blocks (the JSON inside `<!-- wp:acf/... -->` block comments)
- ACF Repeater / Group / Flexible Content fields (via `_field_*` references)
- Custom fields (post meta), taxonomies, featured image, attachment references
- Cyrillic and other UTF-8 content (no escaping)
- WPML translations — and **WP-LOC** translations (any plugin exposing the WPML hook surface)

### Why a dedicated plugin?

The trap with naive WP import scripts is that `wp_insert_post()` and `update_post_meta()` internally call `wp_unslash()`. JSON-decoded content has no slashes, so backslashes inside Gutenberg/ACF block attributes get silently stripped — corrupting blocks. This plugin calls `wp_slash()` on every string before passing it to WordPress, so block JSON survives intact.

## File Structure

```
simple-export-import/
├── simple-export-import.php          # Bootstrap (constants, requires, init)
├── uninstall.php                     # Cleans up options on plugin delete
├── README.md
├── includes/
│   ├── helpers.php                   # Defaults, capabilities, validation, multilingual detection
│   ├── class-sei-multilingual.php    # WPML / WP-LOC compatibility layer
│   ├── class-sei-settings.php        # Settings page, option registration
│   ├── class-sei-export.php          # JSON export + post row actions
│   └── class-sei-import.php          # Tools → Import Post page + post insertion
└── languages/
    ├── simple-export-import.pot      # Translation template
    ├── simple-export-import-uk.po/.mo    # Ukrainian
    ├── simple-export-import-de_DE.po/.mo # German
    └── simple-export-import-ru_RU.po/.mo # Russian
```

No Composer / external dependencies — the plugin uses plain `require_once`.

## Installation

1. Upload the `simple-export-import` folder to `/wp-content/plugins/`.
2. Activate via **Plugins** menu.
3. Configure under **Settings → Export & Import**.

## Settings

The settings page is grouped into five sections.

### General
- **Post Types** — which post types get the Export row action.

### Permissions
- **Export Capability** — minimum capability required to export.
- **Import Capability** — minimum capability required to import.

### Import Behavior
- **Import Status** — default post status for imported posts (Draft / Published / Pending / Private).
- **Import Title Suffix** — text appended to imported titles. Default ` - imported`; leave empty to keep the original title unchanged.
- **Preserve Original Author** — when enabled and the original author's user ID exists on this site, they are kept as the author; otherwise the current user becomes the author.

### Export Behavior
- **Pretty-print JSON** — toggle indentation (off = smaller files).
- **Extra Meta Keys to Skip** — additional meta keys to exclude from export, one per line. WordPress internals (`_edit_lock`, `_wp_trash_meta_status`, etc.) are already skipped.
- **Max Upload Size (MB)** — limit on uploaded JSON file size at import time.

### Media
- **Embed Media Files** — when on, every attachment referenced by the post (featured image, attached media, Gutenberg/ACF blocks, post meta including nested ACF Repeater/Group/Flexible) is embedded as base64 in the JSON. On import, each is recreated on the target site and **all references are remapped** from old IDs to new IDs across post_content (via `parse_blocks`/`serialize_blocks`), block attributes, meta fields, and `wp-image-N` / `data-id` / `[gallery ids=""]` legacy markup. URLs are remapped too. Multilingual-aware: the same image referenced across translation posts is uploaded once and all language posts get the same new ID.
- **Max Embedded File Size (KB)** — files above this limit are exported as references only (old behavior). Default 10240 (10 MB).

### Multilingual
- **Multilingual Translations** — when active, exporting any post bundles all of its translations into the same JSON. On import, translations are recreated and re-connected via the multilingual plugin. Auto-disabled if no compatible plugin is detected.

## Usage

### Export
1. Open any post list (Posts, Pages, or a custom post type enabled in Settings).
2. Hover a row → click **Export** under the row actions.
3. A JSON file downloads with everything needed to recreate the post.

### Import
1. Go to **Tools → Import Post**.
2. Pick a JSON file produced by this plugin.
3. Click **Import Post**.
4. A success notice with a link to the new post appears.

## Multilingual support (WPML & WP-LOC)

The plugin talks to multilingual backends through the **WPML hook surface only**: `wpml_element_language_details`, `wpml_get_element_translations`, `wpml_element_trid`, `wpml_set_element_language_details`. This means it works transparently with:

- **WPML** itself
- **WP-LOC** (lightweight multilingual plugin that emulates the WPML hooks)
- Any other plugin registering the same hooks

Detection is automatic (`sei_is_multilingual_active()` in `includes/helpers.php`). Language codes round-trip in WPML format (`en`, `uk`, `de`...) regardless of the underlying plugin's internal representation.

### Round-trip matrix

| Export from | Import to | Result |
|---|---|---|
| WPML | WPML | ✅ connected |
| WPML | WP-LOC | ✅ connected |
| WP-LOC | WP-LOC | ✅ connected |
| WP-LOC | WPML | ✅ connected |
| any | site without multilingual plugin | ⚠️ imported as separate posts with a notice |

## What gets exported

```json
{
  "ID": 123,
  "post_title": "Sample Post",
  "post_content": "<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->",
  "post_excerpt": "...",
  "post_status": "publish",
  "post_name": "sample-post",
  "post_type": "post",
  "post_author": "1",
  "post_date": "2024-01-01 12:00:00",
  "meta_fields": { "custom_field": "value", "_field_5e0a1b2c": "..." },
  "taxonomies": { "category": [ { "term_id": 5, "name": "News", "slug": "news" } ] },
  "featured_image": { "id": 456, "url": "...", "file": "..." },
  "attachments": [ ... ],
  "wpml_enabled": true,
  "original_language": "en",
  "translations": [
    { "language_code": "uk", "post_title": "...", "post_content": "...", "meta_fields": {...}, ... }
  ]
}
```

**Note:** files (actual images) are not embedded — only references (IDs and paths). The attachment is associated with the new post on import if the same attachment ID already exists in the target media library.

## Localization

Bundled translations: Ukrainian (`uk`), German (`de_DE`), Russian (`ru_RU`).

To add a new language:
1. Open `languages/simple-export-import.pot` in Poedit or any PO editor.
2. Save as `simple-export-import-{locale}.po` (e.g. `simple-export-import-pl_PL.po`).
3. Compile to `.mo`: `msgfmt -o simple-export-import-{locale}.mo simple-export-import-{locale}.po`.
4. WordPress picks the right file based on the site locale.

## Requirements

- WordPress 4.7+
- PHP 7.4+
- JSON support (default in PHP)

## Security

- Nonce verification on every export request and import form
- Capability checks (configurable per action)
- File size + extension + JSON-validity check on upload
- All input sanitized; output escaped
- `wp_kses` applied by core based on the importing user's `unfiltered_html` capability — admins import unmodified content, lower-privileged users get filtered content

## FAQ

**Q: Are image files exported?**
No, only references (IDs and file paths). The attachment is reused by ID on the target site if it exists. This keeps export files small.

**Q: Does import update an existing post?**
No, every import creates a new post. The title suffix (default ` - imported`) distinguishes copies; set the suffix to empty in Settings to keep original titles.

**Q: Do ACF Repeater / Group fields work?**
Yes. Both the field values and the `_field_*` key references are exported, which is what ACF needs to render the fields in admin.

**Q: Will Gutenberg blocks with backslashes / quotes in attributes break?**
No. The plugin calls `wp_slash()` before `wp_insert_post()` / `update_post_meta()` so backslashes in block JSON survive the WP-internal `wp_unslash()` call. This is the most common cause of broken ACF blocks after generic JSON imports.

**Q: Can I bulk-export?**
Not yet — one post per JSON file. Each post can carry all its translations though.

## Changelog

### 1.2
- **Direct `$wpdb` imports** — `wp_insert_post()`, `update_post_meta()`, `wp_set_post_terms()`, `set_post_thumbnail()`, `wp_update_post()` all replaced with raw `$wpdb` operations. Third-party `save_post` / `transition_post_status` / `added_post_meta` / `set_object_terms` hooks no longer fire on import, eliminating content mutation, validation errors, and recursion from imported posts.
- **Yoast SEO indexable rebuild** — after meta restore, `Indexable_Builder::build_for_id_and_type()` is called explicitly so the `wp_yoast_indexable` cache reflects imported `_yoast_wpseo_*` postmeta. Same hook for RankMath.
- **Media embed (opt-in)** — JSON now optionally carries base64-encoded files for every referenced attachment (featured image, attached media, Gutenberg/ACF block images, ACF Image/File/Gallery fields including nested Repeater/Group/Flexible). On import: re-create attachments via `$wpdb` + `wp_generate_attachment_metadata`, build a single deduplicated `old_id → new_id` map across the main post and all translations, and rewrite references in post_content (via `parse_blocks`/`serialize_blocks`), block attrs, meta values (recursive), legacy `wp-image-N` / `data-id` / `[gallery ids=""]`, and URLs. New settings: Embed Media Files, Max Embedded File Size (KB).
- **Export fidelity**: `post_date_gmt`, `comment_status`, `ping_status`, `menu_order` added so direct `$wpdb` insert can reconstruct the full row.
- **Unique slug generator** — `wp_unique_post_slug()` replaced with a direct posts-table lookup (no hook chain).
- New translation strings: media/Yoast/$wpdb error messages. Total 75 strings translated across uk / de_DE / ru_RU.

### 1.1
- **Critical fix**: `wp_slash()` on `post_content` / `post_excerpt` / meta values before `wp_insert_post()` and `update_post_meta()`. Gutenberg and ACF blocks no longer break on import due to WP's internal `wp_unslash()` stripping backslashes from block JSON.
- **Removed `wp_kses_post()` wrapper** on content — `wp_insert_post()` already applies kses based on user capability; the duplicate call sometimes corrupted custom block markup.
- **ACF fields** (Repeater / Group / Flexible Content) now export correctly: `_field_*` references are no longer stripped. Built-in skip list now whitelists only true WP internals.
- **WP-LOC support**: detection via `class WP_LOC` and `has_filter('wpml_*')`, plus normalization of `wpml_element_language_details` (object vs array) so wp-loc's return shape works alongside WPML's.
- **No more `$sitepress` dependency** — translation connecting uses `apply_filters('wpml_element_trid', ...)` for portability.
- **Reorganized**: monolithic file split into `includes/` (helpers + 4 classes). Main file is now a thin bootstrap.
- **New settings**: Import Title Suffix, Preserve Original Author, Extra Meta Keys to Skip, Max Upload Size (MB), Pretty-print JSON.
- **Localization**: Ukrainian, German, Russian translations bundled. `.pot` template included for new languages.
- **uninstall.php** removes every plugin option on plugin delete (multisite-aware).

### 1.0
- Initial release.

## Author

**Vitalii Kaplia** — [vitaliikaplia.com](https://vitaliikaplia.com/)

## License

GPLv2 or later.

## Support

For issues or feature requests, please contact the author.
