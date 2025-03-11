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
    
    // Obtener estadísticas
    $stats = mpodb_get_stats();
    $show_welcome = get_option('mpodb_show_welcome', false);
    
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
        
        <div class="mpodb-info-panel">
            <h3>Sobre este plugin</h3>
            <p>Este plugin optimiza tu base de datos WordPress con elementor realizando las siguientes tareas:</p>
            <ul>
                <li>Elimina meta datos huérfanos</li>
                <li>Elimina meta datos duplicados</li>
                <li>Elimina revisiones de posts</li>
                <li>Elimina posts en la papelera</li>
                <li>Optimiza las tablas de la base de datos</li>
            </ul>
            <p>Para maximizar el rendimiento, recomendamos agregar estas líneas en tu archivo wp-config.php:</p>
            <pre>// Limita a 3 revisiones por post
define('WP_POST_REVISIONS', 3);</pre>
        </div>
    </div>
    <?php
}