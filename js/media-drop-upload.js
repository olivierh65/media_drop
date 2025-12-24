/**
 * @file
 * Media Drop upload interface with Dropzone.
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Store upload file data globally per instance
  const uploadFileData = {};

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
        addRemoveLinks: false,
        dictDefaultMessage: Drupal.t('Drag and drop your files here'),
        dictFallbackMessage: Drupal.t('Your browser does not support drag\'n\'drop'),
        dictFileTooBig: Drupal.t('File is too big (max: {{maxFilesize}}MB)'),
        dictInvalidFileType: Drupal.t('Invalid file type'),
        dictRemoveFile: Drupal.t('Remove from list'),
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

          // Store file info for retry capability
          uploadFileData[file.name] = {
            file: file,
            userName: userName,
            subfolder: folder,
            dropzone: this
          };
        },

        success: function(file, response) {
          if (response.results && response.results[0]) {
            const result = response.results[0];
            if (result.success) {
              self.setFileStatus(file, 'success', '', false, this);
              Drupal.announce(Drupal.t('File uploaded successfully'));
            } else {
              // Handle different error types
              let error_msg = result.error;
              let is_duplicate = false;
              if (result.is_duplicate) {
                error_msg = Drupal.t('This file already exists (same name and size)');
                is_duplicate = true;
              }
              self.setFileStatus(file, 'error', error_msg, is_duplicate, this);
              Drupal.announce(Drupal.t('Error during upload') + ': ' + error_msg, 'assertive');
            }
          }
        },

        error: function(file, errorMessage, xhr) {
          self.setFileStatus(file, 'error', errorMessage, false, this);
          console.error('Upload error:', errorMessage);
          Drupal.announce(Drupal.t('Error during upload'), 'assertive');
        },

        // Add custom preview template with status indicator
        previewTemplate: `
          <div class="dz-preview">
            <div class="dz-image"><img data-dz-thumbnail /></div>
            <div class="dz-details">
              <div class="dz-filename"><span data-dz-name></span></div>
              <div class="dz-size" data-dz-size></div>
              <div class="dz-status-indicator">
                <span class="dz-status-icon"></span>
                <span class="dz-status-text"></span>
              </div>
              <div class="dz-error-message"><span data-dz-errormessage></span></div>
            </div>
            <div class="dz-progress"><span class="dz-upload" data-dz-uploadprogress></span></div>
            <div class="dz-success-mark"><svg width="24" height="24" viewBox="0 0 54 54" class="icon icon-success"><circle cx="27" cy="27" r="27" fill="#4CAF50"/><path d="M22 35l-8-8m0 0l-4-4m12 0l12-12" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div class="dz-error-mark"><svg width="24" height="24" viewBox="0 0 54 54" class="icon icon-error"><circle cx="27" cy="27" r="27" fill="#F44336"/><path d="M16 16l22 22m0-22l-22 22" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg></div>
            <div class="dz-warning-mark"><svg width="24" height="24" viewBox="0 0 54 54" class="icon icon-warning"><circle cx="27" cy="27" r="27" fill="#FF9800"/><text x="27" y="38" font-size="32" font-weight="bold" fill="#fff" text-anchor="middle">!</text></svg></div>
            <div class="dz-action-buttons">
              <button type="button" class="dz-retry-button button button--small dz-hidden">
                <span class="retry-icon">↻</span> ` + Drupal.t('Retry') + `
              </button>
              <a href="javascript:undefined;" class="dz-remove button button--small button--danger" data-dz-remove>` + Drupal.t('Remove from list') + `</a>
            </div>
          </div>
        `,

        // This event is triggered when all files have been processed (success or error).
        queuecomplete: function() {
          self.showClearSuccessButton($container, this);
          // Reload the media list after ALL files are uploaded.
          self.loadUserMedia(config);
          // Trigger the notification via AJAX.
          self.triggerNotification($container, config);
        }
      });

      // Store dropzone reference for easy access
      $container.data('dropzone', dropzone);
    },

    /**
     * Set the upload status for a file.
     */
    setFileStatus: function(file, status, message, isDuplicate, dropzone) {
      const self = this;
      const $preview = $(file.previewElement);

      // Remove old status classes
      $preview.removeClass('dz-uploading dz-success dz-error dz-duplicate');

      // Helper to show SVG mark and force it to stay visible
      const forceShowMark = function($mark) {
        const styleStr = 'display: block !important; position: absolute !important; top: 2px !important; right: 2px !important; width: 24px !important; height: 24px !important; z-index: 20 !important;';
        $mark.attr('style', styleStr);
        $mark[0].style.cssText = styleStr;
        
        // Keep reapplying the style if Dropzone removes it
        let counter = 0;
        const interval = setInterval(() => {
          if (counter > 20 || !$mark.closest('body').length) {
            clearInterval(interval);
            return;
          }
          const currentDisplay = $mark.css('display');
          if (currentDisplay !== 'block') {
            $mark.attr('style', styleStr);
            $mark[0].style.cssText = styleStr;
          }
          counter++;
        }, 50);
      };

      // Helper to hide mark
      const hideMark = function($mark) {
        $mark.attr('style', 'display: none !important;');
        $mark[0].style.cssText = 'display: none !important;';
      };

      if (status === 'success') {
        file.uploadStatus = 'success';
        $preview.addClass('dz-success');
        $preview.find('.dz-status-icon').html('✓');
        $preview.find('.dz-status-text').text(Drupal.t('Transferred'));
        forceShowMark($preview.find('.dz-success-mark'));
        hideMark($preview.find('.dz-error-mark'));
        hideMark($preview.find('.dz-warning-mark'));
        $preview.find('.dz-retry-button').addClass('dz-hidden');
        $preview.find('.dz-error-message').hide();
      } else if (status === 'error' && isDuplicate) {
        file.uploadStatus = 'error';
        // Duplicate file: show warning, no retry
        $preview.addClass('dz-duplicate');
        $preview.find('.dz-status-icon').html('⚠');
        $preview.find('.dz-status-text').text(message || Drupal.t('File exists'));
        $preview.find('.dz-error-message span').text(message || '');
        hideMark($preview.find('.dz-success-mark'));
        hideMark($preview.find('.dz-error-mark'));
        forceShowMark($preview.find('.dz-warning-mark'));
        $preview.find('.dz-error-message').show();
        $preview.find('.dz-retry-button').addClass('dz-hidden');
      } else if (status === 'error') {
        file.uploadStatus = 'error';
        // Other error: show error, allow retry
        $preview.addClass('dz-error');
        $preview.find('.dz-status-icon').html('✕');
        $preview.find('.dz-status-text').text(message || Drupal.t('Error'));
        $preview.find('.dz-error-message span').text(message || '');
        hideMark($preview.find('.dz-success-mark'));
        forceShowMark($preview.find('.dz-error-mark'));
        hideMark($preview.find('.dz-warning-mark'));
        $preview.find('.dz-error-message').show();

        // Show retry button on error
        const $retryBtn = $preview.find('.dz-retry-button').removeClass('dz-hidden');

        // Attach retry handler
        $retryBtn.off('click').on('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          if (dropzone) {
            self.retryUpload(file, dropzone);
          } else {
            console.error('Dropzone instance not found');
          }
        });
      } else if (status === 'uploading') {
        file.uploadStatus = 'uploading';
        $preview.addClass('dz-uploading');
        $preview.find('.dz-status-text').text(Drupal.t('Uploading...'));
        hideMark($preview.find('.dz-success-mark'));
        hideMark($preview.find('.dz-error-mark'));
        hideMark($preview.find('.dz-warning-mark'));
        $preview.find('.dz-retry-button').addClass('dz-hidden');
        $preview.find('.dz-error-message').hide();
      }
    },

    /**
     * Retry file upload after error.
     */
    retryUpload: function(file, dropzone) {
      const self = this;

      // Reset file upload state
      file.upload = {
        progress: 0,
        total: file.size,
        bytesSent: 0
      };
      file.status = Dropzone.QUEUED;

      // Update visual state
      this.setFileStatus(file, 'uploading');

      // Reset progress bar
      $(file.previewElement).find('.dz-upload').css('width', '0%');

      // Re-queue the file for upload by processing it
      dropzone.processFile(file);
    },

    /**
     * Show clear success button if there are successful uploads.
     */
    showClearSuccessButton: function($container, dropzone) {
      const self = this;
      let successCount = 0;
      let errorCount = 0;

      $(dropzone.files).each(function() {
        if (this.uploadStatus === 'success') {
          successCount++;
        } else if (this.uploadStatus === 'error') {
          errorCount++;
        }
      });

      // Find the dropzone container
      const $dropzoneContainer = $container.find('.media-drop-dropzone');

      // Remove all existing clear buttons anywhere in the container
      $container.find('.dz-clear-success-button').remove();

      if (successCount > 0) {
        const $clearBtn = $('<button>', {
          type: 'button',
          class: 'dz-clear-success-button button button--primary',
          text: Drupal.t('Clear completed uploads') + ' (' + successCount + ')'
        }).on('click', function(e) {
          e.preventDefault();
          $(dropzone.files).each(function() {
            if (this.uploadStatus === 'success') {
              dropzone.removeFile(this);
            }
          });
          // Call showClearSuccessButton again to hide the button
          self.showClearSuccessButton($container, dropzone);
          Drupal.announce(Drupal.t('Completed uploads cleared'));
        });

        $dropzoneContainer.after($clearBtn);
      }
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
