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
      // Load existing folders if the user has permission
      if (config.can_create_folder) {
        this.loadUserFolders(config, userName);
      }
    },

    initInterface: function($container, config, userName) {
      const self = this;

      // Pre-fill user name if available
      if (config.user_name) {
        $('#user-name-input', $container).val(config.user_name);
      }

      // Gérer localStorage pour anonymes
      if (config.is_anonymous) {
        const savedName = localStorage.getItem('media_drop_user_name') || '';
        if (savedName) {
          $('#user-name-input').val(savedName);
        }
      }

      // Folder creation management
      if (config.can_create_folder) {
        $('#create-folder', $container).on('click', function(e) {
          e.preventDefault();
          $('#new-folder-form', $container).show();
        });

        $('#cancel-folder', $container).on('click', function(e) {
          e.preventDefault();
          $('#new-folder-form', $container).hide();
          $('#new-folder-name', $container).val('');
        });

        $('#confirm-folder', $container).on('click', function(e) {
          e.preventDefault();
          self.createFolder(config, $container);
        });
      }
    },

    initDropzone: function($container, config) {
      const self = this;

      Dropzone.autoDiscover = false;

      const dropzone = new Dropzone('#media-dropzone', {
        url: config.upload_url,
        paramName: 'file',
        maxFilesize: config.max_file_size || 50, // MB
        acceptedFiles: config.accepted_files || 'image/*,video/*',
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
          }
        },

        error: function(file, errorMessage) {
          console.error('Upload error:', errorMessage);
          Drupal.announce(Drupal.t('Error during upload'), 'assertive');
        },

        // This event is triggered when all files have been processed (success or error).
        queuecomplete: function() {
          // Reload the media list after ALL files are uploaded.
          self.loadUserMedia(config);
          // Trigger the notification via AJAX.
          self.triggerNotification($container, config);
        }
      });
    },

    triggerNotification: function($container, config) {
      const userName = $('#user-name-input', $container).val().trim();
      const folder = $('#folder-select', $container).val();

      $.ajax({
        url: config.trigger_notification_url,
        method: 'POST',
        data: {
          user_name: userName,
          subfolder: folder
        },
        success: function(response) {
          // Notification email has been sent after all uploads completed.
          console.log('Notification triggered');
        },
        error: function(xhr) {
          console.error('Error triggering notification');
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
    },

    loadUserFolders: function(config, userName) {
      if (!userName) {
        return; // Cannot load folders without a user name.
      }
      let listFoldersUrl = config.list_folders_url;
      // Pass user_name for anonymous users who have it in localStorage.
      if (config.is_anonymous) {
        listFoldersUrl += (listFoldersUrl.indexOf('?') === -1 ? '?' : '&') + 'user_name=' + encodeURIComponent(userName);
      }

      $.ajax({
        url: listFoldersUrl,
        method: 'GET',
        success: function(response) {
          if (response.folders && response.folders.length > 0) {
            const $select = $('#folder-select');
            response.folders.forEach(function(folder) {
              $select.append($('<option>', {
                value: folder.safe_name,
                text: folder.name
              }));
            });
          }
        },
        error: function(xhr) {
          console.error('Error loading user folders.');
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);
