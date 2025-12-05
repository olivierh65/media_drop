# Media Drop

Drupal 10/11 module that allows users to bulk upload photos and videos into organized albums.

## Features

- **Album Management**: Create albums with dedicated directories and unique URLs
- **Media Type Selection**: Choose which Drupal media types to create for uploaded images and videos
- **Organization in Media Browser**: Define where media will be classified in the Media Browser
- **Bulk Upload**: Dropzone interface to upload multiple files simultaneously
- **Personal Organization**: Each user has their own directory in the album
- **Subfolders**: Ability to create subfolders (e.g., morning, afternoon, evening)
- **Automatic MIME Mapping**: Configures the created Drupal media types according to MIME types (fallback if not specified in the album)
- **Permission Management**: Granular control with 5 different permissions
- **Anonymous User Support**: Anonymous users can upload by providing their name
- **View and Delete**: Users can see and delete their own media

## Installation

1. Place the module in `/modules/custom/media_drop/`
2. Enable the module: `drush en media_drop`
3. **(Optional)** If you use the [Media Directories](https://www.drupal.org/project/media_directories) module, ensure it is enabled and configured before creating your albums
4. Configure permissions at `/admin/people/permissions`
5. Go to the configuration page: `/admin/config/media/media-drop`

## File Structure

```
media_drop/
├── media_drop.info.yml
├── media_drop.permissions.yml
├── media_drop.routing.yml
├── media_drop.links.menu.yml          # Links in the administration menu
├── media_drop.links.action.yml        # Action buttons
├── media_drop.links.task.yml          # Navigation tabs
├── media_drop.install
├── media_drop.module
├── media_drop.libraries.yml
├── src/
│   ├── Controller/
│   │   ├── AlbumController.php
│   │   └── UploadController.php
│   └── Form/
│       ├── AdminSettingsForm.php
│       ├── AlbumForm.php
│       ├── AlbumDeleteForm.php
│       └── MimeMappingForm.php
├── templates/
│   └── media-drop-upload-page.html.twig
├── js/
│   └── media-drop-upload.js
├── css/
│   └── media-drop-upload.css
└── README.md
```

## Configuration

### Admin Access

Several paths provide access to the module's administration:

**Via the administration menu:**
- Administration > Configuration > Media > **Media Drop**

**Direct paths:**
- General configuration: `/admin/config/media/media-drop`
- Album list: `/admin/config/media/media-drop/albums`
- MIME Mappings: `/admin/config/media/media-drop/mime-mapping`

**Tab Navigation:**
Once in the Media Drop interface, you can navigate between sections using the tabs:
- Settings
- Albums
- MIME Mappings

```
Administration > Configuration > Media
    └── Media Drop
        ├── [Tab] Settings (/admin/config/media/media-drop)
        │   ├── General Configuration
        │   └── [Button] Manage MIME Mappings
        │
        ├── [Tab] Albums (/admin/config/media/media-drop/albums)
        │   ├── Album List
        │   └── [Button] Add Album
        │       ├── Create/Edit Album
        │       └── Delete Album
        │
        └── [Tab] MIME Mappings (/admin/config/media/media-drop/mime-mapping)
            └── MIME Type Configuration
```

### 1. Create an Album

1. Go to **Configuration > Media > Media Drop > Albums**
2. Click **"Add album"**
3. Fill in:
   - **Name**: e.g. "Birthday 2025"

4. **Media Types**:
   - **Media type for images**: Select the Drupal media type to use for images (JPEG, PNG, etc.)
   - **Media type for videos**: Select the Drupal media type to use for videos (MP4, MOV, etc.)
   - If left empty, the system will use the default MIME mapping
   - Only media types that accept image/video files are offered

5. **Directories**:
   - **Storage directory**: e.g. `public://media-drop/birthday2025` (where files will be physically stored)
   - **Directory in Media Browser**:
     - **If Media Directories is enabled**: Select a term from the configured taxonomy, or create a new one. The media will be automatically classified in this virtual directory.
     - **If Media Directories is not enabled**: Enter a text path (e.g., `albums/birthday2025`)

6. **Status**: Active
7. A unique URL will be generated (e.g., `/media-drop/abc123xyz`)

### Integration with Media Directories

If you have the [Media Directories](https://www.drupal.org/project/media_directories) module enabled:

1. The album form will automatically display a taxonomy term selector
2. You can choose an existing directory or create a new one directly from the form
3. Uploaded media will be automatically assigned to this directory
4. This allows for consistent organization with your existing Media Directories structure

**Advantages**:
- Hierarchical organization of media
- Easy filtering in the Media Browser
- Consistency with your existing media structure

### 2. Configure MIME Mappings

1. Go to **Configuration > Media > Media Drop > MIME Mappings**
2. Default mappings are created automatically:
   - `image/jpeg` → media type `image`
   - `video/mp4` → media type `video`
   - etc.
3. Add custom mappings if necessary
4. All custom media types are available

### 3. Configure Permissions

Go to **People > Permissions** and configure:

- **Administer Media Drop**: Manage albums and configuration (admin only)
- **Upload media to albums**: Allow uploading
- **View own media**: See uploaded media
- **Delete own media**: Delete own media
- **Create subfolders in albums**: Organize in subfolders

## Usage

### For the Administrator

1. Create an album
2. Copy the generated URL (e.g., `https://mysite.com/media-drop/abc123xyz`)
3. Share this URL with participants
4. Files will be organized in: `[base_directory]/[user_name]/[subfolder]/file.jpg`

### For the User

1. Go to the album URL
2. Enter your name (required for anonymous users)
3. Optional: Create a subfolder (e.g., "morning", "afternoon", "evening")
4. Drag and drop your photos/videos or click to select
5. Files are uploaded automatically
6. View your uploaded media at the bottom of the page
7. Delete media if necessary

## Example File Organization

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
- **media_drop_uploads**: Tracking of uploads by user/session

## Security

- Anonymous users must provide their name
- Each user can only see/delete their own media
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
2. Add the MIME mapping in **Configuration > Media Drop > MIME Mappings**

## Troubleshooting

### Files are not uploading

- Check directory permissions
- Check that the MIME type is mapped
- Check user permissions
- Check the Drupal logs

### Thumbnails are not displayed

- Check that the media type has a configured thumbnail field
- Check file read permissions

### The URL is not working

- Check that the album is active
- Check that the token is correct
- Clear the Drupal cache

## Support and Contribution

To report a bug or suggest an improvement, contact the development team.

## License

GPL-2.0+

## Author

Developed for Drupal 10/11
