# Media Drop

Drupal 10/11 module allowing users to bulk upload photos and videos for easy integration into albums by moderators.

---

**French version / Version française** : See [README_FR.md](README_FR.md)

## Features

### Drop Space (for users)

- **Drop space creation** : Create dedicated spaces with directories and unique URLs
- **Granular permissions** : Control possible user actions (upload, folder creation, deletion)
- **Bulk upload (Dropzone)** : Drag & drop interface to upload multiple files simultaneously
- **Personal organization** : Each user has their own directory in the drop space
- **Subfolders** : Ability to create subfolders (e.g., morning, afternoon, evening) on 1 level
- **Visualization and deletion** : Users can view and delete their own media
- **Anonymous user support** : Anonymous users can upload by providing their name

### Media Management (for moderators)

- **Album management interface** : Manage albums and media from a centralized interface
- **Bulk move** : Move (not copy) multiple media to another directory in a single action
- **Album integration** : Move and integrate media into an existing album with :
  - Configuration of metadata fields (title, alt, description)
  - Assignment of values to fields for all selected media
  - Management of taxonomy fields with automatic term creation
- **Duplicate prevention** : Does not add the same media twice to an album
- **Conflict management** : Automatically ignores media already present in the album

### General Configuration

- **Media type selection** : Choose which Drupal media types to create for uploaded images and videos
- **Organization in Media Browser** : Define where media will be classified in the Media Browser
- **Automatic MIME mapping** : Configures Drupal media types based on MIME types (fallback if not specified)
- **Media Directories integration** : Optional support for organizing media in a hierarchical taxonomy
- **Permission management** : 6 different permissions to control access and actions

## Installation

1. Place the module in `/modules/custom/media_drop/`
2. Enable the module: `drush en media_drop`
3. **(Recommended)** Install Views Bulk Operations for bulk operations:
   ```bash
   composer require drupal/views_bulk_operations
   drush en views_bulk_operations -y
   ```
