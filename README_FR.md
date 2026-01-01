# Media Drop

Module Drupal 10/11 permettant aux utilisateurs de déposer des photos et vidéos en masse afin d'être aisément intégrés aux albums par les modérateurs.

---

**English version** : See [README.md](README.md)

## Fonctionnalités

### Espace de dépôt (pour les utilisateurs)

- **Création d'espaces de dépôt** : Création d'espaces dédiés avec répertoires et URLs uniques
- **Permissions granulaires** : Contrôle des actions possibles (upload, création de dossiers, suppression)
- **Upload en masse (Dropzone)** : Interface drag & drop pour déposer plusieurs fichiers simultanément
- **Organisation personnelle** : Chaque utilisateur a son propre répertoire dans l'espace de dépôt
- **Sous-dossiers** : Possibilité de créer des sous-dossiers (ex: matin, aprem, soirée) sur 1 niveau
- **Visualisation et suppression** : Les utilisateurs voient et peuvent supprimer leurs propres médias
- **Support utilisateurs anonymes** : Les anonymes peuvent déposer en indiquant leur nom

### Gestion des médias (pour les modérateurs)

- **Interface de mise à jour des albums** : Gérez les albums et les médias depuis une interface centralisée
- **Déplacement en masse** : Déplacez (move, pas copy) plusieurs médias vers un autre répertoire en une seule action
- **Intégration à un album** : Déplacez et intégrez des médias à un album existant avec :
  - Configuration des champs de métadonnées (titre, alt, description)
  - Affectation de valeurs aux champs pour l'ensemble des médias sélectionnés
  - Gestion des champs de taxonomie avec création automatique de termes
- **Prévention des doublons** : N'ajoute pas deux fois le même média dans un album
- **Gestion des conflits** : Ignore automatiquement les médias déjà présents dans l'album

### Configuration générale

- **Sélection des types de médias** : Choisissez quels types de médias Drupal créer pour les images et vidéos uploadées
- **Organisation dans Media Browser** : Définissez où seront classés les médias dans le Media Browser
- **Mapping MIME automatique** : Configure les types de médias Drupal créés selon les types MIME (fallback si pas spécifié)
- **Intégration Media Directories** : Support optionnel pour organiser les médias dans une taxonomie hiérarchique
- **Gestion des permissions** : 6 permissions différentes pour contrôler l'accès et les actions

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
- **Les dossiers utilisateurs** (ex: "robert.dupont", "marie.martin")
- **Les sous-dossiers créés** (ex: "matin", "aprem", "soirée")

**Exemple de hiérarchie créée :**
```
Albums/
└── Anniversaire 2025/           ← Terme de l'album
    ├── robert.dupont/          ← Créé automatiquement
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

1. **Move to album** : Ajoute les médias sélectionnés à un album avec :
   - Sélection de l'album en étape 1
   - Configuration optionnelle des champs de média en étape 2
   - Application automatique des valeurs à tous les médias sélectionnés
   - Gestion des champs de taxonomie avec création automatique de termes

2. **Éditer les médias (groupés)** : Édite plusieurs médias simultanément avec :
   - Affichage de tous les champs configurables
   - Regroupement des médias ayant les mêmes valeurs
   - Résumé visuel des valeurs communes vs multiples
   - Modification sélective champ par champ

3. **Supprimer** : Supprime les médias sélectionnés (avec confirmation)

#### Fonctionnalité "Move to album"

L'action "Move to album" propose une interface en deux étapes :

**Étape 1 : Sélection de l'album**
- Sélectionnez l'album de destination
- Le bouton "Appliquer" se active uniquement une fois l'album choisi
- Affichage du répertoire de destination (si Media Directories est activé)

**Étape 2 : Configuration optionnelle**
Après sélection de l'album, vous pouvez :

- **Déplacer vers un répertoire** : Optionnel, choisissez le dossier de destination
- **Champs de média** : Modifiez les propriétés des médias (par ex: mot-clé, catégorie)
- **Métadonnées** : Définissez titre, texte alternatif (alt), description

**Exemple :**
```
Sélectionner un album : Anniversaire 2025
Répertoire : Albums/Anniversaire/archives
Champs de média :
  - Mot-clé : "2025"
  - Catégorie : "Événement privé"
Métadonnées :
  - Titre : "Festivités"
  - Alt : "Photos de l'anniversaire"
