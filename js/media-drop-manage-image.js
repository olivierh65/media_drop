(function($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.imageModal = {
    attach: function(context, settings) {
      if (typeof this.modalInitialized === 'undefined') {
        this.modalInitialized = false;
      }

      if (!this.modalInitialized && $('.zoom-trigger').length) {
        this.initModal();
        this.modalInitialized = true;
      }
    },

    initModal: function() {
      const self = this;

      // Créer la modale si elle n'existe pas
      if ($('#image-modal').length === 0) {
        this.createModal();
      }

      const $modal = $('#image-modal');

      // Collecter toutes les images AVEC leur taille
      this.images = [];
      $('.draggable-flexgrid__item').each(function(index) {
        const $item = $(this);
        const $trigger = $item.find('.zoom-trigger');
        const $img = $item.find('img');
        const $fullImage = $item.find('.views-field-thumbnail__target-id');

        if ($trigger.length) {
          // Récupérer la taille de l'image depuis data-image-size
          let width = 0, height = 0;
          const sizeData = $fullImage.data('image-size');
          if (sizeData) {
            const sizeParts = sizeData.split('x');
            if (sizeParts.length === 2) {
              width = parseInt(sizeParts[0]) || 0;
              height = parseInt(sizeParts[1]) || 0;
            }
          }

          const isValidImage = width > 0 && height > 0;
          const mediaType = $trigger.data('media-type') || 'image';
          const mimeType = $trigger.data('mime-type') || '';

          self.images.push({
            src: $trigger.data('image-src') || $fullImage.data('image-src') || $img.attr('src'),
            alt: $trigger.data('image-alt') || $img.attr('alt') || '',
            width: width,
            height: height,
            isValid: isValidImage,
            index: index,
            mediaType: mediaType,
            mimeType: mimeType
          });

          $item.data('image-index', index);
        }
      });

      // Gestion du clic sur les boutons loupe
      $(document).on('click', '.media-drop-zoom-trigger', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $item = $(this).closest('.draggable-flexgrid__item');
        const index = $item.data('image-index') || 0;

        self.openModal(index);
      });

      // Gestion des touches pour accessibilité
      $(document).on('keydown', '.media-drop-zoom-trigger', function(e) {
        if (e.key === ' ' || e.key === 'Enter') {
          e.preventDefault();

          const $item = $(this).closest('.draggable-flexgrid__item');
          const index = $item.data('image-index') || 0;

          self.openModal(index);
        }
      });

      // Fermer la modale
      $modal.on('click', function(e) {
        if ($(e.target).hasClass('modal-overlay') ||
            $(e.target).hasClass('modal-image')) {
          self.closeModal();
        }
      });

      // Fermer avec le bouton X
      $modal.on('click', '.modal-close', function(e) {
        e.stopPropagation();
        self.closeModal();
      });

      // Fermer avec la touche Échap
      $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $modal.hasClass('active')) {
          self.closeModal();
        }
      });

      // Empêcher la fermeture en cliquant sur le header
      $modal.on('click', '.modal-header', function(e) {
        e.stopPropagation();
      });

      // Gestion du zoom avec molette de souris
      $modal.on('wheel', '.image-container', function(e) {
        if (e.ctrlKey) {
          e.preventDefault();
          const zoomIn = e.originalEvent.deltaY < 0;
          self.zoomImage(zoomIn ? 1.2 : 0.8);
        }
      });

      // Variables pour le drag and drop
      let isDragging = false;
      let startX, startY, scrollLeft, scrollTop;

      // Démarrer le drag
      $modal.on('mousedown', '.image-container', function(e) {
        if (self.currentZoom > 1) {
          isDragging = true;
          startX = e.pageX - $(this).offset().left;
          startY = e.pageY - $(this).offset().top;
          scrollLeft = $(this).scrollLeft();
          scrollTop = $(this).scrollTop();
          $(this).addClass('grabbing');
        }
      });

      // Arrêter le drag
      $modal.on('mouseup', '.image-container', function() {
        isDragging = false;
        $(this).removeClass('grabbing');
      });

      // Déplacer l'image
      $modal.on('mousemove', '.image-container', function(e) {
        if (!isDragging) return;
        e.preventDefault();
        const x = e.pageX - $(this).offset().left;
        const y = e.pageY - $(this).offset().top;
        const walkX = (x - startX) * 2;
        const walkY = (y - startY) * 2;
        $(this).scrollLeft(scrollLeft - walkX);
        $(this).scrollTop(scrollTop - walkY);
      });

      // Recentrer l'image lors du redimensionnement
      $(window).on('resize', function() {
        if ($modal.hasClass('active')) {
          self.adjustModalSize();
        }
      });
    },

    createModal: function() {
      // Créer la modale avec contrôles de zoom pour image et vidéo
      const modalHTML = `
        <div id="image-modal" class="image-modal">
          <div class="modal-overlay"></div>
          <div class="modal-content">
            <div class="modal-header">
              <h3 class="modal-title">${Drupal.t('Media preview')}</h3>
              <button class="modal-close" aria-label="${Drupal.t('Close')}" title="${Drupal.t('Close')}">×</button>
            </div>
            <div class="modal-body">
              <div class="image-container">
                <img class="modal-image" src="" alt="" loading="lazy" style="display:none;"/>
                <video class="modal-video" style="display:none; max-width: 100%; max-height: 100%; object-fit: contain;" controls preload="metadata" playsinline>
                  <source src="" type="">
                  ${Drupal.t('Your browser does not support the video tag.')}
                </video>
              </div>
              <div class="image-loader">
                <div class="spinner"></div>
              </div>
              <div class="zoom-controls">
                <button class="zoom-btn zoom-in" title="${Drupal.t('Zoom in')}">+</button>
                <button class="zoom-btn zoom-out" title="${Drupal.t('Zoom out')}">-</button>
                <button class="zoom-btn zoom-reset" title="${Drupal.t('Reset zoom')}">100%</button>
                <button class="zoom-btn zoom-fit" title="${Drupal.t('Fit to window')}">⎚</button>
              </div>
            </div>
          </div>
        </div>
      `;

      $('body').append(modalHTML);

      // Initialiser le zoom
      this.currentZoom = 1;

      // Gestion des contrôles de zoom
      const $modal = $('#image-modal');
      $modal.on('click', '.zoom-in', (e) => {
        e.stopPropagation();
        this.zoomImage(1.2);
      });

      $modal.on('click', '.zoom-out', (e) => {
        e.stopPropagation();
        this.zoomImage(0.8);
      });

      $modal.on('click', '.zoom-reset', (e) => {
        e.stopPropagation();
        this.resetZoom();
      });

      $modal.on('click', '.zoom-fit', (e) => {
        e.stopPropagation();
        this.fitToWindow();
      });
    },

    adjustModalSize: function() {
      const $modal = $('#image-modal');
      const $modalContent = $modal.find('.modal-content');

      if (!$modal.hasClass('active')) return;

      const image = this.currentImage;
      if (!image || !image.isValid) return;

      // Dimensions de la fenêtre
      const windowWidth = $(window).width();
      const windowHeight = $(window).height();

      // Hauteur maximale : 80% de la fenêtre
      const maxHeight = windowHeight * 0.8;

      // Largeur maximale : 90% de la fenêtre
      const maxWidth = windowWidth * 0.9;

      // Récupérer les dimensions de l'image
      let imgWidth = image.width;
      let imgHeight = image.height;

      if (imgWidth <= 0 || imgHeight <= 0) {
        // Utiliser une taille par défaut
        imgWidth = 800;
        imgHeight = 600;
      }

      // Calculer le ratio de l'image
      const imgRatio = imgWidth / imgHeight;

      // Calculer les dimensions optimales pour voir toute l'image
      let modalWidth, modalHeight;

      // Essayer avec la hauteur maximale
      modalHeight = Math.min(maxHeight, imgHeight);
      modalWidth = modalHeight * imgRatio;

      // Si trop large, ajuster avec la largeur maximale
      if (modalWidth > maxWidth) {
        modalWidth = Math.min(maxWidth, imgWidth);
        modalHeight = modalWidth / imgRatio;
      }

      // Dimensions minimales
      modalWidth = Math.max(modalWidth, 300);
      modalHeight = Math.max(modalHeight, 200);

      // S'assurer que ça ne dépasse pas les limites
      modalWidth = Math.min(modalWidth, maxWidth);
      modalHeight = Math.min(modalHeight, maxHeight);

      // Appliquer les dimensions
      $modalContent.css({
        'width': modalWidth + 'px',
        'height': modalHeight + 'px'
      });

      // Reset zoom lors du redimensionnement
      this.resetZoom();

      console.log('Modal ajustée:', {
        image: `${imgWidth}x${imgHeight}`,
        modal: `${modalWidth}x${modalHeight}`,
        ratio: imgRatio.toFixed(2)
      });
    },

    zoomImage: function(scaleFactor) {
      const $modalImage = $('#image-modal').find('.modal-image');
      const $imageContainer = $('#image-modal').find('.image-container');

      if (!$modalImage.length) return;

      // Calculer le nouveau zoom
      const newZoom = this.currentZoom * scaleFactor;

      // Limiter le zoom entre 20% et 500%
      if (newZoom < 0.2 || newZoom > 5) return;

      this.currentZoom = newZoom;

      // Appliquer le zoom
      $modalImage.css({
        'transform': `scale(${this.currentZoom})`
      });

      // Mettre à jour le bouton reset
      const resetBtn = $('#image-modal').find('.zoom-reset');
      resetBtn.text(Math.round(this.currentZoom * 100) + '%');

      // Ajuster le container pour permettre le scroll si zoomé
      if (this.currentZoom > 1) {
        $imageContainer.css('overflow', 'auto');
      } else {
        $imageContainer.css('overflow', 'hidden');
        // Recentrer l'image si on dé-zoome
        $imageContainer.scrollLeft(0);
        $imageContainer.scrollTop(0);
      }
    },

    resetZoom: function() {
      this.currentZoom = 1;
      const $modalImage = $('#image-modal').find('.modal-image');
      const $imageContainer = $('#image-modal').find('.image-container');

      $modalImage.css('transform', 'scale(1)');
      $imageContainer.css({
        'overflow': 'hidden'
      });
      $imageContainer.scrollLeft(0);
      $imageContainer.scrollTop(0);

      $('#image-modal').find('.zoom-reset').text('100%');
    },

    fitToWindow: function() {
      // Réajuster la taille de la modale pour voir toute l'image
      this.adjustModalSize();
    },

    openModal: function(index) {
      const self = this;
      const $modal = $('#image-modal');
      const $modalImage = $modal.find('.modal-image');
      const $modalVideo = $modal.find('.modal-video');
      const $imageLoader = $modal.find('.image-loader');

      // Vérifier si l'index est valide
      if (index < 0 || index >= this.images.length) {
        console.error('Index de l\'image invalide');
        return;
      }

      const image = this.images[index];
      const isVideo = image.mediaType === 'video';

      // Stocker l'image courante
      this.currentImage = image;

      // Reset zoom
      this.currentZoom = 1;

      // Mettre à jour le titre
      $modal.find('.modal-title').text(image.alt || Drupal.t('Media preview'));

      // Afficher le loader
      $imageLoader.show();
      $modalImage.hide();
      $modalVideo.hide();

      // Afficher la modale
      $modal.addClass('active');
      $modal.find('.modal-overlay').show();
      $('body').css('overflow', 'hidden');

      // Gérer vidéo ou image
      if (isVideo) {
        // Afficher la vidéo
        const videoElement = $modalVideo.get(0);
        const sourceElement = $modalVideo.find('source').get(0);

        // Mettre à jour la source
        sourceElement.src = image.src;
        sourceElement.type = image.mimeType;

        // Charger la vidéo avec les nouvelles sources
        videoElement.load();

        // Afficher la vidéo
        $modalVideo.show();
        $imageLoader.hide();

        // Ajuster la taille et laisser le temps à la vidéo de charger les métadonnées
        setTimeout(() => {
          self.adjustModalSize();
        }, 100);
      } else {
        // Créer une nouvelle image
        const img = new Image();

        img.onload = function() {
          // Vérifier si l'image est réellement chargée
          if (img.naturalWidth === 0 || img.naturalHeight === 0) {
            // Image invalide
            self.showErrorImage();
            $imageLoader.hide();
            return;
          }

          // Mettre à jour les dimensions si elles étaient incorrectes
          if (!image.width || !image.height || image.width <= 0 || image.height <= 0) {
            image.width = img.naturalWidth;
            image.height = img.naturalHeight;
            image.isValid = true;
          }

          $modalImage
            .attr('src', image.src)
            .attr('alt', image.alt)
            .css('transform', 'scale(1)')
            .show();

          // Ajuster la taille de la modale après le chargement
          setTimeout(() => {
            self.adjustModalSize();
          }, 100);

          $imageLoader.hide();
        };

        img.onerror = function() {
          console.error('Erreur de chargement de l\'image:', image.src);
          self.showErrorImage();
          $imageLoader.hide();
        };

        img.src = image.src;
      }
    },

    showErrorImage: function() {
      const $modal = $('#image-modal');
      const $modalImage = $modal.find('.modal-image');
      const $modalContent = $modal.find('.modal-content');

      // Ajuster la taille pour l'erreur
      $modalContent.css({
        'width': '400px',
        'height': '300px'
      });

      $modalImage
        .attr('src', '')
        .attr('alt', Drupal.t('Image not available'))
        .addClass('image-error')
        .show()
        .html(`
          <div style="text-align: center;">
            <div style="font-size: 60px; margin-bottom: 20px; opacity: 0.7;">📷</div>
            <div style="font-size: 18px; margin-bottom: 10px;">${Drupal.t('Image not available')}</div>
            <div style="font-size: 14px; opacity: 0.7;">${Drupal.t('The image could not be loaded')}</div>
          </div>
        `);

      // Reset zoom
      this.resetZoom();
    },

    closeModal: function() {
      const $modal = $('#image-modal');

      // Cacher la modale
      $modal.removeClass('active');
      $modal.find('.modal-overlay').hide();
      $('body').css('overflow', '');

      // Réinitialiser l'image
      const $modalImage = $modal.find('.modal-image');
      $modalImage
        .attr('src', '')
        .attr('alt', '')
        .removeClass('image-error')
        .hide()
        .css('transform', 'scale(1)');

      // Réinitialiser le titre
      $modal.find('.modal-title').text(Drupal.t('Image preview'));

      // Réinitialiser les dimensions de la modale
      $modal.find('.modal-content').css({
        'width': '',
        'height': ''
      });

      // Nettoyer
      delete this.currentImage;
      this.currentZoom = 1;
    }
  };

  // Initialisation au chargement
  $(document).ready(function() {
    Drupal.behaviors.imageModal.attach(document, Drupal.settings);
  });

})(jQuery, Drupal, drupalSettings, once);
