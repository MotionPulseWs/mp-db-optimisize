<?php
/*
Plugin Name: MotionPulse Optimize Database
Description: Plugin para reducir la base de datos.
Version: 1.2
Author: Kadir Kevin
*/

function optimize_database() {
    global $wpdb;

    // Eliminar meta datos huérfanos
    $wpdb->query(
        "DELETE pm FROM bp_postmeta pm
        LEFT JOIN bp_posts wp ON wp.ID = pm.post_id
        WHERE wp.ID IS NULL"
    );

    // Identificar y eliminar meta datos duplicados
    $duplicates = $wpdb->get_results("
        SELECT meta_id
        FROM (
            SELECT meta_id, ROW_NUMBER() OVER (PARTITION BY post_id, meta_key ORDER BY meta_id DESC) AS rnum
            FROM bp_postmeta
        ) t
        WHERE t.rnum > 1
    ");

    foreach ($duplicates as $duplicate) {
        $wpdb->delete('bp_postmeta', array('meta_id' => $duplicate->meta_id));
    }

    // Eliminar revisiones de posts
    $wpdb->query(
        "DELETE FROM bp_postmeta
        WHERE post_type = 'revision'"
    );

    // Eliminar posts en la papelera
    $wpdb->query(
        "DELETE FROM bp_postmeta
        WHERE post_status = 'trash'"
    );

    // Optimizar tablas
    $wpdb->query("OPTIMIZE TABLE bp_postmeta");
    $wpdb->query("OPTIMIZE TABLE bp_posts");

    echo "Limpieza y optimización completadas.";
}

register_activation_hook(__FILE__, 'optimize_database');