```

**Gestion des champs de taxonomie** :
- Les champs de catégorie, mot-clé, étiquette supportent plusieurs formats d'entrée :
  - Format autocomplete : `123|Nom du terme`
  - ID numérique seul : `123`
  - Label texte : `Nouveau terme`
- Si le terme n'existe pas, il est créé automatiquement dans le vocabulaire approprié
- Les nouveaux termes reçoivent les permissions nécessaires

#### Exemple d'édition groupée

Lorsque vous sélectionnez 50 médias et choisissez l'action d'édition :

**Résumé automatique :**
- Type de média : Image (50 médias)

**Par champ :**
- **Répertoire** : Valeurs multiples
  - "Albums/Anniversaire/robert.dupont/matin" : 20 médias
  - "Albums/Anniversaire/robert.dupont/aprem" : 30 médias
- **Auteur** : Valeur commune : "Olivier Dupont" (50 médias)
- **Description** : (vide) : 50 médias

Vous pouvez alors choisir de modifier seulement certains champs, par exemple :
- ☑ Modifier le répertoire → Déplacer tous vers "Albums/Anniversaire/archives"
- ☑ Modifier la description → Ajouter "Photos événement 2025"
- ☐ Ne pas modifier l'auteur (valeur commune conservée)

## Gestion avancée des médias

### Édition en masse avec l'action "Move to album"

L'action "Move to album" disponible dans les vues bulk operations permet d'ajouter des médias à un album et de configurer leurs propriétés en deux étapes :

**Étape 1 - Sélection de l'album**
- Sélectionnez l'album de destination
- Le bouton "Appliquer" est désactivé jusqu'à la sélection d'un album
- Validation automatique de la compatibilité des types de médias

**Étape 2 - Configuration (optionnelle)**
Une fois l'album sélectionné, configurez :

1. **Répertoire de destination**
   - Sélection parmi les répertoires existants
   - Affichage de la hiérarchie des dossiers
   - Les répertoires actuellement utilisés sont marqués d'une étoile (★)

2. **Champs de média**
   - Modifiez les propriétés métier (catégorie, mot-clé, étiquette, etc.)
   - Les champs sont regroupés par type de média
   - Les champs de taxonomie supportent la création automatique de termes

3. **Métadonnées**
   - Titre : Appliqué aux champs titre des images et fichiers
   - Alt : Appliqué au texte alternatif des images
   - Description : Appliqué aux vidéos et fichiers

### Formats supportés pour les champs de taxonomie

Lors de la modification de champs de taxonomie (catégorie, mot-clé, etc.), trois formats sont acceptés :

| Format | Exemple | Comportement |
|--------|---------|------------|
| **Autocomplete** | `123\|Mon terme` | Extrait l'ID (123) et l'utilise directement |
| **ID numérique** | `123` | Utilise l'ID existant du terme |
| **Label texte** | `Mon nouveau terme` | Cherche le terme existant ou le crée automatiquement |

**Exemples d'utilisation :**

```
Champ "Catégorie" (taxonomie):
- Saisir "456|Événement important" → Applique le terme ID 456
- Saisir "789" → Applique le terme ID 789
- Saisir "Nouvelle catégorie" → Crée ou récupère le terme "Nouvelle catégorie"
```

**Création automatique de termes**

Quand vous saisissez un label texte et que le terme n'existe pas :
- Le terme est créé dans le vocabulaire approprié
- Le terme est automatiquement assigné aux médias
- Les permissions d'accès sont configurées correctement

**Avantages**
- Flexibilité maximale : ID, autocomplete ou simple label
- Pas de risque de doublon : recherche avant création
- Économie de temps : pas besoin de pré-créer tous les termes
- Cohérence : les termes créés respectent la structure taxonomique

## Configuration avancée

### Modification des champs editables

Les champs éditables en masse sont automatiquement détectés :
- Champs personnalisés (excluant image, fichier, vidéo)
- Champs de taxonomie
- Champs de référence d'entités
- Excluent les champs EXIF

Pour modifier la liste, éditez la configuration dans le formulaire d'album.

### Gestion des vocabulaires multiples

Si un champ de taxonomie cible plusieurs vocabulaires :
- Le premier vocabulaire est utilisé par défaut pour la création de termes
- Vous pouvez spécifier le vocabulaire en utilisant le format `vocab_id:term_label`

## Configuration des permissions

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
    ├── robert.dupont/
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

## Historique des modifications

### Version actuelle

**Améliorations récentes :**

- ✨ **Action "Move to album"** : Interface en deux étapes pour ajouter des médias à un album avec configuration optionnelle
- 🏷️ **Création automatique de termes** : Les champs de taxonomie créent automatiquement les termes inexistants
- 🔄 **Multiples formats acceptés** : Support du format `id|label` (autocomplete), ID numérique seul, et label texte pour les taxonomies
- 📋 **Métadonnées éditables** : Titre, alt, description peuvent être appliqués à tous les médias sélectionnés
- 🎯 **Champs de média regroupés** : Les champs sont intelligemment regroupés par type et désignation
- ⭐ **Indicateurs visuels** : Répertoires utilisés marqués d'une étoile dans la sélection
- ⚠️ **Messages d'avertissement** : Résumés détaillés des médias traités vs non-traités
- ✓ **Validation de compatibilité** : Vérification automatique de la compatibilité des types de médias avec l'album

**Correction de bugs :**

- Gestion correcte des formats autocomplete `term_id|term_label` pour les champs de taxonomie
- État du formulaire correctement géré (bouton Appliquer inactif jusqu'à sélection d'album)
- Support des vocabulaires avec restrictions de bundle
- Gestion des médias dupliqués (déjà présents dans l'album)

**Améliorations techniques :**

- Méthode `findOrCreateTaxonomyTerm()` pour gestion unifiée des termes de taxonomie
- Méthode `applyFieldValuesToMedia()` pour application des valeurs de configuration
- Détection automatique des champs éditables par type de médias
- Groupement des champs par désignation (type + label + vocabulaire)
- Logging détaillé pour le débogage et l'audit

## Licence

GPL-2.0+

## Auteur

Développé pour Drupal 10/11
