<?php
/*
Plugin Name: MotionPulse Optimize Database
Description: Plugin para reducir la base de datos, activar y desactivar inmediatamente
Version: 1.2
Author: Kadir Kevin
*/

function optimize_database() {
    global $wpdb;

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
}

// Agregar un evento cron para optimizar la base de datos periódicamente
function schedule_optimize_database() {
    if (!wp_next_scheduled('optimize_database_event')) {
        wp_schedule_event(time(), 'daily', 'optimize_database_event');
    }
}
add_action('wp', 'schedule_optimize_database');

// Desactivar el evento cron al desactivar el plugin
function unschedule_optimize_database() {
    $timestamp = wp_next_scheduled('optimize_database_event');
    wp_unschedule_event($timestamp, 'optimize_database_event');
}
register_deactivation_hook(__FILE__, 'unschedule_optimize_database');

// Agregar el hook para la función de optimización
add_action('optimize_database_event', 'optimize_database');
?>
