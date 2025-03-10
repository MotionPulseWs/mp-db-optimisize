<?php
// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Función principal para optimizar la base de datos
 */
function mpodb_optimize_database() {
    global $wpdb;

    // Actualizar estado como "en proceso"
    update_option('mpodb_optimization_status', 'running');
    update_option('mpodb_last_run_start', current_time('mysql'));
    
    // Guardar tamaño inicial
    $initial_size = mpodb_get_database_size();
    update_option('mpodb_initial_size', $initial_size);

    // Eliminar meta datos huérfanos
    $wpdb->query(
        "DELETE pm FROM {$wpdb->prefix}postmeta pm
        LEFT JOIN {$wpdb->prefix}posts wp ON wp.ID = pm.post_id
        WHERE wp.ID IS NULL"
    );

    // Identificar y eliminar meta datos duplicados
    $duplicates = $wpdb->get_results("
        SELECT meta_id
        FROM (
            SELECT meta_id, ROW_NUMBER() OVER (PARTITION BY post_id, meta_key ORDER BY meta_id DESC) AS rnum
            FROM {$wpdb->prefix}postmeta
        ) t
        WHERE t.rnum > 1
    ");

    foreach ($duplicates as $duplicate) {
        $wpdb->delete($wpdb->prefix . 'postmeta', array('meta_id' => $duplicate->meta_id));
    }

    // Eliminar revisiones de posts
    $wpdb->query(
        "DELETE FROM {$wpdb->prefix}postmeta
        WHERE post_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'revision')"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->prefix}posts
        WHERE post_type = 'revision'"
    );

    // Eliminar posts en la papelera
    $wpdb->query(
        "DELETE FROM {$wpdb->prefix}postmeta
        WHERE post_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_status = 'trash')"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->prefix}posts
        WHERE post_status = 'trash'"
    );

    // Optimizar tablas
    $wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}postmeta");
    $wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}posts");

    // Guardar tamaño final
    $final_size = mpodb_get_database_size();
    update_option('mpodb_final_size', $final_size);
    
    // Calcular la reducción
    $reduction = $initial_size - $final_size;
    $reduction_percentage = ($initial_size > 0) ? round(($reduction / $initial_size) * 100, 2) : 0;
    
    update_option('mpodb_size_reduction', $reduction);
    update_option('mpodb_size_reduction_percentage', $reduction_percentage);
    
    // Actualizar estado como "completado"
    update_option('mpodb_optimization_status', 'completed');
    update_option('mpodb_last_run_end', current_time('mysql'));
}

/**
 * Obtener el tamaño de la base de datos
 * @return float Tamaño en MB
 */
function mpodb_get_database_size() {
    global $wpdb;
    
    $database_name = DB_NAME;
    $sql = $wpdb->prepare("
        SELECT SUM(data_length + index_length) / 1024 / 1024 AS size
        FROM information_schema.TABLES
        WHERE table_schema = %s
    ", $database_name);
    
    $result = $wpdb->get_var($sql);
    return round(floatval($result), 2);
}

/**
 * Obtener estadísticas sobre la optimización
 * @return array Datos estadísticos
 */
function mpodb_get_stats() {
    $current_size = mpodb_get_database_size();
    $initial_size = get_option('mpodb_initial_size', $current_size);
    $final_size = get_option('mpodb_final_size', $current_size);
    $reduction = get_option('mpodb_size_reduction', 0);
    $reduction_percentage = get_option('mpodb_size_reduction_percentage', 0);
    $status = get_option('mpodb_optimization_status', 'none');
    $last_run_start = get_option('mpodb_last_run_start', '');
    $last_run_end = get_option('mpodb_last_run_end', '');
    
    return array(
        'current_size' => $current_size,
        'initial_size' => $initial_size,
        'final_size' => $final_size,
        'reduction' => $reduction,
        'reduction_percentage' => $reduction_percentage,
        'status' => $status,
        'last_run_start' => $last_run_start,
        'last_run_end' => $last_run_end
    );
}

/**
 * Iniciar una optimización manual
 */
function mpodb_start_manual_optimization() {
    if (!wp_next_scheduled('mpodb_optimize_database_event')) {
        wp_schedule_single_event(time(), 'mpodb_optimize_database_event');
    }
}

/**
 * Función AJAX para refrescar las estadísticas
 */
function mpodb_ajax_refresh_stats() {
    check_ajax_referer('mpodb_refresh_nonce', 'nonce');
    
    $stats = mpodb_get_stats();
    wp_send_json_success($stats);
}