# Media Drop

Module Drupal 10/11 permettant aux utilisateurs de déposer des photos et vidéos en masse dans des albums organisés.

## Fonctionnalités

- **Gestion d'albums** : Créez des albums avec des répertoires dédiés et des URL uniques
- **Sélection des types de médias** : Choisissez quels types de médias Drupal créer pour les images et vidéos uploadées
- **Organisation dans Media Browser** : Définissez où seront classés les médias dans le Media Browser
- **Déplacement en masse** : Déplacez (move, pas copy) plusieurs médias vers un autre répertoire en une seule action
- **Upload en masse** : Interface Dropzone pour déposer plusieurs fichiers simultanément
- **Organisation personnelle** : Chaque utilisateur a son propre répertoire dans l'album
- **Sous-dossiers** : Possibilité de créer des sous-dossiers (ex: matin, aprem, soirée)
- **Mapping MIME automatique** : Configure les types de médias Drupal créés selon les types MIME (fallback si pas spécifié dans l'album)
- **Gestion des permissions** : Contrôle granulaire avec 5 permissions différentes
- **Support utilisateurs anonymes** : Les anonymes peuvent déposer en indiquant leur nom
- **Visualisation et suppression** : Les utilisateurs voient et peuvent supprimer leurs propres médias

## Installation

1. Placez le module dans `/modules/custom/media_drop/`
2. Activez le module : `drush en media_drop`
3. **(Recommandé)** Installez Views Bulk Operations pour les opérations en masse :
   ```bash
   composer require drupal/views_bulk_operations
   drush en views_bulk_operations -y
   ```
4. **(Optionnel)** Si vous utilisez le module [Media Directories](https://www.drupal.org/project/media_directories), assurez-vous qu'il est activé et configuré avant de créer vos albums
5. Configurez les permissions dans `/admin/people/permissions`
6. Accédez à la configuration : `/admin/config/media/media-drop`

## Structure des fichiers

```
media_drop/
├── media_drop.info.yml
├── media_drop.permissions.yml
├── media_drop.routing.yml
├── media_drop.links.menu.yml          # Liens dans le menu d'administration
├── media_drop.links.action.yml        # Boutons d'action
├── media_drop.links.task.yml          # Onglets de navigation
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

### Accès à l'administration

Plusieurs chemins permettent d'accéder à l'administration du module :

**Via le menu d'administration :**
- Administration > Configuration > Média > **Media Drop**

**Chemins directs :**
- Configuration générale : `/admin/config/media/media-drop`
- Liste des albums : `/admin/config/media/media-drop/albums`
- Mappings MIME : `/admin/config/media/media-drop/mime-mapping`

**Navigation par onglets :**
Une fois dans l'interface de Media Drop, vous pouvez naviguer entre les sections via les onglets :
- Paramètres
- Albums
- Mappings MIME

```
Administration > Configuration > Média
    └── Media Drop
        ├── [Onglet] Paramètres (/admin/config/media/media-drop)
        │   ├── Configuration générale
        │   └── [Bouton] Gérer les mappings MIME
        │
        ├── [Onglet] Albums (/admin/config/media/media-drop/albums)
        │   ├── Liste des albums
        │   └── [Bouton] Ajouter un album
        │       ├── Créer/Modifier un album
        │       └── Supprimer un album
        │
        └── [Onglet] Mappings MIME (/admin/config/media/media-drop/mime-mapping)
            └── Configuration des types MIME
```

### 1. Créer un album

1. Allez dans **Configuration > Media > Media Drop > Albums**
2. Cliquez sur **"Ajouter un album"**
3. Remplissez :
   - **Nom** : ex. "Anniversaire 2025"

4. **Types de médias** :
   - **Type de média pour les images** : Sélectionnez le type de média Drupal à utiliser pour les images (JPEG, PNG, etc.)
   - **Type de média pour les vidéos** : Sélectionnez le type de média Drupal à utiliser pour les vidéos (MP4, MOV, etc.)
   - Si vous laissez vide, le système utilisera le mapping MIME par défaut
   - Seuls les types de médias acceptant des fichiers image/vidéo sont proposés

5. **Répertoires** :
   - **Répertoire de stockage** : ex. `public://media-drop/anniversaire2025` (où seront physiquement stockés les fichiers)
   - **Répertoire dans Media Browser** :
     - **Si Media Directories est activé** : Sélectionnez un terme de la taxonomie configurée, ou créez-en un nouveau. Les médias seront automatiquement classés dans ce répertoire virtuel.
     - **Si Media Directories n'est pas activé** : Saisissez un chemin texte (ex: `albums/anniversaire2025`)

6. **Statut** : Actif
7. Une URL unique sera générée (ex: `/media-drop/abc123xyz`)

### Intégration avec Media Directories

Si vous avez le module [Media Directories](https://www.drupal.org/project/media_directories) activé :

1. Le formulaire d'album affichera automatiquement un sélecteur de termes de taxonomie
2. Vous pouvez choisir un répertoire existant ou en créer un nouveau directement depuis le formulaire
3. Les médias uploadés seront automatiquement assignés à ce répertoire
4. Cela permet une organisation cohérente avec votre structure Media Directories existante

**Avantages** :
- Organisation hiérarchique des médias
- Filtrage facile dans le Media Browser
- Cohérence avec votre structure de médias existante

## Déplacement en masse des médias

### Via l'interface d'administration

1. Allez dans **Configuration > Media > Media Drop > Gérer les médias**
2. **Filtrez par répertoire** : Utilisez le filtre "Répertoire" pour voir uniquement les médias d'un dossier spécifique
3. **Filtrez par nom, type, auteur** pour affiner la recherche
4. Cochez les médias à déplacer
5. Dans le menu déroulant "Action", sélectionnez **"Déplacer vers un répertoire"**
6. Cliquez sur "Appliquer aux éléments sélectionnés"
7. Choisissez le répertoire de destination
8. Confirmez le déplacement

**Note importante :** Cette action effectue un **déplacement** (move), pas une copie. Les médias changeront de répertoire.

### Création automatique de la structure de taxonomie

Lorsque **Media Directories est activé**, Media Drop crée automatiquement les termes de taxonomie pour :
- **Le dossier de l'album** (si configuré)
- **Les dossiers utilisateurs** (ex: "olivier.dupont", "marie.martin")
- **Les sous-dossiers créés** (ex: "matin", "aprem", "soirée")

**Exemple de hiérarchie créée :**
```
Albums/
└── Anniversaire 2025/           ← Terme de l'album
    ├── olivier.dupont/          ← Créé automatiquement
    │   ├── matin/               ← Créé automatiquement
    │   ├── aprem/               ← Créé automatiquement
    │   └── soiree/              ← Créé automatiquement
    └── marie.martin/            ← Créé automatiquement
```

**Avantages :**
- Retrouvez facilement tous les médias d'un utilisateur
- Naviguez par dossier dans le Media Browser
- Utilisez le filtre "Répertoire" dans la vue de gestion
- Structure cohérente et automatique

**Configuration :**
Dans le formulaire d'album, cochez "Créer automatiquement la structure" pour activer cette fonctionnalité.

### Filtrage par répertoire

Dans la vue de gestion, le filtre **"Répertoire"** affiche tous les dossiers de la taxonomie Media Directories avec :
- Indentation pour visualiser la hiérarchie
- Tous les dossiers créés automatiquement
- Possibilité de filtrer sur un dossier spécifique

### Via Media Directories (drag & drop)

Si Media Directories est activé :
- Le **drag & drop** effectue par défaut une **copie**
- Utilisez l'action VBO ci-dessus pour un véritable **déplacement**

### Actions disponibles

La vue de gestion propose plusieurs actions en masse :

1. **Déplacer vers un répertoire** (Move) : Change le répertoire du média sans copier
2. **Éditer les médias (groupés)** : Édite plusieurs médias simultanément avec :
   - Affichage de tous les champs configurables
   - Regroupement des médias ayant les mêmes valeurs
   - Résumé visuel des valeurs communes vs multiples
   - Modification sélective champ par champ
3. **Supprimer** : Supprime les médias sélectionnés (avec confirmation)

#### Exemple d'édition groupée

Lorsque vous sélectionnez 50 médias et choisissez "Éditer les médias (groupés)" :

**Résumé automatique :**
- Type de média : Image (50 médias)

**Par champ :**
- **Répertoire** : Valeurs multiples
  - "Albums/Anniversaire/olivier.dupont/matin" : 20 médias
  - "Albums/Anniversaire/olivier.dupont/aprem" : 30 médias
- **Auteur** : Valeur commune : "Olivier Dupont" (50 médias)
- **Description** : (vide) : 50 médias

Vous pouvez alors choisir de modifier seulement certains champs, par exemple :
- ☑ Modifier le répertoire → Déplacer tous vers "Albums/Anniversaire/archives"
- ☑ Modifier la description → Ajouter "Photos événement 2025"
- ☐ Ne pas modifier l'auteur (valeur commune conservée)

### 2. Configurer les mappings MIME

1. Allez dans **Configuration > Media > Media Drop > Types MIME**
2. Les mappings par défaut sont créés automatiquement :
   - `image/jpeg` → type média `image`
   - `video/mp4` → type média `video`
   - etc.
3. Ajoutez des mappings personnalisés si nécessaire
4. Tous les types de médias personnalisés sont disponibles

### 3. Configurer les permissions

Allez dans **Personnes > Permissions** et configurez :

- **Administrer Media Drop** : Gérer albums et configuration (admin uniquement)
- **Déposer des médias dans les albums** : Permettre l'upload
- **Voir ses propres médias** : Voir les médias déposés
- **Supprimer ses propres médias** : Supprimer ses médias
- **Créer des sous-dossiers dans les albums** : Organiser en sous-dossiers

## Utilisation

### Pour l'administrateur

1. Créez un album
2. Copiez l'URL générée (ex: `https://monsite.com/media-drop/abc123xyz`)
3. Partagez cette URL avec les participants
4. Les fichiers seront organisés dans : `[répertoire_base]/[nom_utilisateur]/[sous-dossier]/fichier.jpg`

### Pour l'utilisateur

1. Accédez à l'URL de l'album
2. Indiquez votre nom (obligatoire pour les anonymes)
3. Optionnel : Créez un sous-dossier (ex: "matin", "aprem", "soirée")
4. Glissez-déposez vos photos/vidéos ou cliquez pour sélectionner
5. Les fichiers sont uploadés automatiquement
6. Visualisez vos médias déposés en bas de page
7. Supprimez un média si nécessaire

## Exemple d'organisation des fichiers

```
public://media-drop/
└── anniversaire2025/
    ├── olivier.dupont/
    │   ├── matin/
    │   │   ├── photo1.jpg
    │   │   └── photo2.jpg
    │   ├── aprem/
    │   │   └── video1.mp4
    │   └── soiree/
    │       └── photo3.jpg
    └── marie.martin/
        ├── photo4.jpg
        └── photo5.jpg
```

## Base de données

Le module crée 3 tables :

- **media_drop_albums** : Liste des albums
- **media_drop_mime_mapping** : Mappings MIME → type média
- **media_drop_uploads** : Suivi des uploads par utilisateur/session

## Sécurité

- Les utilisateurs anonymes doivent indiquer leur nom
- Chaque utilisateur ne peut voir/supprimer que ses propres médias
- Les sessions anonymes sont trackées pour isolation
- Les tokens d'albums sont générés de manière sécurisée
- Les noms de fichiers et dossiers sont nettoyés

## Personnalisation

### Modifier les styles CSS

Éditez `/css/media-drop-upload.css` pour personnaliser l'apparence.

### Modifier le comportement JavaScript

Éditez `/js/media-drop-upload.js` pour personnaliser l'interface.

### Ajouter des types de médias personnalisés

1. Créez votre type de média dans Drupal
2. Ajoutez le mapping MIME dans **Configuration > Media Drop > Types MIME**

## Dépannage

### Les fichiers ne s'uploadent pas

- Vérifiez les permissions du répertoire
- Vérifiez que le type MIME est mappé
- Vérifiez les permissions utilisateur
- Consultez les logs Drupal

### Les miniatures ne s'affichent pas

- Vérifiez que le type de média a un champ thumbnail configuré
- Vérifiez les permissions de lecture des fichiers

### L'URL ne fonctionne pas

- Vérifiez que l'album est actif
- Vérifiez que le token est correct
- Videz le cache Drupal

## Support et contribution

Pour signaler un bug ou proposer une amélioration, contactez l'équipe de développement.

## Licence

GPL-2.0+

## Auteur

Développé pour Drupal 10/11
