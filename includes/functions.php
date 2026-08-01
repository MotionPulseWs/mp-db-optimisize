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

    // Identificar y eliminar meta datos duplicados.
    // IMPORTANTE: se particiona también por meta_value. WordPress permite
    // legítimamente varias filas con la misma meta_key y valores distintos
    // (add_post_meta con $unique = false), por ejemplo los idiomas de un
    // language switcher de TranslatePress. Sólo son duplicados reales las
    // filas idénticas en post_id + meta_key + meta_value.
    $duplicates = $wpdb->get_results("
        SELECT meta_id
        FROM (
            SELECT meta_id, ROW_NUMBER() OVER (PARTITION BY post_id, meta_key, meta_value ORDER BY meta_id DESC) AS rnum
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

    // NOTA: el borrado de custom post types huérfanos y de taxonomías sin uso
    // ya NO se ejecuta automáticamente. Ambos dependen de que el post type /
    // taxonomía esté registrado en el momento de la consulta, y esta rutina
    // corre por WP-Cron, donde los tipos registrados sólo en el admin (o los
    // de un plugin temporalmente desactivado) no existen. Ejecutarlo automático
    // provocaba pérdida de datos. Ahora se revisa y ejecuta a mano desde el
    // panel del plugin: ver mpodb_scan_orphaned_post_types() y
    // mpodb_scan_orphaned_taxonomies().

    // Limpiar campos ACF huérfanos
    mpodb_clean_acf_orphans();

    // Optimizar tablas. Se pasa por mpodb_is_table_safe_to_touch() para que
    // cualquier tabla protegida (TranslatePress) quede fuera por diseño, aunque
    // en el futuro se amplíe esta lista.
    foreach (array($wpdb->postmeta, $wpdb->posts) as $table) {
        if (mpodb_is_table_safe_to_touch($table)) {
            $wpdb->query("OPTIMIZE TABLE {$table}");
        }
    }

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
 * Comprobar si TranslatePress está activo en la instalación.
 *
 * @return bool
 */
function mpodb_is_translatepress_active() {
    return defined('TRP_PLUGIN_VERSION') || class_exists('TRP_Translate_Press');
}

/**
 * Localizar las tablas propias de TranslatePress presentes en la instalación.
 *
 * El prefijo de WordPress cambia según el cliente/instalación (wp_, twf_...),
 * por eso NUNCA se asume: se toma de $wpdb->prefix en tiempo de ejecución. Lo
 * que sí es constante es el prefijo del plugin, 'trp_', de modo que las tablas
 * siempre siguen el patrón <prefijo_wp>trp_*  (ej. twf_trp_gettext_es_es).
 *
 * Estas tablas contienen las traducciones reales: TranslatePress NO duplica
 * posts por idioma, guarda todo aquí. El optimizador no debe tocarlas nunca.
 *
 * @return array Nombres de tabla encontrados
 */
function mpodb_get_translatepress_tables() {
    global $wpdb;

    // esc_like escapa los '_' para que LIKE no los trate como comodín
    $pattern = $wpdb->esc_like($wpdb->prefix . 'trp_') . '%';

    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));

    return is_array($tables) ? $tables : array();
}

/**
 * Comprobar que una tabla no pertenece a TranslatePress antes de operar
 * sobre ella. Salvaguarda para cualquier operación a nivel de tabla que se
 * añada al plugin en el futuro (OPTIMIZE, DROP, TRUNCATE...).
 *
 * @param string $table Nombre completo de la tabla
 * @return bool True si es seguro operar sobre ella
 */
function mpodb_is_table_safe_to_touch($table) {
    global $wpdb;

    return strpos($table, $wpdb->prefix . 'trp_') !== 0;
}

