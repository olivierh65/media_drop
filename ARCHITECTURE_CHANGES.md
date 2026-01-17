# Media Drop Architecture Changes

## Field Widget Centralization (v2.0)

### What Changed?

The `buildFieldWidget()` method was duplicated across multiple action classes. This has been refactored to use a centralized module.

### Before

```php
// In BaseAlbumAction.php, BulkEditMediaAction.php, etc.
protected function buildFieldWidget($field_config, $default_value = NULL) {
  // 100+ lines of duplicated code
  switch ($field_type) {
    case 'string':
      // ...
  }
}
```

### After

```php
// Single source in media_field_representations module
use Drupal\media_field_representations\Traits\FieldWidgetBuilderTrait;

class BaseAlbumAction extends ConfigurableActionBase {
  use FieldWidgetBuilderTrait;
  
  // buildFieldWidget() automatically inherited from trait
}
```

### New Architecture

```
media_field_representations (shared utilities)
  ├── Service: FieldWidgetFactory
  └── Trait: FieldWidgetBuilderTrait
        ↑
        └── depends on
            ├── media_drop
            └── media_album_av
```

### Benefits

✅ **No Duplication**: Code exists in one place  
✅ **Consistency**: All modules use identical widget logic  
✅ **Maintainability**: Fix a bug once, it's fixed everywhere  
✅ **Independence**: media_drop and media_album_av are not interdependent  
✅ **Extensibility**: New modules can easily reuse this logic  

### Affected Classes

- `Drupal\media_drop\Plugin\Action\BaseAlbumAction`
- `Drupal\media_drop\Plugin\Action\BulkEditMediaAction`
- `Drupal\media_attributes_manager\Plugin\Field\FieldWidget\MediaAttributesWidget`

### Migration Path

If you have custom actions or widgets:

1. Add `media_field_representations` to your module dependencies
2. Use the trait: `use FieldWidgetBuilderTrait;`
3. Remove your duplicate method
4. Enjoy simplified maintenance!

### Dependencies

**media_drop** now depends on:
- `media_field_representations` (new)

**media_attributes_manager** now depends on:
- `media_field_representations` (new)

Both modules maintain their existing external dependencies.

---

See [media_field_representations README](../media_field_representations/README.md) for detailed usage.
