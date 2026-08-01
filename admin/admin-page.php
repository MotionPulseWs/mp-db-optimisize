<?php
// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir menú de administración en la barra lateral
 */
function mpodb_add_admin_menu() {
    // Usar add_menu_page en lugar de add_submenu_page para crear un menú principal
    add_menu_page(
        'Optimizador de Base de Datos', // Título de la página
        'MotionPulse DB',               // Título del menú
        'manage_options',               // Capacidad requerida
        'mpodb-optimize',               // Slug de la página
        'mpodb_admin_page',             // Función de callback
        'dashicons-database',           // Icono (dashicons-database es un icono de BD)
        81                              // Posición (81 es después de Herramientas)
    );
}

/**
 * Renderizar la página de administración
 */
function mpodb_admin_page() {
    global $wpdb;

    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
    }
    
    // Procesar acciones
    if (isset($_POST['mpodb_action']) && $_POST['mpodb_action'] === 'start_optimization') {
        check_admin_referer('mpodb_start_optimization');
        mpodb_start_manual_optimization();
        echo '<div class="notice notice-success is-dismissible"><p>Se ha iniciado el proceso de optimización.</p></div>';
    }
    
    if (isset($_POST['mpodb_action']) && $_POST['mpodb_action'] === 'dismiss_welcome') {
        check_admin_referer('mpodb_dismiss_welcome');
        update_option('mpodb_show_welcome', false);
    }

    // Borrado manual de un post type huérfano (acción explícita del admin)
    if (isset($_POST['mpodb_action']) && $_POST['mpodb_action'] === 'delete_post_type') {
        check_admin_referer('mpodb_delete_orphans');
        $post_type = isset($_POST['mpodb_post_type']) ? sanitize_key(wp_unslash($_POST['mpodb_post_type'])) : '';
        $deleted = $post_type ? mpodb_delete_orphaned_post_type($post_type) : 0;
        printf(
            '<div class="notice notice-success is-dismissible"><p>Se eliminaron %d elementos del post type «%s».</p></div>',
            intval($deleted),
            esc_html($post_type)
        );
    }

    // Borrado manual de una taxonomía huérfana (acción explícita del admin)
    if (isset($_POST['mpodb_action']) && $_POST['mpodb_action'] === 'delete_taxonomy') {
        check_admin_referer('mpodb_delete_orphans');
        $taxonomy = isset($_POST['mpodb_taxonomy']) ? sanitize_key(wp_unslash($_POST['mpodb_taxonomy'])) : '';
        $deleted = $taxonomy ? mpodb_delete_orphaned_taxonomy($taxonomy) : 0;
        printf(
            '<div class="notice notice-success is-dismissible"><p>Se eliminaron %d términos de la taxonomía «%s».</p></div>',
            intval($deleted),
            esc_html($taxonomy)
        );
    }

    // Obtener estadísticas
    $stats = mpodb_get_stats();
    $show_welcome = get_option('mpodb_show_welcome', false);
    $trp_active = mpodb_is_translatepress_active();
    
    // Renderizar interfaz
    ?>
    <div class="wrap mpodb-wrap">
        <h1>MotionPulse Optimizador de Base de Datos</h1>
        
        <?php if ($show_welcome): ?>
        <div class="mpodb-welcome-panel">
            <h2>¡Bienvenido al Optimizador de Base de Datos!</h2>
            <p>El plugin está ahora activo y trabajando automáticamente en la optimización de tu base de datos.</p>
            <p>La primera optimización se ejecutará unos segundos después de la activación, y posteriormente se programará para ejecutarse diariamente.</p>
            <form method="post">
                <?php wp_nonce_field('mpodb_dismiss_welcome'); ?>
                <input type="hidden" name="mpodb_action" value="dismiss_welcome">
                <button type="submit" class="button">Entendido, no mostrar de nuevo</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="mpodb-dashboard">
            <div class="mpodb-status-panel">
                <h2>Estado de la Optimización</h2>
                <div id="mpodb-status-indicator" class="mpodb-status-<?php echo esc_attr($stats['status']); ?>">
                    <?php
                    switch ($stats['status']) {
                        case 'running':
                            echo 'Optimización en progreso...';
                            break;
                        case 'completed':
                            echo 'Última optimización completada';
                            break;
                        default:
                            echo 'Sin optimizaciones recientes';
                    }
                    ?>
                </div>
                
                <?php if (!empty($stats['last_run_end'])): ?>
                <p>Última ejecución: <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stats['last_run_end']))); ?> <br>(fecha y hora del servidor) </p>
                <?php endif; ?>
            </div>
            
            <div class="mpodb-stats-panel">
                <h2>Estadísticas de la Base de Datos</h2>
                
                <div id="mpodb-current-size-container" class="mpodb-stat-item">
                    <span class="mpodb-stat-label">Tamaño actual:</span>
                    <span id="mpodb-current-size" class="mpodb-stat-value"><?php echo esc_html($stats['current_size']); ?> MB</span>
                </div>
                
                <?php if ($stats['reduction'] > 0): ?>
                <div class="mpodb-stat-item">
                    <span class="mpodb-stat-label">Tamaño antes de optimizar:</span>
                    <span id="mpodb-initial-size" class="mpodb-stat-value"><?php echo esc_html($stats['initial_size']); ?> MB</span>
                </div>
                
                <div class="mpodb-stat-item">
                    <span class="mpodb-stat-label">Reducción:</span>
                    <span id="mpodb-reduction" class="mpodb-stat-value"><?php echo esc_html($stats['reduction']); ?> MB (<?php echo esc_html($stats['reduction_percentage']); ?>%)</span>
                </div>
                <?php endif; ?>
                
                <!-- Sección para elementos eliminados -->
                <div class="mpodb-cleaned-items">
                    <h3>Elementos Eliminados</h3>
                    
                    <div class="mpodb-stat-item">
                        <span class="mpodb-stat-label">Custom Post Types huérfanos:</span>
                        <span id="mpodb-orphaned-posts" class="mpodb-stat-value"><?php echo intval($stats['orphaned_posts_deleted']); ?></span>
                    </div>
                    
                    <div class="mpodb-stat-item">
                        <span class="mpodb-stat-label">Taxonomías sin uso:</span>
                        <span id="mpodb-orphaned-taxonomies" class="mpodb-stat-value"><?php echo intval($stats['orphaned_taxonomies_deleted']); ?></span>
                    </div>
                    
                    <div class="mpodb-stat-item">
                        <span class="mpodb-stat-label">Campos ACF huérfanos:</span>
                        <span id="mpodb-acf-orphans" class="mpodb-stat-value"><?php echo intval($stats['acf_orphans_deleted']); ?></span>
                    </div>
                </div>
                
                <div class="mpodb-buttons">
                    <button id="mpodb-refresh-stats" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span> Actualizar estadísticas
                    </button>
                    
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('mpodb_start_optimization'); ?>
                        <input type="hidden" name="mpodb_action" value="start_optimization">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-database"></span> Optimizar ahora
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="mpodb-info-panel mpodb-orphans-panel">
            <h3>Revisión manual de elementos huérfanos</h3>
            <p>
                Estos elementos <strong>no se borran automáticamente</strong>. Que un post type o
                taxonomía no esté registrado no significa que sea basura: puede pertenecer a un
                plugin desactivado temporalmente o registrarse sólo en ciertas condiciones.
                Revisa la lista y elimina únicamente lo que reconozcas como descartable.
            </p>

            <?php
            $orphan_post_types = mpodb_scan_orphaned_post_types();
            $orphan_taxonomies = mpodb_scan_orphaned_taxonomies();
            ?>

            <h4>Post types no registrados</h4>
            <?php if (empty($orphan_post_types)): ?>
                <p><em>No se detectaron post types huérfanos.</em></p>
            <?php else: ?>
                <table class="widefat striped mpodb-orphans-table">
                    <thead>
                        <tr><th>Post type</th><th>Elementos</th><th>Acción</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orphan_post_types as $item): ?>
                        <tr>
                            <td><code><?php echo esc_html($item['post_type']); ?></code></td>
                            <td><?php echo intval($item['count']); ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Se eliminarán <?php echo intval($item['count']); ?> elementos de «<?php echo esc_js($item['post_type']); ?>». Esta acción no se puede deshacer. ¿Continuar?');">
                                    <?php wp_nonce_field('mpodb_delete_orphans'); ?>
                                    <input type="hidden" name="mpodb_action" value="delete_post_type">
                                    <input type="hidden" name="mpodb_post_type" value="<?php echo esc_attr($item['post_type']); ?>">
                                    <button type="submit" class="button button-link-delete">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h4>Taxonomías no registradas</h4>
            <?php if (empty($orphan_taxonomies)): ?>
                <p><em>No se detectaron taxonomías huérfanas.</em></p>
            <?php else: ?>
                <table class="widefat striped mpodb-orphans-table">
                    <thead>
                        <tr><th>Taxonomía</th><th>Términos</th><th>Acción</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orphan_taxonomies as $item): ?>
                        <tr>
                            <td><code><?php echo esc_html($item['taxonomy']); ?></code></td>
                            <td><?php echo intval($item['count']); ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Se eliminarán <?php echo intval($item['count']); ?> términos de «<?php echo esc_js($item['taxonomy']); ?>». Esta acción no se puede deshacer. ¿Continuar?');">
                                    <?php wp_nonce_field('mpodb_delete_orphans'); ?>
                                    <input type="hidden" name="mpodb_action" value="delete_taxonomy">
                                    <input type="hidden" name="mpodb_taxonomy" value="<?php echo esc_attr($item['taxonomy']); ?>">
                                    <button type="submit" class="button button-link-delete">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="mpodb-info-panel">
            <h3>Sobre este plugin</h3>
            <p>Este plugin optimiza tu base de datos WordPress con elementor realizando las siguientes tareas:</p>
            <ul>
                <li>Elimina meta datos huérfanos</li>
                <li>Elimina meta datos duplicados</li>
                <li>Elimina revisiones de posts</li>
                <li>Elimina posts en la papelera</li>
                <li>Elimina campos ACF huérfanos</li>
                <li>Optimiza las tablas de la base de datos</li>
                <li>Compatible con TranslatePress - Multilingual: protege las traducciones y no las elimina</li>
            </ul>
            <p>
                Los Custom Post Types y las taxonomías huérfanas <strong>no se eliminan de forma
                automática</strong>: se listan más arriba para que los revises y los borres a mano.
            </p>
            <p>Para maximizar el rendimiento, recomendamos agregar estas líneas en tu archivo wp-config.php:</p>
            <pre>// Limita a 3 revisiones por post
