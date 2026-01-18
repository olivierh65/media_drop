// media-album-editor-widget.js
(function($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.mediaAlbumEditorWidget = {
    attach: function(context, settings) {
      $('.media-album-editor-widget', context).once('mediaAlbumEditorWidget').each(function() {
        var $widget = $(this);
        var $selectedField = $widget.find('.selected-media-ids-field');
        var fieldName = $selectedField.data('field-name');
        var selectedIds = $selectedField.val() ? $selectedField.val().split(',').map(function(id) {
          return id.trim();
        }) : [];

        // Initialiser les cases à cocher VBO
        initializeVboCheckboxes($widget, selectedIds);

        // Gérer les actions VBO
        handleVboActions($widget, $selectedField, selectedIds);

        // Ajouter un compteur dynamique
        updateSelectionCounter($widget, selectedIds.length);
      });
    }
  };

  function initializeVboCheckboxes($widget, selectedIds) {
    $widget.find('.vbo-select').each(function() {
      var $checkbox = $(this);
      var entityId = $checkbox.val();

      // Cocher si déjà sélectionné
      if (selectedIds.indexOf(entityId) !== -1) {
        $checkbox.prop('checked', true).trigger('change');
      }

      // Écouter les changements
      $checkbox.on('change', function() {
        var id = $(this).val();
        var isChecked = $(this).is(':checked');
        var index = selectedIds.indexOf(id);

        if (isChecked && index === -1) {
          selectedIds.push(id);
        } else if (!isChecked && index !== -1) {
          selectedIds.splice(index, 1);
        }

        // Mettre à jour le champ caché
        $widget.find('.selected-media-ids-field').val(selectedIds.join(','));
        updateSelectionCounter($widget, selectedIds.length);
      });
    });
  }

  function handleVboActions($widget, $selectedField, selectedIds) {
    $widget.find('.vbo-action-button').on('click', function(e) {
      e.preventDefault();

      var $form = $(this).closest('form');
      var action = $(this).data('action') || $(this).val();

      if (action === 'select_media_action') {
        // Ajouter les éléments cochés à la sélection
        $widget.find('.vbo-select:checked').each(function() {
          var id = $(this).val();
          if (selectedIds.indexOf(id) === -1) {
            selectedIds.push(id);
          }
        });

        $selectedField.val(selectedIds.join(','));
        updateSelectionCounter($widget, selectedIds.length);
      }
    });
  }

  function updateSelectionCounter($widget, count) {
    var $counter = $widget.find('.selection-counter');
    if ($counter.length === 0) {
      $counter = $('<div class="selection-counter messages messages--status"></div>');
      $widget.find('.media-album-editor-instructions').after($counter);
    }

    $counter.html('<strong>' + Drupal.t('@count media selected', {'@count': count}) + '</strong>');
  }

})(jQuery, Drupal, drupalSettings);
