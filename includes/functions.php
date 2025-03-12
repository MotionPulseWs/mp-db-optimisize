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

    // Limpiar custom post types huérfanos
    mpodb_clean_orphaned_custom_posts();

    // Limpiar taxonomías sin uso
    mpodb_clean_unused_taxonomies();

    // Limpiar campos ACF huérfanos
    mpodb_clean_acf_orphans();

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
 * Limpiar custom post types huérfanos y sus elementos relacionados
 */
function mpodb_clean_orphaned_custom_posts() {
    global $wpdb;
    
    // Conseguir todos los post types registrados actualmente en WordPress
    $registered_post_types = get_post_types(array(), 'names');
    
    // Añadir algunos post types core que podrían no estar en la lista anterior
    $core_types = array('post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache');
    $valid_post_types = array_merge($registered_post_types, $core_types);
    
    // Convertir a formato para consulta SQL
    $valid_types_sql = "'" . implode("','", $valid_post_types) . "'";
    
    // Obtener IDs de posts con post_types que no están registrados
    $orphaned_post_ids = $wpdb->get_col("
        SELECT ID FROM {$wpdb->posts}
        WHERE post_type NOT IN ({$valid_types_sql})
    ");
    
    // Si hay posts huérfanos, eliminarlos
    if (!empty($orphaned_post_ids)) {
        $ids_string = implode(',', $orphaned_post_ids);
        
        // Eliminar relaciones de taxonomía
        $wpdb->query("
            DELETE FROM {$wpdb->term_relationships}
            WHERE object_id IN ({$ids_string})
        ");
        
        // Eliminar meta datos
        $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE post_id IN ({$ids_string})
        ");
        
        // Eliminar los posts huérfanos
        $wpdb->query("
            DELETE FROM {$wpdb->posts}
            WHERE ID IN ({$ids_string})
        ");
        
        update_option('mpodb_orphaned_posts_deleted', count($orphaned_post_ids));
    } else {
        update_option('mpodb_orphaned_posts_deleted', 0);
    }
}

/**
 * Limpiar taxonomías sin uso
 */
function mpodb_clean_unused_taxonomies() {
    global $wpdb;
    
    // Conseguir taxonomías registradas
    $registered_taxonomies = get_taxonomies(array(), 'names');
    $core_taxonomies = array('category', 'post_tag', 'nav_menu', 'link_category', 'post_format');
    $valid_taxonomies = array_merge($registered_taxonomies, $core_taxonomies);
    $valid_tax_sql = "'" . implode("','", $valid_taxonomies) . "'";
    
    // Identificar términos de taxonomías no registradas
    $orphaned_terms = $wpdb->get_results("
        SELECT t.term_id, tt.term_taxonomy_id
        FROM {$wpdb->terms} t
        JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy NOT IN ({$valid_tax_sql})
    ");
    
    $count_deleted = 0;
    
    if (!empty($orphaned_terms)) {
        foreach ($orphaned_terms as $term) {
            // Eliminar relaciones
            $wpdb->delete($wpdb->term_relationships, array('term_taxonomy_id' => $term->term_taxonomy_id));
            
            // Eliminar taxonomía
            $wpdb->delete($wpdb->term_taxonomy, array('term_taxonomy_id' => $term->term_taxonomy_id));
            
            // Eliminar término
            $wpdb->delete($wpdb->terms, array('term_id' => $term->term_id));
            
            $count_deleted++;
        }
        
        // Limpiar caché
        wp_cache_flush();
    }
    
    update_option('mpodb_orphaned_taxonomies_deleted', $count_deleted);
}

/**
 * Limpiar campos ACF huérfanos
 */
function mpodb_clean_acf_orphans() {
    global $wpdb;
    
    // Verificar si ACF está activo
    if (!class_exists('ACF')) {
        return;
    }
    
    // Buscar campos ACF huérfanos en postmeta
    $deleted_count = 0;
    
    // Patrón para identificar meta keys de ACF
    $acf_patterns = array(
        '_acf_%',       // Meta keys de ACF
        'field_%',      // Campos de ACF
        '_field_%'      // Meta para campos
    );
    
    foreach ($acf_patterns as $pattern) {
        // Buscar meta values que no tengan posts asociados
        $orphaned_metas = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_id
            FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key LIKE %s
            AND (p.ID IS NULL OR p.post_status = 'trash')",
            $pattern
        ));
        
        foreach ($orphaned_metas as $meta) {
            $wpdb->delete($wpdb->postmeta, array('meta_id' => $meta->meta_id));
            $deleted_count++;
        }
    }
    
    update_option('mpodb_acf_orphans_deleted', $deleted_count);
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
    $orphaned_posts_deleted = get_option('mpodb_orphaned_posts_deleted', 0);
    $orphaned_taxonomies_deleted = get_option('mpodb_orphaned_taxonomies_deleted', 0);
    $acf_orphans_deleted = get_option('mpodb_acf_orphans_deleted', 0);
    
    return array(
        'current_size' => $current_size,
        'initial_size' => $initial_size,
        'final_size' => $final_size,
        'reduction' => $reduction,
        'reduction_percentage' => $reduction_percentage,
        'status' => $status,
        'last_run_start' => $last_run_start,
        'last_run_end' => $last_run_end,
        'orphaned_posts_deleted' => $orphaned_posts_deleted,
        'orphaned_taxonomies_deleted' => $orphaned_taxonomies_deleted,
        'acf_orphans_deleted' => $acf_orphans_deleted
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