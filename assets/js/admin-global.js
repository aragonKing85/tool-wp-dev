/**
 * Dev Tools — JS global del plugin.
 *
 * Se carga en TODAS las pantallas del plugin.
 * Contiene: utilidades compartidas disponibles para todos los módulos.
 *
 * @package Tool_WP_Dev
 */

( function () {
    'use strict';

    /**
     * Escapa caracteres HTML para evitar XSS en strings dinámicos.
     *
     * @param  {string} str  Cadena a escapar.
     * @return {string}      Cadena con caracteres especiales escapados.
     */
    window.twdEscHtml = function ( str ) {
        return String( str )
            .replace( /&/g,  '&amp;'  )
            .replace( /</g,  '&lt;'   )
            .replace( />/g,  '&gt;'   )
            .replace( /"/g,  '&quot;' )
            .replace( /'/g,  '&#039;' );
    };

} )();
