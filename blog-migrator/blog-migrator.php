<?php

// Evitar acceso directo
if ( !defined('ABSPATH') ) exit;

// Definir rutas del plugin
define('BM_PATH', plugin_dir_path(__FILE__));
define('BM_URL', plugin_dir_url(__FILE__));

// Incluir archivos necesarios
require_once BM_PATH . 'includes/class-blog-migrator-term-mapper.php';
require_once BM_PATH . 'includes/class-blog-migrator-job-state.php';
require_once BM_PATH . 'includes/class-blog-migrator-api.php';

// Registrar la página y encolar sus scripts en el mismo callback de admin_menu
// para usar el hookname real que devuelve add_submenu_page().
add_action('admin_menu', function() {
    $hook = add_submenu_page(
        TWD_MENU,
        'Blog Migrator',
        'Blog Migrator',
        'manage_options',
        'blog-migrator',
        'bm_render_admin_page'
    );

    add_action('admin_enqueue_scripts', function($current_hook) use ($hook) {
        if ($current_hook !== $hook) return;

        // Vue 3 desde CDN
        wp_enqueue_script('vue-cdn', 'https://unpkg.com/vue@3/dist/vue.global.prod.js', [], null, true);

        // Script principal del plugin
        wp_enqueue_script('blog-migrator-js', BM_URL . 'assets/blog-migrator.js', ['vue-cdn'], '1.0', true);

        wp_localize_script('blog-migrator-js', 'bm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('bm_nonce')
        ]);
    });
});
// Incluir la página del admin
function bm_render_admin_page() {
    include BM_PATH . 'admin/admin-page.php';
}

// AJAX: Comprobar conexión
add_action('wp_ajax_bm_check_connection', ['Blog_Migrator_API', 'check_connection']);

// AJAX: Explorar posts
add_action('wp_ajax_bm_explore_posts', ['Blog_Migrator_API', 'explore_posts']);

// AJAX: Obtener idiomas
add_action('wp_ajax_bm_get_languages', ['Blog_Migrator_API', 'get_languages']);

// AJAX: Importar posts (LEGACY - mantenido para compatibilidad)
add_action('wp_ajax_bm_import_posts', ['Blog_Migrator_API', 'import_posts']);

// AJAX: Iniciar job de importación (BATCHING)
add_action('wp_ajax_bm_start_import', ['Blog_Migrator_API', 'start_import']);

// AJAX: Procesar siguiente lote
add_action('wp_ajax_bm_process_batch', ['Blog_Migrator_API', 'process_batch']);

// AJAX: Obtener estado del job
add_action('wp_ajax_bm_get_job_status', ['Blog_Migrator_API', 'get_job_status']);

// AJAX: Cancelar/Resetear job
add_action('wp_ajax_bm_cancel_job', ['Blog_Migrator_API', 'cancel_job']);
