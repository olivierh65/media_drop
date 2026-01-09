// js/draggable-flexgrid.js
(function ($, Drupal, drupalSettings, once) {
  "use strict";

  Drupal.behaviors.draggableFlexGrid = {
    attach: function (context, settings) {
      // Popup menu management
      once("entity-popup2", ".js-more-info-wrapper", context).forEach(function (
        wrapper
      ) {
        console.log("Setting up popup for wrapper:", wrapper);
        const button = wrapper.querySelector(".js-entity-id-btn");
        const popup = wrapper.querySelector(".js-entity-popup");

        // Open the popup on button click
        button.addEventListener("click", function (e) {
          e.stopPropagation(); // prevent the click on the button from immediately closing the popup
          popup.style.display =
            popup.style.display === "block" ? "none" : "block";
        });

        // Close the popup on click inside it
        popup.addEventListener("click", function (e) {
          popup.style.display = "none";
        });
      });

      // Ferme tous les popups si on clique ailleurs sur la page
      document.addEventListener("click", function () {
        document.querySelectorAll(".js-entity-popup").forEach(function (p) {
          p.style.display = "none";
        });
      });

      // Sortable initialization
      once(
        "draggable-flexgrid-init",
        ".js-draggable-flexgrid",
        context
      ).forEach(function (grid) {
        if (typeof Sortable === "undefined") {
          console.error("Sortable.js is not loaded!");
          return;
        }

        var config = {};
        const isTouch = needsFallback();

        // Créer l'instance Sortable
        var sortable = Sortable.create(grid, {
          animation: 200,
          handle: ".draggable-flexgrid__handle",
          draggable: ".js-draggable-item",
          ghostClass: "draggable-flexgrid__item--ghost",
          chosenClass: "draggable-flexgrid__item--chosen",
          dragClass: "draggable-flexgrid__item--drag",
          fallbackOnBody: isTouch,
          forceFallback: isTouch,
          fallbackTolerance: isTouch ? 10 : 0,
          swapThreshold: 0.65,
          invertSwap: isTouch,
          scroll: true,

          onEnd: function (evt) {
            console.log("Drag ended");
            updateOrderInputCB(this);
            if (config.saveOrder) {
              saveNewOrder(grid, config);
            }
          },
          onStart: function () {
            console.log("Drag started");
          },
        });
        console.log("Initialized Sortable on grid:", grid);
        updateOrderInputCB(sortable);
      });

      function needsFallback() {
  return (
    "ontouchstart" in window ||
    navigator.maxTouchPoints > 0 ||
    navigator.msMaxTouchPoints > 0
  );
}

      // Dans votre JS - updateOrderInput()
      function updateOrderInputCB(sortable) {
        const order = sortable.toArray();

        // Sauvegarder immédiatement dans tempstore
        fetch(Drupal.url("media-drop/draggable-flexgrid/save-order"), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": drupalSettings.csrf_token,
          },
          body: JSON.stringify({ order: order }),
        });

        // Garder aussi le champ hidden pour backup
        $(".vbo-media-drop-order").val(JSON.stringify(order));
      }

      function updateOrderInput(sortable) {
        // Retourne un tableau d'ID d'entité dans l'ordre actuel
        const vboOrder = $(".vbo-media-drop-order");
        vboOrder.val(JSON.stringify(sortable.toArray()));
        console.log("Updated order input:", vboOrder.val());
      }
    },
  };
})(jQuery, Drupal, drupalSettings, once);