define('WP_POST_REVISIONS', 3);</pre>
        </div>

        <?php if ($trp_active): ?>
        <?php $trp_tables = mpodb_get_translatepress_tables(); ?>
        <div class="mpodb-info-panel mpodb-trp-compat">
            <h3><span class="dashicons dashicons-translation"></span> Compatible con TranslatePress - Multilingual</h3>
            <p>
                TranslatePress no duplica posts por idioma: guarda las traducciones en sus propias
                tablas, y este optimizador nunca opera sobre ellas. <strong>Tus traducciones no se
                tocan.</strong>
            </p>
            <?php if (!empty($trp_tables)): ?>
            <details class="mpodb-trp-tables">
                <summary>
                    Ver las <?php echo count($trp_tables); ?> tablas protegidas
                    (prefijo <code><?php echo esc_html($wpdb->prefix); ?></code>)
                </summary>
                <ul>
                    <?php foreach ($trp_tables as $trp_table): ?>
                        <li><code><?php echo esc_html($trp_table); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="mpodb-info-panel">
            <p>Este plugin es gratis y de uso libre. Si te ha ayudado, considera invitarle un cafe a los desarrolladores.<span class="dashicons dashicons-heart"></span></p>
            <a class="button button-coffee" href="https://www.paypal.com/ncp/payment/MUCAMQDHUBSXN" target="_blank">Enviar un cafe</a>
        </div>
    </div>
    <?php
}