4. **(Optional)** If you use the [Media Directories](https://www.drupal.org/project/media_directories) module, make sure it is enabled and configured before creating your albums
5. Configure permissions in `/admin/people/permissions`
6. Access the configuration: `/admin/config/media/media-drop`

## Configuration

### Admin Access

Several paths allow you to access the module administration:

**Via the administration menu:**
- Administration > Configuration > Media > **Media Drop**

**Direct paths:**
- General configuration: `/admin/config/media/media-drop`
- Albums list: `/admin/config/media/media-drop/albums`
- MIME mappings: `/admin/config/media/media-drop/mime-mapping`

**Tab navigation:**
Once in the Media Drop interface, you can navigate between sections using tabs:
- Settings
- Albums
- MIME Types

```
Administration > Configuration > Media
    └── Media Drop
        ├── [Tab] Settings (/admin/config/media/media-drop)
        │   ├── General configuration
        │   └── [Button] Manage MIME mappings
        │
        ├── [Tab] Albums (/admin/config/media/media-drop/albums)
        │   ├── Albums list
        │   └── [Button] Add album
        │       ├── Create/Edit an album
        │       └── Delete an album
        │
        └── [Tab] MIME Types (/admin/config/media/media-drop/mime-mapping)
            └── MIME types configuration
```

### 1. Create an Album

1. Go to **Configuration > Media > Media Drop > Albums**
2. Click **"Add album"**
3. Fill in:
   - **Name**: e.g. "Birthday 2025"

4. **Media types**:
   - **Image media type**: Select the Drupal media type to use for images (JPEG, PNG, etc.)
   - **Video media type**: Select the Drupal media type to use for videos (MP4, MOV, etc.)
   - If left empty, the system will use the default MIME mapping
   - Only media types accepting image/video files are proposed

5. **Directories**:
   - **Storage directory**: e.g. `public://media-drop/birthday2025` (where files will be physically stored)
   - **Directory in Media Browser**:
     - **If Media Directories is enabled**: Select a taxonomy term or create a new one. Media will be automatically classified in this virtual directory.
     - **If Media Directories is not enabled**: Enter a text path (e.g. `albums/birthday2025`)

6. **Status**: Active
7. A unique URL will be generated (e.g. `/media-drop/abc123xyz`)

### Media Directories Integration

If you have the [Media Directories](https://www.drupal.org/project/media_directories) module enabled:

1. The album form will automatically display a taxonomy term selector
2. You can choose an existing directory or create a new one directly from the form
3. Uploaded media will be automatically assigned to this directory
4. This allows consistent organization with your existing Media Directories structure

**Advantages**:
- Hierarchical media organization
- Easy filtering in Media Browser
- Consistency with your existing media structure

## Bulk Media Movement

### Via Admin Interface

1. Go to **Configuration > Media > Media Drop > Manage media**
2. **Filter by directory**: Use the "Directory" filter to view only media in a specific folder
3. **Filter by name, type, author** to refine your search
4. Check the media to move
5. In the "Action" dropdown, select **"Move to directory"**
6. Click "Apply to selected items"
7. Choose the destination directory
8. Confirm the move

**Important note:** This action performs a **move** (not a copy). Media will change directory.

### Automatic Taxonomy Structure Creation

When **Media Directories is enabled**, Media Drop automatically creates taxonomy terms for:
- **The album folder** (if configured)
- **User folders** (e.g. "robert.dupont", "marie.martin")
- **Created subfolders** (e.g. "morning", "afternoon", "evening")

**Example of created hierarchy:**
```
Albums/
└── Birthday 2025/           ← Album term
    ├── robert.dupont/       ← Automatically created
    │   ├── morning/         ← Automatically created
    │   ├── afternoon/       ← Automatically created
    │   └── evening/         ← Automatically created
    └── marie.martin/        ← Automatically created
```

**Advantages:**
- Easily find all media from a user
- Navigate by folder in Media Browser
- Use the "Directory" filter in the management view
- Consistent and automatic structure

**Configuration:**
In the album form, check "Automatically create structure" to enable this feature.

### Directory Filtering

In the management view, the **"Directory"** filter displays all folders from the Media Directories taxonomy with:
- Indentation to visualize hierarchy
- All automatically created folders
- Ability to filter on a specific folder

### Via Media Directories (drag & drop)

If Media Directories is enabled:
- **Drag & drop** performs a **copy** by default
- Use the VBO action above for a true **move**

### Available Actions

The management view offers several bulk actions:

1. **Move to album**: Adds selected media to an album with:
   - Album selection in step 1
   - Optional media field configuration in step 2
   - Automatic value application to all selected media
   - Taxonomy field management with automatic term creation

2. **Edit media (bulk)**: Edit multiple media simultaneously with:
   - Display of all configurable fields
   - Grouping of media with identical values
   - Visual summary of common vs multiple values
   - Selective field-by-field modification

3. **Delete**: Delete selected media (with confirmation)

#### "Move to album" Feature

The "Move to album" action provides a two-step interface:

**Step 1: Album Selection**
- Select the destination album
- The "Apply" button activates only after album selection
- Display of destination directory (if Media Directories is enabled)

**Step 2: Optional Configuration**
After selecting an album, you can:

- **Move to directory**: Optional, choose the destination folder
- **Media fields**: Modify media properties (e.g. keyword, category)
- **Metadata**: Set title, alt text, description

**Example:**
```
Select an album: Birthday 2025
Directory: Albums/Birthday/archives
Media fields:
  - Keyword: "2025"
  - Category: "Private event"
Metadata:
  - Title: "Festivities"
  - Alt: "Birthday photos"
```

**Taxonomy field management**:
- Category, keyword, tag fields support multiple input formats:
  - Autocomplete format: `123|Term name`
  - Numeric ID only: `123`
  - Text label: `New term`
- If the term doesn't exist, it is created automatically in the appropriate vocabulary
- New terms receive necessary permissions

#### Bulk Edit Example

When you select 50 media and choose the edit action:

**Automatic summary:**
- Media type: Image (50 media)

**Per field:**
- **Directory**: Multiple values
  - "Albums/Birthday/robert.dupont/morning": 20 media
  - "Albums/Birthday/robert.dupont/afternoon": 30 media
- **Author**: Common value: "Olivier Dupont" (50 media)
- **Description**: (empty): 50 media

You can then choose to modify only certain fields, for example:
- ☑ Modify directory → Move all to "Albums/Birthday/archives"
- ☑ Modify description → Add "2025 event photos"
- ☐ Do not modify author (common value retained)

## Advanced Media Management

### Bulk editing with "Move to album" action

The "Move to album" action available in bulk operations allows you to add media to an album and configure their properties in two steps:

**Step 1 - Album Selection**
- Select the destination album
- The "Apply" button is disabled until an album is selected
- Automatic validation of media type compatibility

**Step 2 - Configuration (optional)**
Once the album is selected, configure:

1. **Destination directory**
   - Selection from existing directories
   - Folder hierarchy display
   - Currently used directories are marked with a star (★)

2. **Media fields**
   - Modify business properties (category, keyword, tag, etc.)
   - Fields are grouped by media type
   - Taxonomy fields support automatic term creation

3. **Metadata**
   - Title: Applied to title fields of images and files
   - Alt: Applied to image alt text
   - Description: Applied to videos and files

### Supported Formats for Taxonomy Fields

When modifying taxonomy fields (category, keyword, etc.), three formats are accepted:

| Format | Example | Behavior |
|--------|---------|----------|
| **Autocomplete** | `123\|My term` | Extracts the ID (123) and uses it directly |
| **Numeric ID** | `123` | Uses the existing term ID |
| **Text label** | `My new term` | Searches for existing term or creates it automatically |

**Usage examples:**

```
"Category" field (taxonomy):
- Enter "456|Important event" → Applies term ID 456
- Enter "789" → Applies term ID 789
- Enter "New category" → Creates or retrieves the term "New category"
```

**Automatic term creation**

When you enter a text label and the term doesn't exist:
- The term is created in the appropriate vocabulary
- The term is automatically assigned to media
- Access permissions are configured correctly

**Advantages**
- Maximum flexibility: ID, autocomplete or simple label
- No risk of duplication: search before creation
- Time saving: no need to pre-create all terms
- Consistency: created terms respect the taxonomy structure

## Advanced Configuration

### Editing Editable Fields

Editable fields in bulk are automatically detected:
- Custom fields (excluding image, file, video)
- Taxonomy fields
- Entity reference fields
- Exclude EXIF fields

To modify the list, edit the configuration in the album form.

### Multiple Vocabularies Management

If a taxonomy field targets multiple vocabularies:
- The first vocabulary is used by default for term creation
- You can specify the vocabulary using the format `vocab_id:term_label`

## Permissions Configuration

Go to **People > Permissions** and configure:

- **Administer Media Drop**: Manage albums and configuration (admin only)
- **Upload media to albums**: Allow uploads
- **View own media**: View uploaded media
- **Delete own media**: Delete their own media
- **Create subfolders in albums**: Organize into subfolders

## Usage

### For the Administrator

1. Create an album
2. Copy the generated URL (e.g. `https://mysite.com/media-drop/abc123xyz`)
3. Share this URL with participants
4. Files will be organized in: `[base_directory]/[username]/[subfolder]/file.jpg`

### For the User

1. Access the album URL
2. Enter your name (mandatory for anonymous users)
3. Optional: Create a subfolder (e.g. "morning", "afternoon", "evening")
4. Drag & drop your photos/videos or click to select
5. Files are uploaded automatically
6. View your uploaded media at the bottom of the page
7. Delete a media if necessary

## File Organization Example

```
public://media-drop/
└── birthday2025/
    ├── robert.dupont/
    │   ├── morning/
    │   │   ├── photo1.jpg
    │   │   └── photo2.jpg
    │   ├── afternoon/
    │   │   └── video1.mp4
    │   └── evening/
    │       └── photo3.jpg
    └── marie.martin/
        ├── photo4.jpg
        └── photo5.jpg
```

## Database

The module creates 3 tables:

- **media_drop_albums**: List of albums
- **media_drop_mime_mapping**: MIME mappings → media type
- **media_drop_uploads**: Upload tracking per user/session

## Security

- Anonymous users must provide their name
- Each user can only view/delete their own media
- Anonymous sessions are tracked for isolation
- Album tokens are generated securely
- File and folder names are sanitized

## Customization

### Modify CSS Styles

Edit `/css/media-drop-upload.css` to customize the appearance.

### Modify JavaScript Behavior

Edit `/js/media-drop-upload.js` to customize the interface.

### Add Custom Media Types

1. Create your media type in Drupal
2. Add the MIME mapping in **Configuration > Media Drop > MIME Types**

## Troubleshooting

### Files don't upload

- Check directory permissions
- Verify the MIME type is mapped
- Check user permissions
- Consult Drupal logs

### Thumbnails don't display

- Verify the media type has a thumbnail field configured
- Check file read permissions

### URL doesn't work

- Verify the album is active
- Verify the token is correct
- Clear the Drupal cache

## Changelog

### Current Version

**Recent improvements:**

- ✨ **"Move to album" action**: Two-step interface to add media to an album with optional configuration
- 🏷️ **Automatic term creation**: Taxonomy fields automatically create non-existent terms
- 🔄 **Multiple formats accepted**: Support for `id|label` format (autocomplete), numeric ID only, and text label for taxonomies
- 📋 **Editable metadata**: Title, alt, description can be applied to all selected media
- 🎯 **Grouped media fields**: Fields are intelligently grouped by type and designation
- ⭐ **Visual indicators**: Used directories marked with a star in the selection
- ⚠️ **Warning messages**: Detailed summaries of processed vs non-processed media
- ✓ **Compatibility validation**: Automatic validation of media type compatibility with album

**Bug fixes:**

- Correct handling of `term_id|term_label` autocomplete format for taxonomy fields
- Form state correctly managed (Apply button inactive until album selection)
- Support for vocabularies with bundle restrictions
- Handling of duplicate media (already in album)

**Technical improvements:**

- `findOrCreateTaxonomyTerm()` method for unified taxonomy term management
- `applyFieldValuesToMedia()` method for configuration value application
- Automatic detection of editable fields by media type
- Field grouping by designation (type + label + vocabulary)
- Detailed logging for debugging and auditing

## License

GPL-2.0+

## Author

Developed for Drupal 10/11

