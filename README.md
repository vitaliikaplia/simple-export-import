# Simple Export & Import

A WordPress plugin for exporting and importing posts as JSON with **full Gutenberg / ACF / multilingual** support, optional embedded media with ID remapping, and GitHub-backed auto-update.

## Description

Simple Export & Import exports any post to a portable JSON file and imports it back on another site without losing data — even when the donor and recipient sites are decoupled. It correctly preserves:

- Gutenberg blocks (including InnerBlocks and nested structures)
- ACF blocks (the JSON inside `<!-- wp:acf/... -->` block comments)
- ACF Repeater / Group / Flexible Content fields (via `_field_*` references)
- Custom fields (post meta), taxonomies, featured image, attached media
- Cyrillic and other UTF-8 content (no escaping)
- WPML translations *and* **WP-LOC** translations (any plugin exposing the WPML hook surface) — bidirectional
- Per-language attachment translations (one file, multiple language rows) — WPML Media Translation model
- Yoast SEO postmeta (and the `wp_yoast_indexable` cache that backs it)

### Why a dedicated plugin?

Two pitfalls of naive post-export-import scripts:

1. **Theme/plugin `save_post` hooks** mutate imported content, throw validation errors, hit external APIs, or recurse. This plugin imports via **direct `$wpdb` writes**, deliberately bypassing `save_post`, `transition_post_status`, `added_post_meta`, `set_object_terms`, and friends — content lands exactly as exported.
2. **`wp_unslash()` strips backslashes** from Gutenberg/ACF block JSON when content goes through `wp_insert_post()` / `update_post_meta()`. The plugin sidesteps the whole `wp_unslash` problem by writing directly via `$wpdb` (which does not unslash) — so ACF block JSON attributes and any string with `\` survive intact.

After the raw write, the plugin selectively re-invokes the few SEO-specific rebuilders (Yoast `Indexable_Builder`, Rank Math link cache) that would otherwise have run from `save_post` — so the data shows up on the frontend and in admin UI immediately.

## File Structure

```
simple-export-import/
├── simple-export-import.php          # Bootstrap (constants, requires, init)
├── uninstall.php                     # Cleans up options on plugin delete
├── README.md
├── .gitignore
├── includes/
│   ├── helpers.php                   # Defaults, capabilities, validation, multilingual detection
│   ├── class-sei-multilingual.php    # WPML / WP-LOC compatibility layer
│   ├── class-sei-media.php           # Base64 embed + ID/URL remap + per-language attachment translations
│   ├── class-sei-settings.php        # Settings page, option registration
│   ├── class-sei-export.php          # JSON export + post row actions
│   ├── class-sei-import.php          # Tools → Import Post page + $wpdb-direct insert
│   └── class-sei-github-updater.php  # WP admin auto-update from GitHub master branch
└── languages/
    ├── simple-export-import.pot          # Translation template
    ├── simple-export-import-uk.po/.mo    # Ukrainian
    ├── simple-export-import-de_DE.po/.mo # German
    └── simple-export-import-ru_RU.po/.mo # Russian
