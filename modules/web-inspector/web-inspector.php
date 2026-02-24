<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Submenu ───────────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'twd_wi_admin_menu' );

function twd_wi_admin_menu() {
    add_submenu_page(
        TWD_MENU,
        'Web Inspector',
        'Web Inspector',
        'manage_options',
        'twd-web-inspector',
        'twd_wi_render_page'
    );
}

// ── Botón toggle en la admin bar (frontend) ───────────────────────────────────
add_action( 'admin_bar_menu', 'twd_wi_admin_bar_node', 100 );

function twd_wi_admin_bar_node( $wp_admin_bar ) {
    if ( is_admin() ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $wp_admin_bar->add_node( [
        'id'    => 'twd-web-inspector',
        'title' => '&#128269; Web Inspector',
        'href'  => '#',
        'meta'  => [ 'onclick' => 'return twdWiToggle(event)' ],
    ] );
}

// ── CSS: fija jerarquía de z-index (panel > bar > overlay) ───────────────────
// Ambos #__inspector-panel__ y #__inspector-root__ comparten z-index 2147483647
// en el UMD, pero el bar (appended a <html> después de <body>) gana por orden DOM.
// Bajamos el bar un nivel para que el panel SEO siempre quede encima.
add_action( 'wp_head', 'twd_wi_zindex_css' );

function twd_wi_zindex_css() {
    if ( ! is_admin_bar_showing() ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    echo '<style id="twd-wi-zindex">'
        . '#__inspector-panel__   { z-index: 2147483647 !important; }'
        . '#__inspector-root__    { z-index: 2147483646 !important; }'
        . '#__inspector-overlay__ { z-index: 2147483645 !important; }'
        . '</style>';
}

// ── Script en footer: toggle del inspector con botón de finalizar ─────────────
add_action( 'wp_footer', 'twd_wi_frontend_script' );

function twd_wi_frontend_script() {
    if ( ! is_admin_bar_showing() ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $src = wp_json_encode( esc_url( TWD_URL . 'modules/web-inspector/assets/web-inspector.umd.js' ) );
    ?>
    <script>
    (function () {
        var SCRIPT_SRC = <?php echo $src; ?>;

        // Reaplica z-index vía JS tras cargar el script (refuerzo sobre el CSS de wp_head)
        function twdWiFixZindex() {
            setTimeout(function () {
                var map = {
                    '__inspector-panel__'  : '2147483647',
                    '__inspector-root__'   : '2147483646',
                    '__inspector-overlay__': '2147483645'
                };
                Object.keys(map).forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.style.setProperty('z-index', map[id], 'important');
                });
            }, 80);
        }

        function twdWiGetBtn() {
            return document.querySelector('#wp-admin-bar-twd-web-inspector .ab-item');
        }

        // Retira el admin bar del DOM para que no sea auditado,
        // ejecuta la callback y lo restituye en su posición original.
        function twdWiWithoutAdminBar(callback) {
            var bar    = document.getElementById('wpadminbar');
            var parent = bar ? bar.parentNode : null;
            var next   = bar ? bar.nextSibling : null;
            if (bar && parent) parent.removeChild(bar);
            try { callback(); } finally {
                if (bar && parent) parent.insertBefore(bar, next);
            }
        }

        window.twdWiToggle = function (e) {
            e.preventDefault();
            var btn = twdWiGetBtn();

            if (!window.__twdWiLoaded) {
                // Primera carga: sacar el admin bar, cargar el script (auto-init
                // ocurre sincrónicamente durante la ejecución del script),
                // restaurar en onload.
                var bar    = document.getElementById('wpadminbar');
                var parent = bar ? bar.parentNode : null;
                var next   = bar ? bar.nextSibling : null;
                if (bar && parent) parent.removeChild(bar);

                var s = document.createElement('script');
                s.src = SCRIPT_SRC;
                s.onload = function () {
                    if (bar && parent) parent.insertBefore(bar, next);
                    window.__twdWiLoaded = true;
                    window.__twdWiActive = true;
                    if (btn) btn.textContent = '\u2715 Finalizar audit';
                    twdWiFixZindex();
                };
                s.onerror = function () {
                    if (bar && parent) parent.insertBefore(bar, next);
                };
                document.head.appendChild(s);

            } else if (window.HTMLInspector) {
                if (window.__twdWiActive !== false) {
                    // Inspector activo → destruir completamente
                    window.HTMLInspector.destroy();
                    window.__twdWiActive = false;
                    if (btn) btn.textContent = '\uD83D\uDD0D Web Inspector';
                } else {
                    // Inspector destruido → re-inicializar sin auditar el admin bar
                    twdWiWithoutAdminBar(function () {
                        window.HTMLInspector.init();
                    });
                    window.__twdWiActive = true;
                    if (btn) btn.textContent = '\u2715 Finalizar audit';
                    twdWiFixZindex();
                }
            }
            return false;
        };
    })();
    </script>
    <?php
}

// ── Página de admin ───────────────────────────────────────────────────────────
function twd_wi_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $src        = TWD_URL . 'modules/web-inspector/assets/web-inspector.umd.js';
    $bookmarklet = 'javascript:(function(){if(window.__twdWiLoaded)return;var s=document.createElement("script");s.src="' . esc_js( $src ) . '";s.onload=function(){window.__twdWiLoaded=true;window.__twdWiActive=true;};document.head.appendChild(s);})();';
    ?>
    <div class="wrap">
        <h1>Web Inspector</h1>
        <p>Herramienta de auditoría DOM y SEO. Analiza accesibilidad, estructura de cabeceras, Open Graph, rendimiento y más en cualquier página.</p>

        <h2>Opción 1 &mdash; Botón en la barra de administración</h2>
        <p>Visita cualquier página pública del sitio. El botón <strong>&#128269; Web Inspector</strong> en la barra superior activa el inspector. Una vez activo, el mismo botón cambia a <strong>&#x2715; Finalizar audit</strong> para cerrarlo por completo.</p>

        <h2>Opción 2 &mdash; Bookmarklet <em>(funciona en cualquier sitio)</em></h2>
        <p>Arrastra el siguiente enlace a la barra de marcadores de tu navegador:</p>
        <p>
            <a href="<?php echo esc_attr( $bookmarklet ); ?>"
               style="display:inline-block;padding:8px 18px;background:#2271b1;color:#fff;border-radius:4px;text-decoration:none;font-weight:600;font-size:14px;">
                &#128269; Web Inspector
            </a>
        </p>
        <p><em>Una vez guardado, visita cualquier URL y haz clic en el marcador para activar el inspector.</em></p>

        <h2>Categorías auditadas</h2>
        <ul style="list-style:disc;padding-left:1.5em;line-height:2">
            <li><strong>Informaci&oacute;n</strong>: alt en im&aacute;genes, IDs duplicados, roles ARIA, tabindex, labels en inputs</li>
            <li><strong>Cabeceras</strong>: estructura H1-H6, m&uacute;ltiples H1, H1 vac&iacute;o</li>
            <li><strong>Enlaces</strong>: href vac&iacute;o, href="#", texto de ancla pobre, aria en enlaces</li>
            <li><strong>Open Graph</strong>: og:title, og:image</li>
            <li><strong>Indexaci&oacute;n</strong>: canonical, viewport, noindex, meta description (presencia y longitud)</li>
            <li><strong>Rendimiento</strong>: im&aacute;genes sin lazy-load, scripts sin defer, formatos no modernos, dimensiones ausentes, theme-color</li>
        </ul>
    </div>
    <?php
}
