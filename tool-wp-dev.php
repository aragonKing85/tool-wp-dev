<?php
/**
 * Plugin Name: Dev Tools
 * Description: Suite de herramientas de desarrollo: Blog Migrator, Conversor Post→CPT, Redirecciones, Polylang Fixer y Web Inspector.
 * Version:     1.0.0
 * Author:      Iván González
 * Text Domain: tool-wp-dev
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWD_VERSION', '1.0.0' );
define( 'TWD_PATH',    plugin_dir_path( __FILE__ ) );
define( 'TWD_URL',     plugin_dir_url( __FILE__ ) );
define( 'TWD_MENU',    'twd-tools' );

// ── Shared (se carga antes que cualquier módulo) ───────────────────────────────
require_once TWD_PATH . 'modules/shared/security.php';
require_once TWD_PATH . 'modules/shared/helpers.php';
require_once TWD_PATH . 'modules/shared/ui-components.php';

// ── Módulos ───────────────────────────────────────────────────────────────────
// MD Redirects primero: define las clases usadas en los hooks de activación.
require_once TWD_PATH . 'md-redirects/md-redirects.php';
require_once TWD_PATH . 'blog-migrator/blog-migrator.php';
require_once TWD_PATH . 'converter-post-cpt/ptc-basic.php';
require_once TWD_PATH . 'polylang-fix-simulator/polylang-fix-simulator.php';
require_once TWD_PATH . 'modules/web-inspector/web-inspector.php';
require_once TWD_PATH . 'og-img-helper/og-img-helper.php';

// ── Assets globales ────────────────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'twd_register_global_assets', 1 );

/**
 * Registra (sin encolar) los assets globales del plugin.
 *
 * Se hace con priority=1 para que los handles estén disponibles antes de que
 * cualquier módulo declare sus dependencias. Los módulos usan 'twd-admin-global'
 * como dependencia y WordPress lo encola automáticamente cuando lo necesita.
 * El JS sí se encola explícitamente, pero solo en pantallas del plugin.
 */
function twd_register_global_assets(): void {
    // CSS: registrar siempre — los módulos lo declaran como dependencia y WP
    // lo inyecta automáticamente cuando algún módulo activo lo necesite.
    wp_register_style(
        'twd-admin-global',
        TWD_URL . 'assets/css/admin-global.css',
        [],
        TWD_VERSION
    );

    // JS: solo encolarlo en pantallas del plugin.
    if ( twd_is_plugin_screen() ) {
        wp_enqueue_script(
            'twd-admin-global',
            TWD_URL . 'assets/js/admin-global.js',
            [],
            TWD_VERSION,
            true
        );
    }
}

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