```

No Composer / external dependencies — plain `require_once`.

## Installation

### One-time setup

1. Upload the `simple-export-import` folder to `/wp-content/plugins/` (or install the zip via Plugins → Add New → Upload).
2. Activate via **Plugins** menu.
3. Configure under **Settings → Export & Import**.

### Updates

Once installed, updates ship through the regular WordPress Updates screen — no FTP, no zip uploads. The plugin watches the master branch of its GitHub repository and surfaces a "new version available" notice when the header version changes. One-click **Update now** does the rest.

See [GitHub auto-update](#github-auto-update) below for details.

## Settings

The settings page is grouped into six sections.

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
- **Max Upload Size (MB)** — limit on uploaded JSON file size at import time. Default and ceiling are derived from the server's PHP config (`min(upload_max_filesize, post_max_size)` via `wp_max_upload_size()`). The setting can only tighten the cap, never raise it past what PHP physically accepts. The current PHP cap is shown in the field's description.

### Media
- **Embed Media Files** — when on, every attachment referenced by the post (featured image, attached media, Gutenberg/ACF blocks, post meta including nested ACF Repeater/Group/Flexible) is embedded as base64 in the JSON. On import, each is recreated on the target site and **all references are remapped** from old IDs to new IDs across post_content (via `parse_blocks`/`serialize_blocks`), block attributes, meta fields, and `wp-image-N` / `data-id` / `[gallery ids=""]` legacy markup. URLs are remapped too. Multilingual-aware: the same image referenced across translation posts is uploaded once and all language posts get the same new ID. WPML Media Translation (per-language metadata on one file) round-trips: one file is written to `uploads/`, and N sibling attachment rows share `_wp_attached_file` / `_wp_attachment_metadata` while carrying per-language title/alt/caption/description; they are connected via `wpml_set_element_language_details` with `element_type=post_attachment`.
- **Max Embedded File Size (KB)** — files above this limit are exported as references only (no embed). Default 10240 (10 MB). The current server `memory_limit` is shown in the field's description so you can pick a value that won't OOM during base64 decode (which inflates files by ~33%).

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

| Export from | Import to | Posts | Attachments (with embed enabled) |
|---|---|---|---|
| WPML | WPML | ✅ connected | ✅ per-language siblings connected |
| WPML | WP-LOC | ✅ connected | ✅ per-language siblings connected |
| WP-LOC | WP-LOC | ✅ connected | ✅ per-language siblings connected |
| WP-LOC | WPML | ✅ connected | ✅ per-language siblings connected |
| any | site without multilingual plugin | ⚠️ imported as separate posts (notice shown) | ⚠️ siblings imported but not linked |

## Yoast SEO

Yoast meta (`_yoast_wpseo_*`) round-trips automatically — none of those keys are in the skip-list. Since the import bypasses `save_post`, the plugin explicitly calls `Indexable_Builder::build_for_id_and_type( $post_id, 'post' )` after meta restore so the `wp_yoast_indexable` cache is rebuilt and SEO tags actually render on the frontend. Done per post and per imported translation. Rank Math link cache is cleared similarly (`rank_math/links/clear_cache_for_post`).

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
  "post_date_gmt": "2024-01-01 12:00:00",
  "comment_status": "open",
  "ping_status": "closed",
  "menu_order": 0,
  "meta_fields": { "custom_field": "value", "_field_5e0a1b2c": "...", "_yoast_wpseo_title": "..." },
  "taxonomies": { "category": [ { "term_id": 5, "name": "News", "slug": "news" } ] },
  "featured_image": { "id": 456, "url": "...", "file": "..." },
  "attachments": [ { "id": 789, "title": "...", "url": "...", "file": "..." } ],
  "embedded_attachments": [
    {
      "id": 456,
      "filename": "hero.jpg",
      "mime_type": "image/jpeg",
      "base64": "...",
      "language_code": "en",
      "translations": [
        { "id": 457, "language_code": "uk", "title": "...", "alt": "...", "caption": "...", "description": "..." }
      ]
    }
  ],
  "wpml_enabled": true,
  "original_language": "en",
  "translations": [
    { "language_code": "uk", "post_title": "...", "post_content": "...", "meta_fields": {...}, "embedded_attachments": [...], ... }
  ]
}
```

- `embedded_attachments` is only present when **Embed Media Files** is on. Without it, attachments are still referenced by ID in `featured_image`, `attachments`, and inside `post_content` — but the importer can't recreate them (target must already have those attachment IDs).
- `translations` is only present when a multilingual plugin is active *and* the **Multilingual Translations** setting is on.

## GitHub auto-update

The plugin watches the master branch of its public GitHub repository. The flow:

1. WordPress's normal update check (twice-daily cron, or **Check Again** on the Updates screen with `?force-check=1`) fires the `pre_set_site_transient_update_plugins` filter.
2. `SEI_GitHub_Updater` fetches the plugin header from `https://raw.githubusercontent.com/vitaliikaplia/simple-export-import/master/simple-export-import.php` (12-hour cache, 1-hour on failure).
3. If the remote `Version:` is greater than the installed version, an update entry is injected into the transient.
4. The Plugins screen and Dashboard → Updates show **Update now**, which downloads `https://github.com/vitaliikaplia/simple-export-import/archive/refs/heads/master.zip`.
5. `upgrader_source_selection` renames GitHub's `simple-export-import-master/` extraction to `simple-export-import/` so WP installs it in the right place.

No GitHub API token, no rate-limit headache — `raw.githubusercontent.com` and `archive/refs/heads/<branch>.zip` are static endpoints.

To track a different branch (e.g. a staging branch) on a particular site, define the constant in `wp-config.php`:

```php
define( 'SEI_GITHUB_BRANCH', 'develop' );
```

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
- Direct `$wpdb` import path intentionally bypasses `save_post` / `add_attachment` / `transition_post_status` and similar hooks — content is written exactly as exported. If a theme or plugin needs to react to imported posts (e.g. custom search indexing), it must hook a later admin action or re-save the post manually.

## FAQ

**Q: Are image files exported?**
Optionally — enable **Embed Media Files** in Settings → Media. Embedded files are base64-encoded inside the JSON, recreated on the target site, and every ID/URL reference (featured image, attached media, Gutenberg/ACF block attrs, post meta including ACF Repeater/Group/Flexible, legacy `wp-image-N`/`data-id`/`[gallery ids=""]`) is remapped. Without embedding, only references are exported and the target site must already have those attachment IDs.

**Q: Does import update an existing post?**
No, every import creates a new post. The title suffix (default ` - imported`) distinguishes copies; set the suffix to empty in Settings to keep original titles.

**Q: Do ACF Repeater / Group fields work?**
Yes. Both the field values and the `_field_*` key references are exported, which is what ACF needs to render the fields in admin.

