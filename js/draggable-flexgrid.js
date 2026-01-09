// js/draggable-flexgrid.js
(function ($, Drupal, drupalSettings, once) {
  "use strict";

  Drupal.behaviors.draggableFlexGrid = {
    attach: function (context, settings) {
      // ========================================
      // DRAGULA - Gestion du drag & drop
      // ========================================
      once("dragula", ".js-draggable-flexgrid", context).forEach(function (
        grid
      ) {
        console.log("Initializing Dragula on grid:", grid);

        var drake = dragula([grid], {
          // Important : le handle est imbriqué dans .draggable-flexgrid__menu-handle
          moves: function (el, container, handle) {
            // L'élément doit avoir la classe js-draggable-item
            if (!el.classList.contains("js-draggable-item")) {
              return false;
            }

            if (handle.closest(".js-more-info-wrapper")) {
              return false;
            }

            // Le drag doit partir du handle ou d'un de ses parents
            return (
              handle.classList.contains("draggable-flexgrid__handle") ||
              handle.closest(".draggable-flexgrid__handle") !== null
            );
          },

          revertOnSpill: true,
          removeOnSpill: false,
          direction: "horizontal", // Votre grid est en flex-wrap

          // Paramètres tactiles
          delay: 0, // Pas de délai avec Dragula
          mirrorContainer: document.body,
        });

        // Événement au début du drag
        drake.on("drag", function (el, source) {
          console.log("✓ Drag started on element:", el.getAttribute("data-id"));
          el.classList.add("draggable-flexgrid__item--drag");
          // Ajouter un listener de mousemove pour l'auto-scroll
          document.addEventListener("mousemove", autoScrollDuringDrag);
          document.addEventListener("touchmove", autoScrollDuringDrag);
        });

        // Événement pendant le mouvement
        drake.on("shadow", function (el, container, source) {
          console.log("✓ Moving element");
        });

        // Événement à la fin du drag
        drake.on("drop", function (el, target, source, sibling) {
          console.log("✓ Drop successful");
          el.classList.remove("draggable-flexgrid__item--drag");

          // retirer les listeners
          document.removeEventListener("mousemove", autoScrollDuringDrag);
          document.removeEventListener("touchmove", autoScrollDuringDrag);

          // Mettre à jour l'ordre
          updateOrderFromDragula(grid);
        });

        // Événement si le drag est annulé
        drake.on("cancel", function (el, container, source) {
          console.log("✗ Drag cancelled");
          el.classList.remove("draggable-flexgrid__item--drag");

          // retirer les listeners
          document.removeEventListener("mousemove", autoScrollDuringDrag);
          document.removeEventListener("touchmove", autoScrollDuringDrag);
        });
      });

      // Fonction pour mettre à jour l'ordre après un drag Dragula
      function updateOrderFromDragula(grid) {
        var items = grid.querySelectorAll(".js-draggable-item");
        var order = [];

        items.forEach(function (item, index) {
          // Mettre à jour data-index
          item.setAttribute("data-index", index);

          // Récupérer l'ID de l'entité
          var entityId = item.getAttribute("data-entity-id");
          if (entityId) {
            order.push(entityId);
          }
        });

        console.log("New order:", order);

        // Mettre à jour le champ hidden
        var orderInput = grid.querySelector(".vbo-media-drop-order");
        if (orderInput) {
          orderInput.value = JSON.stringify(order);
          console.log("Updated hidden field:", orderInput.value);
        }

        // Sauvegarder via AJAX
        saveOrderToServer(order);
      }

      // Fonction pour sauvegarder l'ordre sur le serveur
      function saveOrderToServer(order) {
        if (typeof drupalSettings.csrf_token === "undefined") {
          console.warn("CSRF token not available, skipping save");
          return;
        }

        fetch(Drupal.url("media-drop/draggable-flexgrid/save-order"), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": drupalSettings.csrf_token,
          },
          body: JSON.stringify({ order: order }),
        })
          .then((response) => response.json())
          .then((data) => {
            console.log("Order saved successfully:", data);
          })
          .catch((error) => {
            console.error("Error saving order:", error);
          });
      }

      // Fonction d'auto-scroll pendant le drag
      function autoScrollDuringDrag(e) {
        const margin = 80; // px depuis le haut/bas de l'écran pour déclencher le scroll
        const speed = 15; // pixels à chaque tick

        // Déterminer la position Y selon le type d'événement
        let y;
        if (e.touches && e.touches.length > 0) {
          y = e.touches[0].clientY;
        } else if (e.clientY !== undefined) {
          y = e.clientY;
        } else {
          return; // impossible de déterminer Y
        }

        // console.log("Auto-scroll check at Y:", y);

        if (y < margin) {
          // console.log("Scrolling up");
          window.scrollBy({ top: -speed });
        } else if (y > window.innerHeight - margin) {
          // console.log("Scrolling down");
          window.scrollBy({ top: speed });
        }
      }

      const container = document.querySelector(".draggable-flexgrid");

      container.addEventListener(
        "touchmove",
        function (e) {
          const scrollTop = container.scrollTop;
          const scrollHeight = container.scrollHeight;
          const offsetHeight = container.offsetHeight;

          // Si on est tout en haut et qu'on scroll vers le haut, ne pas bloquer
          if (scrollTop <= 0 && e.touches[0].clientY > container._lastY) {
            // rien
          }
          // Si on est tout en bas et qu'on scroll vers le bas, ne pas bloquer
          else if (
            scrollTop + offsetHeight >= scrollHeight &&
            e.touches[0].clientY < container._lastY
          ) {
            // rien
          }
          // Sinon, on veut bloquer le scroll natif pour drag
          else {
            e.preventDefault();
          }

          container._lastY = e.touches[0].clientY;
        },
        { passive: false }
      );

      // ========================================
      // POPUP - Gestion du menu "Plus..."
      // ========================================
      once("entity-popup2", ".js-more-info-wrapper", context).forEach(function (
        wrapper
      ) {
        const button = wrapper.querySelector(".js-entity-id-btn");
        const popup = wrapper.querySelector(".js-entity-popup");

        if (!button || !popup) return;

        // Bloquer Dragula AVANT le drag
        wrapper.addEventListener("mousedown", function (e) {
          e.stopPropagation();
        });

        // Ouvrir/fermer le popup
        button.addEventListener("click", function (e) {
          e.stopPropagation();

          document.querySelectorAll(".js-entity-popup").forEach(function (p) {
            if (p !== popup) {
              p.style.display = "none";
            }
          });

          popup.style.display =
            popup.style.display === "block" ? "none" : "block";
        });

        // Fermer le popup quand on clique dedans
        popup.addEventListener("click", function () {
          popup.style.display = "none";
        });
      });

      // Fermer tous les popups si on clique ailleurs
      document.addEventListener("click", function (e) {
        // Ne pas fermer si on clique sur un bouton de popup
        console.log("Document click:", e.target);
        if (
          !e.target.closest(".js-more-info-wrapper") &&
          !e.target.closest(".dropbutton-wrapper")
        ) {
          console.log("Closing all popups");
          document.querySelectorAll(".js-entity-popup").forEach(function (p) {
            p.style.display = "none";
          });
        }
      });
    },
  };
})(jQuery, Drupal, drupalSettings, once);
