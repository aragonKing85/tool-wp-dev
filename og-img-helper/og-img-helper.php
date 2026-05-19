<?php
/**
 * OG IMG Helper — Gestión de meta tags Open Graph para el sitio.
 *
 * Permite activar/desactivar la inyección de og:image, og:image:width,
 * og:image:height y twitter:image en el <head> del front-end.
 * Si el post/página tiene imagen destacada, ésta tiene prioridad sobre
 * la imagen global configurada aquí.
 *
 * @package Tool_WP_Dev
 * @module  og-img-helper
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constantes del módulo ─────────────────────────────────────────────────────
define( 'TWD_OG_PATH', plugin_dir_path( __FILE__ ) );
define( 'TWD_OG_URL',  plugin_dir_url( __FILE__ ) );

// ── Submenú ───────────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'twd_og_admin_menu' );

/**
 * Registra la página de configuración en el menú de Dev Tools.
 */
function twd_og_admin_menu() {
    $hook = add_submenu_page(
        TWD_MENU,
        'OG IMG Helper',
        'OG IMG Helper',
        'manage_options',
        'twd-og-img-helper',
        'twd_og_render_settings'
    );

    add_action( 'admin_enqueue_scripts', function ( $current_hook ) use ( $hook ) {
        if ( $current_hook !== $hook ) return;
        twd_og_enqueue_assets();
    } );
}

// ── Assets ────────────────────────────────────────────────────────────────────

/**
 * Carga CSS, JS y el Media Uploader de WP en la pantalla de configuración.
 */
function twd_og_enqueue_assets() {
    wp_enqueue_media();

    wp_enqueue_style(
        'twd-og-helper',
        TWD_OG_URL . 'assets/og-helper.css',
        [ 'twd-admin-global' ],
        TWD_VERSION
    );

    wp_enqueue_script(
        'twd-og-helper',
        TWD_OG_URL . 'assets/og-helper.js',
        [ 'jquery' ],
        TWD_VERSION,
        true
    );

    wp_localize_script( 'twd-og-helper', 'twdOG', [
        'mediaTitle'  => __( 'Seleccionar imagen OG', 'tool-wp-dev' ),
        'mediaButton' => __( 'Usar esta imagen', 'tool-wp-dev' ),
        'siteName'    => get_bloginfo( 'name' ),
        'siteUrl'     => parse_url( home_url(), PHP_URL_HOST ),
        'pageTitle'   => get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' ),
    ] );
}

// ── Guardar configuración ─────────────────────────────────────────────────────

/**
 * Procesa y guarda la configuración del formulario.
 */
function twd_og_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para realizar esta acción.', 'tool-wp-dev' ) );
    }

    check_admin_referer( 'twd_save_og_settings' );

    // Imagen global
    $active    = isset( $_POST['twd_og_active'] ) ? 1 : 0;
    $image_id  = absint( $_POST['twd_og_image_id'] ?? 0 );
    $image_url = $image_id > 0 ? esc_url_raw( wp_get_attachment_url( $image_id ) ) : '';

    update_option( 'twd_og_active',    $active );
    update_option( 'twd_og_image_id',  $image_id );
    update_option( 'twd_og_image_url', $image_url );

    // Metadatos globales
    $allowed_types = [ 'website', 'article', 'blog' ];
    $og_type       = sanitize_text_field( $_POST['twd_og_type'] ?? 'website' );
    if ( ! in_array( $og_type, $allowed_types, true ) ) {
        $og_type = 'website';
    }

    update_option( 'twd_og_title',       sanitize_text_field( $_POST['twd_og_title']       ?? '' ) );
    update_option( 'twd_og_description', sanitize_textarea_field( $_POST['twd_og_description'] ?? '' ) );
    update_option( 'twd_og_type',        $og_type );

    wp_safe_redirect(
        add_query_arg( [ 'page' => 'twd-og-img-helper', 'saved' => '1' ], admin_url( 'admin.php' ) )
    );
    exit;
}

add_action( 'admin_post_twd_og_save', 'twd_og_save_settings' );

// ── Render página de configuración ───────────────────────────────────────────

/**
 * Renderiza la pantalla de configuración del módulo.
 */
function twd_og_render_settings() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    require_once TWD_OG_PATH . 'views/settings.php';
}

