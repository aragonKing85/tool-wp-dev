<?php
/**
 * Plugin Name: Dev Tools
 * Description: Suite de herramientas de desarrollo: Blog Migrator, Conversor Post→CPT, Redirecciones, Polylang Fixer y Web Inspector.
 * Version:     1.0.0
 * Author:      Mindset Digital
 * Text Domain: tool-wp-dev
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWD_VERSION', '1.0.0' );
define( 'TWD_PATH',    plugin_dir_path( __FILE__ ) );
define( 'TWD_URL',     plugin_dir_url( __FILE__ ) );
define( 'TWD_MENU',    'twd-tools' );

// ── Módulos ───────────────────────────────────────────────────────────────────
// MD Redirects primero: define las clases usadas en los hooks de activación.
require_once TWD_PATH . 'md-redirects/md-redirects.php';
require_once TWD_PATH . 'blog-migrator/blog-migrator.php';
require_once TWD_PATH . 'converter-post-cpt/ptc-basic.php';
require_once TWD_PATH . 'polylang-fix-simulator/polylang-fix-simulator.php';
require_once TWD_PATH . 'modules/web-inspector/web-inspector.php';

// ── Activation / Deactivation (crea/elimina tabla DB de MD Redirects) ─────────
register_activation_hook( __FILE__, [ 'MDR_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'MDR_Deactivator', 'deactivate' ] );

// ── Menú principal (priority 5: se registra antes que los submenús) ────────────
add_action( 'admin_menu', 'twd_register_main_menu', 5 );

function twd_register_main_menu() {
    add_menu_page(
        'Dev Tools',
        'Dev Tools',
        'manage_options',
        TWD_MENU,
        'twd_render_dashboard',
        'dashicons-admin-tools',
        80
    );
}

function twd_render_dashboard() {
    echo '<div class="wrap"><h1>Dev Tools</h1><p>Selecciona una herramienta en el menú lateral.</p></div>';
}
