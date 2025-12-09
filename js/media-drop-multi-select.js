/**
 * @file
 * Handles multi-select with Shift+click for media grid checkboxes.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.mediaDropMultiSelect = {
    attach: function (context) {
      // Initialize multi-select functionality once per context
      once('media-drop-multi-select', '.vbo-view-form', context).forEach(function (form) {
        var checkboxes = form.querySelectorAll('input[type="checkbox"].js-vbo-checkbox');
        var lastCheckedIndex = -1;

        checkboxes.forEach(function (checkbox, index) {
          checkbox.addEventListener('click', function (e) {
            if (e.shiftKey && lastCheckedIndex !== -1) {
              // Shift+click: select or deselect range
              var start = Math.min(lastCheckedIndex, index);
              var end = Math.max(lastCheckedIndex, index);
              var isChecking = this.checked;

              for (var i = start; i <= end; i++) {
                checkboxes[i].checked = isChecking;
              }

              // Trigger change event to update VBO status
              checkboxes[start].dispatchEvent(new Event('change', { bubbles: true }));
            } else {
              // Regular click: just update last checked index
              lastCheckedIndex = index;
            }
          });
        });
      });
    }
  };
})(Drupal, once);
