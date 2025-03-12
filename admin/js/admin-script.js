(function ($) {
  "use strict";

  $(document).ready(function () {
    // Botón de actualizar estadísticas
    $("#mpodb-refresh-stats").on("click", function (e) {
      e.preventDefault();

      const $button = $(this);
      $button.addClass("mpodb-refresh-spinning");
      $button.attr("disabled", true);

      $.ajax({
        url: mpodb_vars.ajax_url,
        type: "POST",
        data: {
          action: "mpodb_refresh_stats",
          nonce: mpodb_vars.nonce,
        },
        success: function (response) {
          if (response.success && response.data) {
            const data = response.data;

            // Actualizar tamaño actual
            $("#mpodb-current-size").text(data.current_size + " MB");

            // Actualizar indicador de estado
            $("#mpodb-status-indicator")
              .removeClass(
                "mpodb-status-none mpodb-status-running mpodb-status-completed"
              )
              .addClass("mpodb-status-" + data.status);

            let statusText = "Sin optimizaciones recientes";
            if (data.status === "running") {
              statusText = "Optimización en progreso...";
            } else if (data.status === "completed") {
              statusText = "Última optimización completada";
            }
            $("#mpodb-status-indicator").text(statusText);

            // Si hay una reducción, mostrar estadísticas adicionales
            if (data.reduction > 0) {
              // Actualizar o crear el elemento de tamaño inicial
              if ($("#mpodb-initial-size").length === 0) {
                const initialSizeHtml = `
                      <div class="mpodb-stat-item">
                          <span class="mpodb-stat-label">Tamaño antes de optimizar:</span>
                          <span id="mpodb-initial-size" class="mpodb-stat-value">${data.initial_size} MB</span>
                      </div>`;
                $("#mpodb-current-size-container").after(initialSizeHtml);
              } else {
                $("#mpodb-initial-size").text(data.initial_size + " MB");
              }

              // Actualizar o crear el elemento de reducción
              if ($("#mpodb-reduction").length === 0) {
                const reductionHtml = `
                      <div class="mpodb-stat-item">
                          <span class="mpodb-stat-label">Reducción:</span>
                          <span id="mpodb-reduction" class="mpodb-stat-value">${data.reduction} MB (${data.reduction_percentage}%)</span>
                      </div>`;
                $("#mpodb-initial-size").parent().after(reductionHtml);
              } else {
                $("#mpodb-reduction").text(
                  `${data.reduction} MB (${data.reduction_percentage}%)`
                );
              }
            }

            // Actualizar estadísticas de elementos eliminados
            $("#mpodb-orphaned-posts").text(data.orphaned_posts_deleted);
            $("#mpodb-orphaned-taxonomies").text(
              data.orphaned_taxonomies_deleted
            );
            $("#mpodb-acf-orphans").text(data.acf_orphans_deleted);
          }
        },
        complete: function () {
          $button.removeClass("mpodb-refresh-spinning");
          $button.attr("disabled", false);
        },
      });
    });

    // Auto-actualizar cada 30 segundos durante la optimización
    if ($("#mpodb-status-indicator").hasClass("mpodb-status-running")) {
      const intervalId = setInterval(function () {
        $("#mpodb-refresh-stats").trigger("click");

        // Detener la actualización automática si ya no está en progreso
        if (!$("#mpodb-status-indicator").hasClass("mpodb-status-running")) {
          clearInterval(intervalId);
        }
      }, 30000);
    }
  });
})(jQuery);