// ── Inyección de meta tags en el front-end ────────────────────────────────────
add_action( 'wp_head', 'twd_og_inject_meta', 1 );

/**
 * Inyecta las meta tags OG en el <head> del front-end.
 *
 * Lógica de prioridad para cada campo:
 * - og:title       → título del post/página > título global > nombre del sitio
 * - og:description → excerpt del post > descripción global > tagline del sitio
 * - og:type        → "article" si es singular/post > tipo global configurado
 * - og:url         → URL canónica actual
 * - og:site_name   → nombre del sitio (siempre de WordPress)
 * - og:image       → imagen destacada del post > imagen global configurada
 *
 * @return void
 */
function twd_og_inject_meta() {
    if ( ! get_option( 'twd_og_active', 0 ) ) return;

    $image_url    = '';
    $image_width  = '';
    $image_height = '';
    $og_title     = '';
    $og_desc      = '';
    $og_type      = get_option( 'twd_og_type', 'website' );
    $og_url       = '';

    if ( is_singular() ) {
        $post_id = get_queried_object_id();
        $post    = get_post( $post_id );

        // Título: del post
        $og_title = get_the_title( $post_id );

        // Descripción: excerpt o contenido recortado
        if ( $post && ! empty( $post->post_excerpt ) ) {
            $og_desc = wp_strip_all_tags( $post->post_excerpt );
        } elseif ( $post ) {
            $og_desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '…' );
        }

        // Tipo: posts y páginas → article
        if ( is_single() || is_page() ) {
            $og_type = 'article';
        }

        // URL canónica
        $og_url = get_permalink( $post_id );

        // Imagen destacada
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id ) {
            $meta = wp_get_attachment_image_src( $thumbnail_id, 'full' );
            if ( $meta ) {
                $image_url    = $meta[0];
                $image_width  = $meta[1];
                $image_height = $meta[2];
            }
        }
    } else {
        $og_url = home_url( $_SERVER['REQUEST_URI'] ?? '/' );
    }

    // Fallbacks globales configurados
    if ( empty( $og_title ) ) {
        $og_title = get_option( 'twd_og_title', '' ) ?: get_bloginfo( 'name' );
    }
    if ( empty( $og_desc ) ) {
        $og_desc = get_option( 'twd_og_description', '' ) ?: get_bloginfo( 'description' );
    }

    // Imagen global si no hay imagen del post
    if ( empty( $image_url ) ) {
        $global_id = absint( get_option( 'twd_og_image_id', 0 ) );
        if ( $global_id > 0 ) {
            $meta = wp_get_attachment_image_src( $global_id, 'full' );
            if ( $meta ) {
                $image_url    = $meta[0];
                $image_width  = $meta[1];
                $image_height = $meta[2];
            }
        } else {
            $image_url = get_option( 'twd_og_image_url', '' );
        }
    }

    ?>
    <!-- OG IMG Helper (tool-wp-dev) -->
    <meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
    <meta property="og:type"      content="<?php echo esc_attr( $og_type ); ?>" />
    <?php if ( $og_url ) : ?>
    <meta property="og:url"       content="<?php echo esc_url( $og_url ); ?>" />
    <?php endif; ?>
    <?php if ( $og_title ) : ?>
    <meta property="og:title"       content="<?php echo esc_attr( $og_title ); ?>" />
    <meta name="twitter:title"      content="<?php echo esc_attr( $og_title ); ?>" />
    <?php endif; ?>
    <?php if ( $og_desc ) : ?>
    <meta property="og:description"  content="<?php echo esc_attr( $og_desc ); ?>" />
    <meta name="twitter:description" content="<?php echo esc_attr( $og_desc ); ?>" />
    <?php endif; ?>
    <?php if ( $image_url ) : ?>
    <meta property="og:image"        content="<?php echo esc_url( $image_url ); ?>" />
    <meta name="twitter:image"       content="<?php echo esc_url( $image_url ); ?>" />
    <meta name="twitter:card"        content="summary_large_image" />
    <?php if ( $image_width ) : ?>
    <meta property="og:image:width"  content="<?php echo esc_attr( $image_width ); ?>" />
    <?php endif; ?>
    <?php if ( $image_height ) : ?>
    <meta property="og:image:height" content="<?php echo esc_attr( $image_height ); ?>" />
    <?php endif; ?>
    <?php endif; ?>
    <?php
}
