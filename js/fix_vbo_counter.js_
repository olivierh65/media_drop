/**
 * @file
 * Fixes the VBO (Views Bulk Operations) multipage selector and bulk actions counter.
 *
 * Problem:
 * - In VBO 4.x with Drupal 10, the multipage selector summary can incorrectly
 *   display "1 item selected" even when zero items are selected.
 * - Cause: This is due to a bug in the VBO JS that mishandles translations
 *   when updating the summary after AJAX calls.
 * - Additionally, the main bulk actions counter (`.js-views-bulk-actions-status`)
 *   does not automatically update when checkboxes change.
 *
 * Solution:
 * - Attach a listener to all VBO checkboxes to dispatch a custom event
 *   `vbo-selection-changed` whenever selection changes.
 * - Update the main bulk actions counter based on the actual number of checked checkboxes.
 * - Use a MutationObserver on the multipage selector summary to correct the display
 *   to "0 items selected" when the selection is empty, avoiding infinite loops.
 *
 * Notes:
 * - This is a JS-only fix; no backend changes are required.
 * - The code uses `once()` to ensure event listeners and observers are attached only once.
 */

(function (Drupal, once) {

  // Behavior to update the main VBO checkbox counter
  Drupal.behaviors.vboCheckboxSync = {
    attach(context) {
      once('vbo-checkbox-sync', '.js-vbo-checkbox', context).forEach(cb => {
        cb.addEventListener('change', () => {
          document.dispatchEvent(new Event('vbo-selection-changed'));
        });
      });

      function updateCounter() {
        const checked = document.querySelectorAll('.js-vbo-checkbox:checked').length;
        const status = document.querySelector('.js-views-bulk-actions-status');

        if (status) {
          status.textContent = checked
            ? Drupal.t('@count item(s) selected', { '@count': checked })
            : Drupal.t('No items selected');
        }
      }

      document.addEventListener('vbo-selection-changed', updateCounter);

      // Initialize counter on page load
      updateCounter();
    }
  };

  // Behavior to fix the multipage selector summary
  Drupal.behaviors.summaryVboFix = {
    attach(context) {
      once('mon-vbo-fix', '.vbo-multipage-selector summary', context).forEach(summary => {

        // ⚡ Declare observer variable first
        let observer;
        // Create observer after defining updateSummary
        observer = new MutationObserver(updateSummary);

        // Start observing changes
        observer.observe(summary, { childList: true });

        // Function to update summary text safely
        function updateSummary() {
          const count = document.querySelectorAll('.js-vbo-checkbox:checked').length;

          if (count === 0 && summary.textContent !== Drupal.t('0 élément sélectionné')) {
            // Stop observing to prevent infinite loop
            observer.disconnect();

            // Update summary text
            summary.textContent = Drupal.t('0 élément sélectionné');

            // Reconnect observer
            observer.observe(summary, { childList: true });
          }
        }

        // Initialize summary text on page load
        updateSummary();
      });
    }
  };

})(Drupal, once);