/**
 * Construir la cláusula SQL que excluye los posts gestionados por
 * TranslatePress (traducciones legítimas, no huérfanos).
 *
 * TranslatePress no expone siempre las mismas tablas según la versión/addon
 * instalado, pero usa consistentemente el prefijo 'trp_' tanto en los post
 * types que registra como en las meta_key con las que vincula un post a su
 * original. La consulta usa $wpdb->postmeta, por lo que respeta el prefijo
 * real de la instalación (wp_, twf_, etc.) y nunca lo asume.
 *
 * IMPORTANTE: el fragmento devuelto NO debe pasarse por $wpdb->prepare().
 * Ya viene listo para concatenarse a una consulta preparada; el prefijo
 * 'trp_' es una constante del código (no entrada de usuario), así que no hay
 * riesgo de inyección. Pasarlo por prepare() rompería el '%' del LIKE.
 *
 * @return string Fragmento SQL (vacío si TranslatePress no está activo)
 */
function mpodb_get_translatepress_exclusion_sql() {
    global $wpdb;

    if (!mpodb_is_translatepress_active()) {
        return '';
    }

    // 'trp\_%' : se escapa el guion bajo para que LIKE lo trate como literal
    return " AND NOT EXISTS (
        SELECT 1 FROM {$wpdb->postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key LIKE 'trp\\_%'
    )";
}

/**
 * Devolver la lista de post types registrados que se consideran válidos.
 *
 * @return array
 */
function mpodb_get_valid_post_types() {
    $registered_post_types = get_post_types(array(), 'names');

    // Post types de core que podrían no aparecer en la lista anterior
    $core_types = array('post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'patterns_ai_data');

    return array_unique(array_merge(array_values($registered_post_types), $core_types));
}

/**
 * Escanear (SIN borrar nada) los post types presentes en la BD que no están
 * registrados actualmente en WordPress.
 *
 * Esta función es de sólo lectura a propósito: que un post type no esté
 * registrado NO significa que sea basura. Puede tratarse de un plugin
 * desactivado temporalmente, de un CPT registrado sólo en el admin, o de
 * traducciones de TranslatePress. Por eso el borrado es una acción manual
 * y explícita del administrador.
 *
 * @return array Lista de arrays con 'post_type' y 'count'
 */
function mpodb_scan_orphaned_post_types() {
    global $wpdb;

    $valid_post_types = mpodb_get_valid_post_types();
    $placeholders = implode(',', array_fill(0, count($valid_post_types), '%s'));

    // Una sola consulta agregada: cuenta por post type ya descontando las
    // traducciones de TranslatePress, sin recorrer post por post.
    // Los fragmentos de exclusión se concatenan DESPUÉS de prepare() para no
    // reinterpretar los '%' de sus cláusulas LIKE.
    $sql = $wpdb->prepare(
        "SELECT p.post_type, COUNT(*) AS total
        FROM {$wpdb->posts} p
        WHERE p.post_type NOT IN ({$placeholders})",
        $valid_post_types
    );

    if (mpodb_is_translatepress_active()) {
        // Excluir los post types propios de TranslatePress
        $sql .= " AND p.post_type NOT LIKE 'trp\\_%'";
        $sql .= mpodb_get_translatepress_exclusion_sql();
    }

    $sql .= ' GROUP BY p.post_type ORDER BY total DESC';

    $rows = $wpdb->get_results($sql);

    $result = array();
    foreach ($rows as $row) {
        $result[] = array(
            'post_type' => $row->post_type,
            'count'     => intval($row->total),
        );
    }

    return $result;
}

/**
 * Obtener los IDs borrables de un post type no registrado, excluyendo los
 * posts gestionados por TranslatePress (traducciones legítimas).
 *
 * @param string $post_type
 * @return array IDs
 */
function mpodb_get_deletable_ids_for_post_type($post_type) {
    global $wpdb;

    // Nunca operar sobre un post type que sí está registrado
    if (in_array($post_type, mpodb_get_valid_post_types(), true)) {
        return array();
    }

    // Nunca operar sobre los post types propios de TranslatePress
    if (mpodb_is_translatepress_active() && strpos($post_type, 'trp_') === 0) {
        return array();
    }

    // El fragmento de exclusión se concatena DESPUÉS de prepare() (ver nota en
    // mpodb_get_translatepress_exclusion_sql)
    $sql = $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s",
        $post_type
    );
    $sql .= mpodb_get_translatepress_exclusion_sql();

    $ids = $wpdb->get_col($sql);

    return array_map('intval', $ids);
}

