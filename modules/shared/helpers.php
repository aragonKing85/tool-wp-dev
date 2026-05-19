<?php
/**
 * Helpers — Funciones de utilidad compartidas entre herramientas.
 *
 * Añadir aquí cualquier función PHP genérica que se use en 2 o más módulos.
 *
 * @package Tool_WP_Dev
 * @module  shared
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Comprueba si la pantalla actual pertenece al plugin Dev Tools.
 *
 * @return bool True si la pantalla tiene el prefijo 'twd-tools'.
 */
function twd_is_plugin_screen(): bool {
    $screen = get_current_screen();
    return $screen && strpos( $screen->id, TWD_MENU ) !== false;
}

/**
 * Devuelve la URL pública de una imagen dado su ID de adjunto.
 * Si no existe, devuelve una cadena vacía.
 *
 * @param  int    $attachment_id  ID del adjunto en WordPress.
 * @param  string $size           Tamaño de imagen (default 'full').
 * @return string                 URL de la imagen o cadena vacía.
 */
function twd_get_image_url( int $attachment_id, string $size = 'full' ): string {
    if ( ! $attachment_id ) return '';
    $src = wp_get_attachment_image_url( $attachment_id, $size );
    return $src ?: '';
}
