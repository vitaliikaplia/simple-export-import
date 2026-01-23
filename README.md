# Simple Export & Import

A professional WordPress plugin for exporting and importing posts with complete data preservation.

## Description

Simple Export & Import allows you to easily export WordPress posts to JSON format and import them back with full data preservation. Perfect for migrating content between sites, creating backups of individual posts, or duplicating content.

### Key Features

- **Complete Data Export** - Exports all post data including:
  - Post content, title, excerpt, status, and metadata
  - Custom fields (post meta)
  - Taxonomies (categories, tags, custom taxonomies)
  - Featured images (attachment references)
  - Post attachments (file references)

- **Flexible Settings**
  - Choose which post types can be exported
  - Set user capabilities for export/import operations
  - Configure default status for imported posts

- **Security First**
  - Nonce verification for all operations
  - Capability checks
  - File validation and sanitization
  - Secure data handling

- **User-Friendly Interface**
  - Export links directly in post list actions
  - Simple import page in Tools menu
  - Clean settings page

## Installation

1. Upload the `simple-export-import` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → Export & Import to configure the plugin

## Usage

### Configuration

Navigate to **Settings → Export & Import** to configure:

1. **Post Types** - Select which post types should have export functionality
2. **Export Capability** - Choose minimum user capability required to export posts
3. **Import Capability** - Choose minimum user capability required to import posts
4. **Import Status** - Set default status for imported posts (draft, publish, pending, private)

### Exporting Posts

1. Navigate to the post type list (Posts, Pages, or custom post types)
2. Hover over the post you want to export
3. Click the **Export** link in the row actions
4. A JSON file will be downloaded to your computer

The exported file contains all post data in a structured JSON format.

### Importing Posts

1. Navigate to **Tools → Import Post**
2. Click **Choose File** and select your JSON export file
3. Click **Import Post**
4. The post will be created with the status configured in settings
5. A success message will appear with a link to edit the imported post

## What Gets Exported/Imported

### Exported Data

- Post ID (reference only)
- Post title, content, excerpt
- Post status, name (slug), type
- Post author ID, post date
- **Meta Fields** - All custom fields (excluding internal WordPress meta starting with `_`)
- **Taxonomies** - All terms with IDs, names, and slugs
- **Featured Image** - Attachment ID, URL, and file path
- **Attachments** - All attached media with IDs and file information

### Import Behavior

- **New Post Creation** - Always creates a new post (doesn't update existing)
- **Post Title** - Appends " - imported" to distinguish from original
- **Meta Fields** - Restored if valid
- **Taxonomies** - Terms are assigned if they exist (by ID or slug)
- **Featured Image** - Set if attachment ID exists in the target site
- **Attachments** - Associated with new post if they exist in the target site

**Note:** Files themselves are NOT exported/imported - only references (IDs and paths). This is intentional to keep export files small and portable.

## File Format

Exports are saved as JSON files with UTF-8 encoding. Example structure:

```json
{
  "ID": 123,
  "post_title": "Sample Post",
  "post_content": "...",
  "post_excerpt": "...",
  "post_status": "publish",
  "post_name": "sample-post",
  "post_type": "post",
  "post_author": "1",
  "post_date": "2024-01-01 12:00:00",
  "meta_fields": {
    "custom_field": "value"
  },
  "taxonomies": {
    "category": [
      {
        "term_id": 5,
        "name": "News",
        "slug": "news"
      }
    ]
  },
  "featured_image": {
    "id": 456,
    "url": "...",
    "file": "..."
  },
  "attachments": []
}
```

## Requirements

- WordPress 4.7 or higher
- PHP 5.6 or higher
- JSON support (enabled by default in PHP)

## Security

The plugin implements multiple security measures:

- **Nonce Verification** - All forms and actions are protected
- **Capability Checks** - User permissions are verified before operations
- **File Validation** - Uploaded files are validated (extension, size, JSON format)
- **Data Sanitization** - All input is sanitized and output is escaped
- **Size Limit** - Import files limited to 5MB

## Frequently Asked Questions

### Q: Are the actual image files exported?

No, only references (IDs and file paths) are exported. This keeps export files small. When importing, the plugin attempts to map existing attachments by ID.

### Q: Can I update an existing post with import?

No, the plugin always creates a new post. This is by design to prevent accidental overwrites.

### Q: What happens if taxonomies don't exist on import?

The plugin checks if taxonomies and terms exist. If a taxonomy doesn't exist, it's skipped. If terms exist (by ID or slug), they're assigned to the imported post.

### Q: Can I export multiple posts at once?

Currently, posts must be exported individually. Bulk export is a potential future feature.

### Q: What about custom post types?

Yes! In settings, you can select any public post type for export functionality.

### Q: Are ACF (Advanced Custom Fields) fields exported?

Yes, ACF fields are stored as post meta and will be included in the export.

## Changelog

### 1.0
- Initial release
- Export posts to JSON
- Import posts from JSON
- Settings page with post type and capability configuration
- Support for meta fields, taxonomies, and attachments
- Security features (nonce, capabilities, validation)

## Author

**Vitalii Kaplia**
Website: [https://vitaliikaplia.com/](https://vitaliikaplia.com/)

## License

This plugin is licensed under GPLv2 or later.

## Support

For issues, questions, or feature requests, please contact the author or submit an issue on the plugin repository.

---

**Made with ❤️ for the WordPress community**