/**
 * Eliminar manualmente los posts de un post type huérfano concreto.
 * Sólo debe invocarse desde una acción explícita del administrador.
 *
 * @param string $post_type
 * @return int Número de posts eliminados
 */
function mpodb_delete_orphaned_post_type($post_type) {
    global $wpdb;

    $ids = mpodb_get_deletable_ids_for_post_type($post_type);

    if (empty($ids)) {
        return 0;
    }

    $ids_string = implode(',', array_map('intval', $ids));

    // Eliminar relaciones de taxonomía
    $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$ids_string})");

    // Eliminar meta datos
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_string})");

    // Eliminar los posts
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids_string})");

    $total = intval(get_option('mpodb_orphaned_posts_deleted', 0)) + count($ids);
    update_option('mpodb_orphaned_posts_deleted', $total);

    return count($ids);
}

/**
 * Devolver la lista de taxonomías registradas que se consideran válidas.
 *
 * @return array
 */
function mpodb_get_valid_taxonomies() {
    $registered_taxonomies = get_taxonomies(array(), 'names');
    $core_taxonomies = array('category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category');

    return array_unique(array_merge(array_values($registered_taxonomies), $core_taxonomies));
}

/**
 * Escanear (SIN borrar nada) las taxonomías presentes en la BD que no están
 * registradas actualmente. Mismo criterio que con los post types: una
 * taxonomía no registrada puede pertenecer a un plugin desactivado, por lo
 * que el borrado es manual y explícito.
 *
 * @return array Lista de arrays con 'taxonomy' y 'count'
 */
function mpodb_scan_orphaned_taxonomies() {
    global $wpdb;

    $valid_taxonomies = mpodb_get_valid_taxonomies();
    $placeholders = implode(',', array_fill(0, count($valid_taxonomies), '%s'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT tt.taxonomy, COUNT(*) AS total
        FROM {$wpdb->term_taxonomy} tt
        WHERE tt.taxonomy NOT IN ({$placeholders})
        GROUP BY tt.taxonomy
        ORDER BY total DESC",
        $valid_taxonomies
    ));

    $result = array();
    foreach ($rows as $row) {
        $result[] = array(
            'taxonomy' => $row->taxonomy,
            'count'    => intval($row->total),
        );
    }

    return $result;
}

/**
 * Eliminar manualmente los términos de una taxonomía huérfana concreta.
 * Sólo debe invocarse desde una acción explícita del administrador.
 *
 * @param string $taxonomy
 * @return int Número de términos eliminados
 */
function mpodb_delete_orphaned_taxonomy($taxonomy) {
    global $wpdb;

    // Nunca operar sobre una taxonomía que sí está registrada
    if (in_array($taxonomy, mpodb_get_valid_taxonomies(), true)) {
        return 0;
    }

    $orphaned_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT t.term_id, tt.term_taxonomy_id
        FROM {$wpdb->terms} t
        JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = %s",
        $taxonomy
    ));

    $count_deleted = 0;

    foreach ($orphaned_terms as $term) {
        // Eliminar relaciones
        $wpdb->delete($wpdb->term_relationships, array('term_taxonomy_id' => $term->term_taxonomy_id));

        // Eliminar taxonomía
        $wpdb->delete($wpdb->term_taxonomy, array('term_taxonomy_id' => $term->term_taxonomy_id));

        // Eliminar término
        $wpdb->delete($wpdb->terms, array('term_id' => $term->term_id));

        $count_deleted++;
    }

    if ($count_deleted > 0) {
        wp_cache_flush();
        $total = intval(get_option('mpodb_orphaned_taxonomies_deleted', 0)) + $count_deleted;
        update_option('mpodb_orphaned_taxonomies_deleted', $total);
    }

    return $count_deleted;
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