/**
 * @file
 * Media Drop upload interface with Dropzone.
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.mediaDropUpload = {
    attach: function (context, settings) {
      const config = drupalSettings.media_drop || {};
      // Drupal 10 correct use of once()
      const container = once('media-drop-init', '#media-drop-upload-container', context);

      if (container.length === 0) {
        return;
      }

      const $container = $(container[0]);

      let currentFolder = '';
      // Use user name from config, or localStorage for anonymous users
      let userName = config.user_name || localStorage.getItem('media_drop_user_name') || '';

      // Initialize the interface
      this.initInterface($container, config, userName);

      // Initialize Dropzone if the user has permission
      if (config.can_upload) {
        this.initDropzone($container, config);
      }

      // Load existing media if the user has permission
      if (config.can_view) {
        this.loadUserMedia(config);
      }
    },

    initInterface: function($container, config, userName) {
      const self = this;

      // HTML Template
      const html = `
        <div class="media-drop-header">
          <h1>${Drupal.t('Drop your media: @album', {'@album': config.album_name})}</h1>
        </div>

        <div class="media-drop-user-info">
          <label for="user-name-input">${Drupal.t('Your name')} :</label>
          <input type="text" id="user-name-input" value="${userName}"
                 placeholder="${Drupal.t('Enter your name')}" required />
          <button id="save-user-name" class="button">${Drupal.t('Save')}</button>
        </div>

        ${config.can_create_folder ? `
        <div class="media-drop-folder-section">
          <label>${Drupal.t('Organize in a sub-folder (optional)')} :</label>
          <div class="folder-controls">
            <select id="folder-select">
              <option value="">${Drupal.t('-- Main folder --')}</option>
            </select>
            <button id="create-folder" class="button">${Drupal.t('Create folder')}</button>
          </div>
          <div id="new-folder-form" style="display: none;">
            <input type="text" id="new-folder-name" placeholder="${Drupal.t('Folder name')}" />
            <button id="confirm-folder" class="button button--primary">${Drupal.t('Create')}</button>
            <button id="cancel-folder" class="button">${Drupal.t('Cancel')}</button>
          </div>
        </div>
        ` : ''}

        ${config.can_upload ? `
        <div class="media-drop-dropzone">
          <form action="${config.upload_url}" class="dropzone" id="media-dropzone">
            <div class="dz-message">${Drupal.t('Drag and drop your photos and videos here or click to select')}</div>
          </form>
        </div>
        ` : `
        <div class="media-drop-no-permission">
          <p>${Drupal.t('You do not have permission to drop media.')}</p>
        </div>
        `}

        <div class="media-drop-gallery">
          <h2>${Drupal.t('Your dropped media')}</h2>
          <div id="media-gallery" class="media-grid"></div>
        </div>
      `;

      $container.html(html);

      // User name management
      $('#save-user-name', $container).on('click', function() {
        const name = $('#user-name-input', $container).val().trim();
        if (name) {
          localStorage.setItem('media_drop_user_name', name);
          Drupal.announce(Drupal.t('Name saved'));
        }
      });

      // Folder creation management
      if (config.can_create_folder) {
        $('#create-folder', $container).on('click', function() {
          $('#new-folder-form', $container).show();
        });

        $('#cancel-folder', $container).on('click', function() {
          $('#new-folder-form', $container).hide();
          $('#new-folder-name', $container).val('');
        });

        $('#confirm-folder', $container).on('click', function() {
          self.createFolder(config, $container);
        });

        $('#folder-select', $container).on('change', function() {
          currentFolder = $(this).val();
        });
      }
    },

    initDropzone: function($container, config) {
      const self = this;

      Dropzone.autoDiscover = false;

      const dropzone = new Dropzone('#media-dropzone', {
        url: config.upload_url,
        paramName: 'file',
        maxFilesize: 50, // MB
        acceptedFiles: 'image/*,video/*',
        addRemoveLinks: true,
        dictDefaultMessage: Drupal.t('Drag and drop your files here'),
        dictFallbackMessage: Drupal.t('Your browser does not support drag\'n\'drop'),
        dictFileTooBig: Drupal.t('File is too big (max: {{maxFilesize}}MB)'),
        dictInvalidFileType: Drupal.t('Invalid file type'),
        dictRemoveFile: Drupal.t('Remove'),
        dictCancelUpload: Drupal.t('Cancel'),

        sending: function(file, xhr, formData) {
          const userName = $('#user-name-input', $container).val().trim();
          const folder = $('#folder-select', $container).val();

          if (!userName) {
            alert(Drupal.t('Please enter your name before dropping files.'));
            this.removeFile(file);
            return false;
          }

          formData.append('user_name', userName);
          formData.append('subfolder', folder);
        },

        success: function(file, response) {
          if (response.results && response.results[0] && response.results[0].success) {
            Drupal.announce(Drupal.t('File uploaded successfully'));
            self.loadUserMedia(config);
          }
        },

        error: function(file, errorMessage) {
          console.error('Upload error:', errorMessage);
          Drupal.announce(Drupal.t('Error during upload'), 'assertive');
        }
      });
    },

    createFolder: function(config, $container) {
      const userName = $('#user-name-input', $container).val().trim();
      const folderName = $('#new-folder-name', $container).val().trim();

      if (!userName) {
        alert(Drupal.t('Please save your name first.'));
        return;
      }

      if (!folderName) {
        alert(Drupal.t('Please enter a folder name.'));
        return;
      }

      $.ajax({
        url: config.create_folder_url,
        method: 'POST',
        data: {
          user_name: userName,
          folder_name: folderName
        },
        success: function(response) {
          if (response.success) {
            const $select = $('#folder-select', $container);
            $select.append($('<option>', {
              value: response.safe_folder_name,
              text: folderName
            }));
            $select.val(response.safe_folder_name);
            $('#new-folder-form', $container).hide();
            $('#new-folder-name', $container).val('');
            Drupal.announce(Drupal.t('Folder created'));
          }
        },
        error: function(xhr) {
          alert(Drupal.t('Error creating folder'));
        }
      });
    },

    loadUserMedia: function(config) {
      $.ajax({
        url: config.list_media_url,
        method: 'GET',
        success: function(response) {
          if (response.media) {
            const $gallery = $('#media-gallery');
            $gallery.empty();

            if (response.media.length === 0) {
              $gallery.html('<p>' + Drupal.t('No media dropped yet.') + '</p>');
              return;
            }

            response.media.forEach(function(media) {
              const $item = $('<div>', {class: 'media-item'});

              if (media.thumbnail) {
                $item.append($('<img>', {src: media.thumbnail, alt: media.name}));
              }

              $item.append($('<div>', {class: 'media-name', text: media.name}));

              if (media.subfolder) {
                $item.append($('<div>', {class: 'media-folder', text: media.subfolder}));
              }

              if (config.can_delete) {
                const $deleteBtn = $('<button>', {
                  class: 'button button--danger button--small',
                  text: Drupal.t('Delete')
                }).on('click', function() {
                  if (confirm(Drupal.t('Are you sure you want to delete this media?'))) {
                    const deleteUrl = config.delete_media_url.replace('__MEDIA_ID__', media.id);
                    $.ajax({
                      url: deleteUrl,
                      method: 'POST', // POST instead of DELETE for compatibility
                      success: function() {
                        $item.remove();
                        Drupal.announce(Drupal.t('Media deleted'));
                      },
                      error: function(xhr) {
                        alert(Drupal.t('Error during deletion'));
                      }
                    });
                  }
                });
                $item.append($deleteBtn);
              }

              $gallery.append($item);
            });
          }
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);
