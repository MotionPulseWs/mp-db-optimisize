<?php
/*
Plugin Name: MotionPulse Optimize Database
Description: Busca y elimina meta datos huérfanos y duplicados en la base de datos.
Version: 2.1
Author: Kadir Kevin
*/

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('MPODB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPODB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MPODB_VERSION', '1.3');

// Incluir archivos de funciones
require_once MPODB_PLUGIN_DIR . 'includes/functions.php';
require_once MPODB_PLUGIN_DIR . 'admin/admin-page.php';

// Activar el plugin
function mpodb_activate() {
    // Programar la primera ejecución inmediata
    wp_schedule_single_event(time() + 10, 'mpodb_optimize_database_event');
    
    // Programar ejecuciones diarias
    if (!wp_next_scheduled('mpodb_optimize_database_event_daily')) {
        wp_schedule_event(time() + 86400, 'daily', 'mpodb_optimize_database_event_daily');
    }
    
    // Establecer una opción para mostrar mensaje de bienvenida
    add_option('mpodb_show_welcome', true);
}
register_activation_hook(__FILE__, 'mpodb_activate');

// Desactivar el plugin
function mpodb_deactivate() {
    // Cancelar eventos programados
    $timestamp = wp_next_scheduled('mpodb_optimize_database_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'mpodb_optimize_database_event');
    }
    
    $timestamp_daily = wp_next_scheduled('mpodb_optimize_database_event_daily');
    if ($timestamp_daily) {
        wp_unschedule_event($timestamp_daily, 'mpodb_optimize_database_event_daily');
    }
}
register_deactivation_hook(__FILE__, 'mpodb_deactivate');

// Hooks para las funciones de optimización
add_action('mpodb_optimize_database_event', 'mpodb_optimize_database');
add_action('mpodb_optimize_database_event_daily', 'mpodb_optimize_database');

// Agregar menú de administración
add_action('admin_menu', 'mpodb_add_admin_menu');

// Registrar assets (CSS y JS)
function mpodb_enqueue_admin_assets($hook) {
    // Verificar si estamos en la página de nuestro plugin
    if ('toplevel_page_mpodb-optimize' !== $hook) {
        return;
    }
    
    // Registrar y encolar el CSS
    wp_register_style(
        'mpodb-admin-style', 
        MPODB_PLUGIN_URL . 'admin/css/admin-style.css', 
        array(), 
        MPODB_VERSION
    );
    wp_enqueue_style('mpodb-admin-style');
    
    // Registrar y encolar el JavaScript
    wp_register_script(
        'mpodb-admin-script', 
        MPODB_PLUGIN_URL . 'admin/js/admin-script.js', 
        array('jquery'), 
        MPODB_VERSION, 
        true
    );
    wp_enqueue_script('mpodb-admin-script');
    
    // Pasar variables a JavaScript
    wp_localize_script('mpodb-admin-script', 'mpodb_vars', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mpodb_refresh_nonce')
    ));
}
add_action('admin_enqueue_scripts', 'mpodb_enqueue_admin_assets');

// Registrar endpoint AJAX para actualizar información
add_action('wp_ajax_mpodb_refresh_stats', 'mpodb_ajax_refresh_stats');