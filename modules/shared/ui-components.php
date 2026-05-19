<?php
/**
 * UI Components — Componentes HTML reutilizables del plugin.
 *
 * Renderiza bloques de interfaz comunes: cabeceras de página, badges de estado,
 * botones de guardar, etc. Usar estas funciones garantiza coherencia visual.
 *
 * @package Tool_WP_Dev
 * @module  shared
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renderiza la cabecera estándar de una herramienta del plugin.
 *
 * @param string $icon        Emoji o carácter Unicode del icono.
 * @param string $title       Título de la herramienta.
 * @param string $description Descripción breve (subtítulo).
 */
function twd_render_page_header( string $icon, string $title, string $description ): void {
    ?>
    <div class="twd-page-header">
        <span class="twd-page-header__icon"><?php echo esc_html( $icon ); ?></span>
        <div>
            <h1 class="twd-page-header__title"><?php echo esc_html( $title ); ?></h1>
            <p class="twd-page-header__desc"><?php echo esc_html( $description ); ?></p>
        </div>
    </div>
    <?php
}

/**
 * Renderiza un badge de estado de post.
 *
 * @param string $status Estado del post (publish, draft, pending, private, future).
 * @param array  $labels Mapa status → etiqueta. Usa los valores por defecto si no se pasa.
 */
function twd_render_status_badge( string $status, array $labels = [] ): void {
    $default_labels = [
        'publish' => 'Publicado',
        'draft'   => 'Borrador',
        'pending' => 'Pendiente',
        'private' => 'Privado',
        'future'  => 'Programado',
    ];

    $map   = array_merge( $default_labels, $labels );
    $label = $map[ $status ] ?? esc_html( $status );
    $class = 'twd-badge twd-badge--' . sanitize_html_class( $status );

    echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Renderiza el botón estándar de guardar cambios con nonce incluido.
 *
 * @param string $text        Texto del botón (default 'Guardar cambios').
 * @param string $nonce_action Acción del nonce.
 */
function twd_render_save_button( string $text = 'Guardar cambios', string $nonce_action = '' ): void {
    if ( $nonce_action ) {
        wp_nonce_field( $nonce_action );
    }
    ?>
    <button type="submit" class="twd-btn twd-btn--primary">
        <?php echo esc_html( $text ); ?>
    </button>
    <?php
}