**Q: Will Gutenberg blocks with backslashes / quotes in attributes break?**
No. The import path writes directly via `$wpdb` and does not call `wp_unslash` — the most common cause of broken ACF blocks after generic JSON imports.

**Q: Will my theme's `save_post` action fire on imported posts?**
No. That's a deliberate choice — third-party hooks can mutate content, throw, or recurse. If you specifically need them to fire, open the imported post in the editor and click Update.

**Q: Will Yoast SEO data show up correctly on imported posts?**
Yes. All `_yoast_wpseo_*` postmeta is restored, and the Yoast indexable cache is explicitly rebuilt so the frontend renders the right tags.

**Q: How does media translation work across WPML / WP-LOC?**
WPML's Media Translation model keeps one physical file with multiple attachment rows (one per language) sharing `_wp_attached_file` but carrying per-language title/alt/caption/description. The exporter captures all language siblings for every attachment; the importer recreates them as siblings of a single uploaded file and connects them via the WPML hook surface. Cross-plugin (WPML → WP-LOC and back) is supported.

**Q: How do updates work?**
The plugin watches the master branch of its GitHub repository. New versions surface as standard WordPress plugin updates — Dashboard → Updates → Update now. No external dependencies, no GitHub API token needed.

**Q: Can I bulk-export?**
Not yet — one post per JSON file. Each post can carry all its translations though.

## Changelog

### 1.2
- **Direct `$wpdb` imports** — `wp_insert_post()`, `update_post_meta()`, `wp_set_post_terms()`, `set_post_thumbnail()`, `wp_update_post()` all replaced with raw `$wpdb` operations. Third-party `save_post` / `transition_post_status` / `added_post_meta` / `set_object_terms` hooks no longer fire on import, eliminating content mutation, validation errors, and recursion from imported posts. `wp_slash()` removed from the import path — no longer needed because `$wpdb` does not call `wp_unslash` internally.
- **Yoast SEO indexable rebuild** — after meta restore, `Indexable_Builder::build_for_id_and_type()` is called explicitly so the `wp_yoast_indexable` cache reflects imported `_yoast_wpseo_*` postmeta. Same hook for Rank Math link cache.
- **Media embed (opt-in)** — JSON now optionally carries base64-encoded files for every referenced attachment (featured image, attached media, Gutenberg/ACF block images, ACF Image/File/Gallery fields including nested Repeater/Group/Flexible). On import: re-create attachments via `$wpdb` + `wp_generate_attachment_metadata`, build a single deduplicated `old_id → new_id` map across the main post and all translations, and rewrite references in post_content (via `parse_blocks`/`serialize_blocks`), block attrs, meta values (recursive), legacy `wp-image-N` / `data-id` / `[gallery ids=""]`, and URLs. New settings: Embed Media Files, Max Embedded File Size (KB).
- **Per-language attachment translations** — WPML Media Translation model: one physical file in `uploads/`, N attachment rows (one per language) sharing `_wp_attached_file` and `_wp_attachment_metadata` but each with its own title/alt/caption/description. Connected via `wpml_set_element_language_details` with `element_type=post_attachment`. id_map includes every language sibling so each translation post's content remaps to the attachment for its language.
- **Export fidelity**: `post_date_gmt`, `comment_status`, `ping_status`, `menu_order` added so direct `$wpdb` insert can reconstruct the full row.
- **Unique slug generator** — `wp_unique_post_slug()` replaced with a direct posts-table lookup (no hook chain).
- **GitHub auto-update** — `SEI_GitHub_Updater` hooks WP's plugin update transient and `plugins_api`, fetches the plugin header from `raw.githubusercontent.com`, exposes the archive zip from `github.com/.../archive/refs/heads/master.zip`. 12-hour cache, force-check via `?force-check=1`. Branch overridable via `SEI_GITHUB_BRANCH` constant.
- **Server-derived size limits** — `Max Upload Size (MB)` now defaults to `wp_max_upload_size()` instead of a hardcoded `5`; the effective import cap is `min(setting, PHP upload limit)`. The Import page description and the validator's error message both show the real effective cap, so values above PHP's ceiling silently no-op instead of producing confusing "file too large" errors at the PHP level. Settings page surfaces the actual `upload_max_filesize` / `post_max_size` and `memory_limit` so admins know the ceiling without grepping `phpinfo()`.
- New translation strings: media / Yoast / `$wpdb` error messages, server-limit descriptions. 76 strings translated across `uk` / `de_DE` / `ru_RU`.

### 1.1
- **Critical fix**: `wp_slash()` on `post_content` / `post_excerpt` / meta values before `wp_insert_post()` and `update_post_meta()`. Gutenberg and ACF blocks no longer break on import due to WP's internal `wp_unslash()` stripping backslashes from block JSON. *(Superseded by 1.2's direct `$wpdb` path which sidesteps `wp_unslash` entirely.)*
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

For issues or feature requests, open an issue on [the GitHub repository](https://github.com/vitaliikaplia/simple-export-import/issues) or contact the author.
