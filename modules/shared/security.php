<?php
/**
 * Security — Funciones de seguridad compartidas entre herramientas.
 *
 * Centraliza la verificación de nonce + permisos para peticiones AJAX,
 * evitando repetir el mismo bloque en cada método de cada módulo.
 *
 * @package Tool_WP_Dev
 * @module  shared
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Verifica nonce y permisos del usuario en una petición AJAX.
 *
 * Si la verificación falla, envía wp_send_json_error y detiene la ejecución.
 *
 * @param string $nonce_action Acción del nonce (ej: 'bm_nonce').
 * @param string $nonce_key    Clave del campo nonce en $_POST (default 'nonce').
 * @param string $capability   Capacidad requerida (default 'manage_options').
 */
function twd_verify_ajax_request(
    string $nonce_action,
    string $nonce_key   = 'nonce',
    string $capability  = 'manage_options'
): void {
    check_ajax_referer( $nonce_action, $nonce_key );

    if ( ! current_user_can( $capability ) ) {
        wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
    }
}
