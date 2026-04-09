# 📚 Référence complète des fonctions et méthodes - Module media_drop

**Date**: 9 avril 2026
**Module**: media_drop
**Lieu**: `/web/modules/custom/media_drop`

> Ce document rassemble TOUTES les fonctions et méthodes publiques du module, avec leurs paramètres d'entrée, types de retour et descriptions.

---

## 📋 Table des matières

1. [Hooks Drupal (media_drop.module et .install)](#hooks-drupal--media_dropmodule-et-install)
2. [Contrôleurs](#contrôleurs)
3. [Formulaires](#formulaires)
4. [Services](#services)
5. [Traits et Utilitaires](#traits-et-utilitaires)
6. [Plugins Views](#plugins-views)

---

## Hooks Drupal (`media_drop.module` et `.install`)

### Hooks d'implémentation (.module)

| # | Fonction | Paramètres | Retour | Description |
|---|----------|-----------|--------|-------------|
| 1 | `media_drop_theme()` | `$existing` (array), `$type` (string), `$theme` (string), `$path` (string) | **array** | Implémente hook_theme() - Définit les thèmes personnalisés pour upload_page, draggable_flexgrid, variantes par affichage |
| 2 | `media_drop_theme_suggestions_media_drop_draggable_flexgrid_with_groups_alter()` | `&$suggestions` (array), `$variables` (array), `$hook` (string) | **void** | Génère suggestions thème personnalisées pour grille draggable selon vue et affichage |
| 3 | `media_drop_views_data()` | *(aucun)* | **array** | Expose les données Views : champ Media Details, filtres et arguments répertoires |
| 4 | `media_drop_form_alter()` | `&$form` (array), `$form_state` (FormStateInterface), `$form_id` (string) | **void** | Modifie formulaires Views exposés - Ajoute classes CSS et librairies au filtre include_children |
| 5 | `media_drop_mail()` | `$key` (string), `&$message` (array), `$params` (array) | **void** | Génère contenu emails de notification pour uploads au dépôt |
| 6 | `media_drop_capture_order_submit()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Capture et stocke ordre des médias en tempstore privée (pour VBO) |
| 7 | `media_drop_views_pre_render()` | `$view` (ViewExecutable) | **void** | Nettoie résultats Views : supprime fichiers manquants, configure groupage par répertoire |

### Hooks d'installation (.install)

| # | Fonction | Paramètres | Retour | Description |
|---|----------|-----------|--------|-------------|
| 8 | `media_drop_schema()` | *(aucun)* | **array** | Définit schéma des tables : media_drop_depots, media_drop_mime_mapping, media_drop_uploads |
| 9 | `media_drop_install()` | *(aucun)* | **void** | Crée mappages MIME par défaut (images/vidéos) lors installation module |

### Fonction préprocessage

| # | Fonction | Paramètres | Retour | Description |
|---|----------|-----------|--------|-------------|
| 10 | `template_preprocess_lightgallery_views_flex_justified()` | `&$variables` (array) | **void** | Prépare variables template lightgallery - Configure attributs, cache, librairies, plugins |

---

## Contrôleurs

### DepotController
📄 Fichier: `src/Controller/DepotController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$database` (Connection), `$entity_type_manager` (EntityTypeManagerInterface) | **void** | Constructeur - Injecte connexion DB et gestionnaire d'entités |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance via DI container |
| 3 | `listDepots()` | *(aucun)* | **array** | Affiche tableau tous dépôts avec infos : nom, répertoire, types média, URL publique, uploads, statut, dates, actions |
| 4 | `formatMediaTypes()` | `$depot` (object) | **string** | Formate affichage types média assignés au dépôt (images, vidéos, répertoire Media Directories) |
| 5 | `checkFileFieldPathsAjax()` | `$request` (Request) | **AjaxResponse** | Endpoint AJAX vérifie si type média a filefield_paths activé - Affiche avertissements/checkbox |
| 6 | `hasFileFieldPathsEnabled()` | `$media_type_id` (string) | **bool** | Vérifie si filefield_paths activé sur type média spécifié |

---

### DraggableFlexGridController
📄 Fichier: `src/Controller/DraggableFlexGridController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$tempstore_factory` (PrivateTempStoreFactory) | **void** | Constructeur - Injecte factory tempstore privée |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance via DI |
| 3 | `saveOrder()` | `$request` (Request) | **JsonResponse** | Endpoint AJAX sauvegarde ordre médias en grille (stocké tempstore) |

---

### ManageMediaController
📄 Fichier: `src/Controller/ManageMediaController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$config_factory` (ConfigFactoryInterface), `$module_handler` (ModuleHandlerInterface), `$entity_type_manager` (EntityTypeManagerInterface), `$extension_list_module` (ModuleExtensionList), `$taxonomy_service` (DirectoryService), `$file_system` (FileSystemInterface), `$form_builder` (FormBuilderInterface), `$media_action_service` (mixed) | **void** | Constructeur - Injecte 8 services pour gestion médias, formulaires, actions |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance avec services injectés |
| 3 | `getEntityTypeManager()` | *(aucun)* | **EntityTypeManagerInterface** | Retourne entity type manager |
| 4 | `managePage()` | `$request` (Request), `$id` (mixed = NULL) | **array** | Affiche page gestion médias avec vue media_drop_manage - Parse arguments contextuels (tid:include_children) |
| 5 | `createView()` | *(aucun)* | **RedirectResponse\|array** | Crée vue media_drop_manage programmatiquement depuis config YAML |
| 6 | `getViewConfig()` | *(aucun)* | **array** | Charge config vue depuis YAML et injecte ID vocabulaire Media Directories |

---

### TaxonomyAutocompleteController
📄 Fichier: `src/Controller/TaxonomyAutocompleteController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entity_type_manager` (EntityTypeManagerInterface) | **void** | Constructeur - Injecte entity type manager |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance via DI |
| 3 | `autocomplete()` | `$request` (Request), `$vocabularies` (string) | **JsonResponse** | Endpoint AJAX autocomplétion termes taxonomie dans formulaires VBO - Format: "term_id\|term_label" |

---

### UploadController
📄 Fichier: `src/Controller/UploadController.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | 12 paramètres injectés : Connection, EntityTypeManagerInterface, FileSystemInterface, FileRepositoryInterface, MimeTypeGuesser, FileUrlGenerator, RequestStack, TimeInterface, ModuleHandlerInterface, DirectoryService (x2), NotificationService, LoggerInterface, MessengerInterface | **void** | Constructeur - Injecte tous les services nécessaires pour gestion uploads, fichiers, notifications |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance avec services injectés du conteneur |
| 3 | `uploadPage()` | `$depot_token` (string) | **array** | Page upload interactive avec dropzone.js - Liste médias uploadés, formulaire sous-dossiers, drag-drop |
| 4 | `ajaxUpload()` | `$depot_token` (string), `$request` (Request) | **JsonResponse** | Endpoint AJAX pour uploader fichiers (simple ou chunked) - Crée entités médias, gère taxonomies, notifie destinataires |
| 5 | `ajaxChunkUpload()` | `$request` (Request) | **array** | Gestionnaire uploads par chunks (pour Dropzone.js) |
| 6 | `ajaxSingleUpload()` | `$request` (Request) | **array** | Gestionnaire uploads simples |
| 7 | `listFolders()` | `$depot_token` (string), `$request` (Request) | **JsonResponse** | Endpoint AJAX récupère liste sous-dossiers utilisateur |
| 8 | `createFolder()` | `$depot_token` (string), `$request` (Request) | **JsonResponse** | Endpoint AJAX crée sous-dossier - Crée aussi terme taxonomie si media_directories activé |
| 9 | `listMedia()` | `$depot_token` (string), `$request` (Request) | **JsonResponse** | Endpoint AJAX récupère liste médias uploadés utilisateur (avec thumbnails) |
| 10 | `deleteMedia()` | `$depot_token` (string), `$media_id` (string) | **JsonResponse** | Endpoint AJAX supprime un média - Vérifie ownership, supprime fichiers physiques |
| 11 | `checkUploadAccess()` | `$depot_token` (string), `$account` (AccountInterface) | **AccessResult** | Vérification accès upload (permission "upload media to depots") |
| 12 | `loadDepotByToken()` | `$token` (string) | **object\|NULL** | Charge dépôt par son token unique avec vérification statut actif |
| 13 | `getMediaTypeForMime()` | `$mime_type` (string), `$depot` (object = NULL) | **string\|NULL** | Détermine type média Drupal basé sur type MIME fichier (via DB ou mappages par défaut) |
| 14 | `getMediaSourceField()` | `$media_type_id` (string) | **string\|NULL** | Récupère nom champ source du type média (ex: field_media_image_value) |
| 15 | `getSessionId()` | *(aucun)* | **string** | Génère ou récupère ID session (anonymes) ou identifie par UID |
| 16 | `getMediaThumbnail()` | `$media` (Media) | **string\|NULL** | Génère URL absolue du thumbnail media en utilisant style image "medium" |
| 17 | `triggerNotification()` | `$depot_token` (string), `$request` (Request) | **JsonResponse** | Déclenche email notification après uploads (batch fichiers dernière minute) |
| 18 | `checkDuplicate()` | `$depot_token` (string), `$request` (Request) | **JsonResponse** | Endpoint AJAX vérifie si fichier existe déjà (nom + taille) |
| 19 | `recreateDeletedDirectories()` | `$depot` (object) | **void** | Récréé répertoires manquants sur filesystem basé sur termes taxonomie Media Directories |
| 20 | `cleanupMissingMedia()` | `$depot` (object), `$depot_dir` (bool = FALSE) | **void** | Supprime entités médias dont fichiers source n'existent plus (nettoyage DB) |
| 21 | `checkDuplicateFile()` | `$destination_uri` (string), `$file_size` (int) | **array** | Vérifie si fichier existe déjà avec même taille (détection doublons) |

---

## Formulaires

### AdminSettingsForm
📄 Fichier: `src/Form/AdminSettingsForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `getEditableConfigNames()` | *(aucun)* | **array** | Retourne noms configs éditables |
| 2 | `getFormId()` | *(aucun)* | **string** | Retourne ID unique du formulaire |
| 3 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface) | **array** | Formulaire config générale : taille max, extensions autorisées, prévisualisation, sécurité, durée token |
| 4 | `validateForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Valide champs du formulaire |
| 5 | `submitForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Enregistre config modifiée |

---

### DepotDeleteForm
📄 Fichier: `src/Form/DepotDeleteForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$database` (Connection) | **void** | Constructeur - Injecte connexion DB |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance via DI |
| 3 | `getFormId()` | *(aucun)* | **string** | Retourne ID du formulaire |
| 4 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface), `$depot_id` (mixed = NULL) | **array\|RedirectResponse** | Formulaire confirmation suppression dépôt - Affiche nombre médias associés |
| 5 | `getQuestion()` | *(aucun)* | **string\|TranslatableMarkup** | Retourne question confirmation |
| 6 | `getDescription()` | *(aucun)* | **string\|TranslatableMarkup** | Retourne description confirmation |
| 7 | `getCancelUrl()` | *(aucun)* | **Url** | Retourne URL annulation |
| 8 | `submitForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Supprime enregistrements upload et dépôt de DB (pas fichiers physiques) |

---

### DepotForm
📄 Fichier: `src/Form/DepotForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | 12 paramètres : Connection, EntityTypeManagerInterface, ConfigFactoryInterface, ModuleExtensionList, RequestStack, TimeInterface, DirectoryService (x2), ModuleHandlerInterface, RendererInterface, LoggerInterface, FileSystemInterface | **void** | Constructeur - Injecte 12 services pour gestion dépôts, fichiers, configurations |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance avec services injectés |
| 3 | `getEntityTypeManager()` | *(aucun)* | **EntityTypeManagerInterface** | Retourne entity type manager |
| 4 | `getFormId()` | *(aucun)* | **string** | Retourne ID unique du formulaire |
| 5 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface), `$depot_id` (mixed = NULL) | **array** | Formulaire complets créer/éditer dépôt : nom, types média (images/vidéos) avec avertissements filefield_paths, sélection répertoire (jstree ou textuel), config stockage (public/private), URL partage + token, notifications email (rôles + email supplémentaires) |
| 6 | `validateForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Valide champs du formulaire |
| 7 | `submitForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Enregistre/met à jour dépôt en DB |

---

### MimeMappingForm
📄 Fichier: `src/Form/MimeMappingForm.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$database` (Connection), `$entity_type_manager` (EntityTypeManagerInterface) | **void** | Constructeur - Injecte DB et entity type manager |
| 2 | `create()` | `$container` (ContainerInterface) | **static** | Factory method statique - Crée instance via DI |
| 3 | `getFormId()` | *(aucun)* | **string** | Retourne ID du formulaire |
| 4 | `buildForm()` | `$form` (array), `$form_state` (FormStateInterface) | **array** | Tableau draggable pour éditer mappages MIME type → Types médias Drupal |
| 5 | `validateForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Valide champs du formulaire |
| 6 | `submitForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Sauvegarde mappages modifiés, supprimés, ou nouveaux |

---

## Services

### NotificationService
📄 Fichier: `src/Service/NotificationService.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entity_type_manager` (EntityTypeManagerInterface), `$mail_manager` (MailManagerInterface), `$language_manager` (LanguageManagerInterface) | **void** | Constructeur - Injecte entity type manager, mail manager, language manager |
| 2 | `notifyUploadBatch()` | `$user_name` (string), `$depot` (object), `$uploaded_files` (array) | **void** | Envoie email notification batch tous destinataires (rôles + email supplémentaire) avec liste fichiers uploadés |
| 3 | `notifyUpload()` | `$depot` (object), `$filename` (string), `$user_name` (string), `$media` (Media = NULL) | **void** | [DÉPRÉCIÉE] Encapsule notifyUploadBatch() pour un seul fichier |
| 4 | `getNotificationRecipients()` | `$depot` (object) | **array** | Récupère liste adresses email à notifier (rôles utilisateur + email supplémentaire) |

---

### TaxonomyService / DirectoryService
📄 Fichier: `src/Service/TaxonomyService.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entity_type_manager` (EntityTypeManagerInterface), `$logger` (LoggerInterface) | **void** | Constructeur - Injecte entity type manager et logger |
| 2 | `getMediaDirectoriesVocabulary()` | *(aucun)* | **string\|NULL** | Récupère ID vocabulaire pour media_directories depuis config |
| 3 | `ensureDirectoryTerm()` | `$depot_id` (int), `$user_folder_name` (string), `$subfolder_name` (string = NULL) | **int\|NULL** | Crée ou récupère terme taxonomie pour dossier utilisateur/sous-dossier du dépôt |
| 4 | `getOrCreateTerm()` | `$vocabulary_id` (string), `$term_name` (string), `$parent_tid` (int = 0) | **int\|NULL** | Crée ou retrouve terme taxonomie |
| 5 | `createDepotDirectoryStructure()` | `$depot_id` (int), `$depot_name` (string) | **int\|NULL** | Crée structure termes pour nouveau dépôt |
| 6 | `cleanupEmptyTerms()` | `$vocabulary_id` (string) | **void** | Supprime termes taxonomie sans aucun média associé |
| 7 | `moveMediaFilesToDirectory()` | `$media` (MediaInterface), `$new_term_id` (int = NULL) | **bool** | Déplace fichiers physiques média vers répertoire correspondant au terme taxonomie |
| 8 | `buildDirectoryPathFromTerm()` | `$term_id` (int = NULL) | **string** | Construit chemin physique basé sur terme taxonomie (parcourt arborescence) |

---

## Traits et Utilitaires

### MediaFieldFilterTrait
📄 Fichier: `src/Traits/MediaFieldFilterTrait.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `getFilterableCustomFields()` | `$bundle` (string) | **array** | Retourne champs personnalisés filtrables pour bundle média (exclut fichiers, EXIF, dimensions sauf exceptions) |
| 2 | `shouldIncludeField()` | `$field_definition` (object) | **bool** | Vérifie si champ doit être inclus |
| 3 | `isSpecialIncludedField()` | `$field_name` (string) | **bool** | Vérifie si champ est spécialement inclus |
| 4 | `getSpecialIncludedFields()` | *(aucun)* | **array** | Retourne champs toujours inclus : *_alt_text, *_title, *_description, *_author |
| 5 | `isExcludedFieldType()` | `$field_type` (string) | **bool** | Vérifie si type champ est exclu |
| 6 | `getExcludedFieldTypes()` | *(aucun)* | **array** | Types exclus : image, file, video_file, audio_file, document |
| 7 | `isExcludedFieldName()` | `$field_name` (string) | **bool** | Vérifie si nom champ matches pattern exclusion |
| 8 | `getExcludedFieldNamePatterns()` | *(aucun)* | **array** | Patterns exclus : field_exif_* (prefix), field_width, field_height |
| 9 | `getEntityTypeManager()` | *(aucun)* | **EntityTypeManagerInterface** | Retourne entity type manager (abstract - à implémenter) |

---

### MediaViewsCleanupTrait
📄 Fichier: `src/Traits/MediaViewsCleanupTrait.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `filterMediaResults()` | `$view` (ViewExecutable) | **void** | Filtre résultats Views : supprime médias dont fichier source n'existe plus - Met à jour view->result et view->total_rows |

---

### FieldConfigHelper
📄 Fichier: `src/Utility/FieldConfigHelper.php`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$entity_type_manager` (EntityTypeManagerInterface) | **void** | Constructeur - Injecte entity type manager |
| 2 | `getMediaFieldsOfBundle()` | `$bundle` (string) | **array** | Récupère tous champs référence média pour bundle nœud |
| 3 | `getFieldProperty()` | `$field_config` (object), `$property` (string), `$default` (mixed = NULL) | **mixed** | Récupère propriété spécifique champ |
| 4 | `getAllFieldProperties()` | `$field_config` (object) | **array** | Retourne toutes propriétés config champ (nom, type, label, description, requis, caché, etc.) |
| 5 | `getFieldStorage()` | `$entity_type` (string), `$field_name` (string) | **object\|null** | Récupère storage config champ |
| 6 | `getFieldCardinality()` | `$field_storage` (object) | **int** | Récupère cardinalité champ |
| 7 | `getSelectFieldOptions()` | `$field_storage` (object) | **array** | Récupère options champ select |
| 8 | `getTargetType()` | `$field_config` (object) | **string\|null** | Récupère type cible (entity_reference) |
| 9 | `getEntitiesForReference()` | `$entity_type` (string), `$limit` (int = 50) | **array** | Récupère entités pour référence |
| 10 | `getEntityReferenceOptions()` | `$entity_type` (string), `$include_empty` (bool = TRUE), `$limit` (int = 50) | **array** | Options formatées pour widgets référence entités (ID => Label) |
| 11 | `addMediaToNode()` | `$media` (MediaInterface), `$node` (NodeInterface), `$field_name` (string = NULL) | **bool** | Ajoute média à nœud dans premier champ référence disponible (ou spécifique) |
| 12 | `applyFieldValuesToMedia()` | `$media` (MediaInterface), `$field_values` (array) | **bool** | Applique valeurs champs à entité média et la sauvegarde |

---

## Plugins Views

### DirectoryArgument
📄 Fichier: `src/Plugin/views/argument/DirectoryArgument.php`

**Annotation**: `@ViewsArgument("media_directory_argument")`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `summaryName()` | `$data` (object) | **string** | Retourne label du sommaire pour argument |
| 2 | `title()` | *(aucun)* | **string\|TranslatableMarkup** | Retourne titre argument |
| 3 | `parseArgument()` | `$raw` (string = NULL) | **array** | Parse format "TID" ou "TID:1" (1=incl. enfants) depuis URL |
| 4 | `query()` | `$group_by` (bool = FALSE) | **void** | Ajoute condition WHERE filtrer par TID(s) dans requête Views |
| 5 | `defineOptions()` | *(aucun)* | **array** | Définit options par défaut argument |
| 6 | `buildOptionsForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Construit formulaire configuration argument |
| 7 | `setArgument()` | `$arg` (string) | **bool** | Définit la valeur argument |

---

### MediaInfoField
📄 Fichier: `src/Plugin/views/field/MediaInfoField.php`

**Annotation**: `@ViewsField("media_drop_media_info")`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `__construct()` | `$configuration` (array), `$plugin_id` (string), `$plugin_definition` (mixed), `$entity_type_manager` (EntityTypeManagerInterface) | **void** | Constructeur - Injecte entity type manager |
| 2 | `create()` | `$container` (ContainerInterface), `$configuration` (array), `$plugin_id` (string), `$plugin_definition` (mixed) | **static** | Factory method statique - Crée instance via DI |
| 3 | `getEntityTypeManager()` | *(aucun)* | **EntityTypeManagerInterface** | Retourne entity type manager |
| 4 | `usesGroupBy()` | *(aucun)* | **bool** | Indique si champ utilise grouping |
| 5 | `query()` | *(aucun)* | **void** | Ajoute seulement mid et bundle à requête Views |
| 6 | `render()` | `$values` (ResultRow) | **array** | Affiche champs filtrables personnalisés média sous forme liste définitions (dt/dd) |
| 7 | `formatOutput()` | `$output` (array) | **array** | Formate output rendu |
| 8 | `getFieldValue()` | `$media` (Media), `$field_name` (string), `$field_type` (string) | **string\|null** | Récupère valeur formatée champ selon type (string, text, boolean, entity_reference, timestamp, etc.) |

---

### DirectoryFilter
📄 Fichier: `src/Plugin/views/filter/DirectoryFilter.php`

**Annotation**: `@ViewsFilter("media_directory_filter")`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `canExpose()` | *(aucun)* | **bool** | Indique si filtre peut être exposé |
| 2 | `isExposed()` | *(aucun)* | **bool** | Indique si filtre est exposé |
| 3 | `defineOptions()` | *(aucun)* | **array** | Définit options par défaut filtre |
| 4 | `adminSummary()` | *(aucun)* | **string\|TranslatableMarkup** | Retourne sommaire d'administration |
| 5 | `valueForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Affiche select avec termes taxonomie pour choisir répertoire |
| 6 | `validateExposed()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Valide filtre exposé |
| 7 | `buildExposedForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Construit formulaire exposé filtre |
| 8 | `getDirectoryArgument()` | *(aucun)* | **ArgumentPluginBase\|null** | Récupère l'argument répertoire si présent |
| 9 | `acceptExposedInput()` | `$input` (array) | **bool** | Accepte input exposé |
| 10 | `query()` | *(aucun)* | **void** | Ajoute condition WHERE filtrer médias par TID(s) - Gère argument contextuel vs. formulaire exposé |
| 11 | `parseArgument()` | `$raw` (string = NULL) | **array** | Parse argument format TID ou TID:include_children |
| 12 | `getGroupingConfig()` | *(aucun)* | **array** | Retourne config groupage par répertoire pour style plugin |

---

### IncludeChildrenFilter
📄 Fichier: `src/Plugin/views/filter/IncludeChildrenFilter.php`

**Annotation**: `@ViewsFilter("include_children_filter")`

| # | Méthode | Paramètres | Retour | Description |
|---|---------|-----------|--------|-------------|
| 1 | `defineOptions()` | *(aucun)* | **array** | Définit options par défaut filtre |
| 2 | `adminSummary()` | *(aucun)* | **string\|TranslatableMarkup** | Retourne sommaire d'administration |
| 3 | `valueForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Select pour choisir inclure/exclure sous-répertoires + checkbox afficher sous-repos |
| 4 | `validateExposed()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Valide filtre exposé |
| 5 | `acceptExposedInput()` | `$input` (array) | **bool** | Accepte input exposé |
| 6 | `buildExposedForm()` | `&$form` (array), `$form_state` (FormStateInterface) | **void** | Construit formulaire exposé filtre |
| 7 | `getDirectoryArgument()` | *(aucun)* | **ArgumentPluginBase\|null** | Récupère l'argument répertoire si présent |
| 8 | `query()` | *(aucun)* | **void** | Pas de condition SQL directe (lue par DirectoryFilter) |

---

## 📊 Résumé statistique

| Catégorie | Total |
|-----------|-------|
| **Hooks Drupal** | 7 |
| **Hooks d'installation** | 2 |
| **Préprocessage** | 1 |
| **Contrôleurs** | 5 × 3-21 méthodes |
| **Formulaires** | 4 × 5-7 méthodes |
| **Services** | 2 × 4-8 méthodes |
| **Traits** | 2 × 1-9 méthodes |
| **Utilitaires** | 1 × 12 méthodes |
| **Plugins Views** | 4 × 7-12 méthodes |
| **Total fichiers PHP** | 15 |
| **Total fonctions/méthodes** | **150+** |
| **Endpoints AJAX exposés** | 8 |

---

## 🔐 Permissions

Le module définit les permissions suivantes :
- `upload media to depots` - Accès interface upload
- `view own uploaded media` - Afficher ses propres uploads
- `manage media` - Gestion complète médias via vue
- `create depot folders` - Créer sous-dossiers
- `delete own uploaded media` - Supprimer ses uploads

---

## 🎯 Architecture du module

Ce module fournit un système complet de **dépôt utilisateur** pour uploads médias Drupal 10+ avec :

1. **Interface d'upload** (UploadController) - Drag-drop Dropzone.js, uploads chunks/simples, gestion dossiers utilisateur
2. **Gestion administrative** (DepotController, DepotForm) - CRUD dépôts, config types média, mappages MIME
3. **Vue panoramique** (ManageMediaController) - Gestion médias avec Vue Views, taxonomie Media Directories
4. **Notifications** (NotificationService) - Emails batch après uploads à destinataires configurés
5. **Intégration Views** - Plugins Views (Argument, Filter x2, Field) avec regroupement par répertoire taxonomique

---

## 🔧 Comment utiliser ce document

1. **Localiser un endpoint AJAX**: Cherchez dans UploadController ou DraggableFlexGridController
2. **Configurer dépôts**: Consultez DepotForm et DepotController
3. **Gérer médias**: Consultez ManageMediaController et NotificationService
4. **Filtrer Views**: Consultez DirectoryFilter, IncludeChildrenFilter, DirectoryArgument
5. **Personnaliser uploads**: Consultez UploadController::ajaxUpload() et services TaxonomyService

---

**Dernière mise à jour**: 9 avril 2026
