<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Submenu ───────────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'twd_wi_admin_menu' );

function twd_wi_admin_menu() {
    $hook = add_submenu_page(
        TWD_MENU,
        'Web Inspector',
        'Web Inspector',
        'manage_options',
        'twd-web-inspector',
        'twd_wi_render_page'
    );

    add_action( 'admin_enqueue_scripts', function ( $current_hook ) use ( $hook ) {
        if ( $current_hook !== $hook ) return;
        wp_enqueue_style(
            'twd-wi-admin',
            TWD_URL . 'modules/web-inspector/assets/web-inspector-admin.css',
            [ 'twd-admin-global' ],
            TWD_VERSION
        );
    } );
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

    $src         = TWD_URL . 'modules/web-inspector/assets/web-inspector.umd.js';
    $bookmarklet = 'javascript:(function(){if(window.__twdWiLoaded)return;var s=document.createElement("script");s.src="' . esc_js( $src ) . '";s.onload=function(){window.__twdWiLoaded=true;window.__twdWiActive=true;};document.head.appendChild(s);})();';
    ?>
    <div class="twd-wi-wrap">

        <!-- ── Header ──────────────────────────────────────────────────────── -->
        <div class="twd-wi-header">
            <span class="twd-wi-icon">&#128269;</span>
            <div>
                <h1>Web Inspector</h1>
                <p>Auditoría DOM y SEO en tiempo real. Analiza accesibilidad, cabeceras, Open Graph, indexación y rendimiento en cualquier página.</p>
            </div>
        </div>

        <!-- ── Opciones de activación ───────────────────────────────────────── -->
        <div class="twd-wi-options">

            <!-- Opción 1: Admin bar -->
            <div class="twd-wi-option">
                <div class="twd-wi-option__header">
                    <span class="twd-wi-option__num">1</span>
                    <h2>Botón en la barra de administración</h2>
                </div>
                <div class="twd-wi-option__body">
                    <div class="twd-wi-bar-demo">
                        <span class="twd-wi-bar-demo__dot"></span>
                        &#128269; Web Inspector
                    </div>
                    <p>Visita cualquier página pública del sitio. El botón <strong>&#128269; Web Inspector</strong> en la barra superior activa el inspector.</p>
                    <p>Una vez activo, cambia a <strong>&#x2715; Finalizar audit</strong> para cerrarlo.</p>
                </div>
            </div>

            <!-- Opción 2: Bookmarklet -->
            <div class="twd-wi-option">
                <div class="twd-wi-option__header">
                    <span class="twd-wi-option__num">2</span>
                    <h2>Bookmarklet — funciona en cualquier sitio</h2>
                </div>
                <div class="twd-wi-option__body">
                    <div class="twd-wi-bookmarklet-wrap">
                        <p>Arrastra este botón a tu barra de marcadores:</p>
                        <a href="<?php echo esc_attr( $bookmarklet ); ?>" class="twd-wi-bookmarklet-link">
                            &#128269; Web Inspector
                        </a>
                    </div>
                    <p class="twd-wi-hint">&#8505; Una vez guardado, visita cualquier URL y haz clic en el marcador para activar el inspector.</p>
                </div>
            </div>

        </div>

        <!-- ── Categorías auditadas ─────────────────────────────────────────── -->
        <div class="twd-wi-card">
            <div class="twd-wi-card__header">
                <h2>Categorías auditadas</h2>
            </div>
            <div class="twd-wi-card__body">
                <div class="twd-wi-cats">

                    <div class="twd-wi-cat">
                        <div class="twd-wi-cat__title">
                            <span class="twd-wi-cat__icon">&#9432;</span> Información
                        </div>
                        <ul class="twd-wi-cat__items">
                            <li>Alt en imágenes</li>
                            <li>IDs duplicados</li>
                            <li>Roles ARIA</li>
                            <li>Tabindex</li>
                            <li>Labels en inputs</li>
                        </ul>
                    </div>

                    <div class="twd-wi-cat">
                        <div class="twd-wi-cat__title">
                            <span class="twd-wi-cat__icon">&#35;</span> Cabeceras
                        </div>
                        <ul class="twd-wi-cat__items">
                            <li>Estructura H1-H6</li>
                            <li>Múltiples H1</li>
                            <li>H1 vacío</li>
                        </ul>
                    </div>

                    <div class="twd-wi-cat">
                        <div class="twd-wi-cat__title">
                            <span class="twd-wi-cat__icon">&#128279;</span> Enlaces
                        </div>
                        <ul class="twd-wi-cat__items">
                            <li>href vacío o "#"</li>
                            <li>Texto de ancla pobre</li>
                            <li>ARIA en enlaces</li>
                        </ul>
                    </div>

                    <div class="twd-wi-cat">
                        <div class="twd-wi-cat__title">
                            <span class="twd-wi-cat__icon">&#128247;</span> Open Graph
                        </div>
                        <ul class="twd-wi-cat__items">
                            <li>og:title</li>
                            <li>og:image</li>
                        </ul>
                    </div>

                    <div class="twd-wi-cat">
                        <div class="twd-wi-cat__title">
                            <span class="twd-wi-cat__icon">&#128269;</span> Indexación
                        </div>
                        <ul class="twd-wi-cat__items">
                            <li>Canonical</li>
                            <li>Viewport</li>
                            <li>Noindex</li>
                            <li>Meta description (presencia y longitud)</li>
                        </ul>
                    </div>

                    <div class="twd-wi-cat">
                        <div class="twd-wi-cat__title">
                            <span class="twd-wi-cat__icon">&#9889;</span> Rendimiento
                        </div>
                        <ul class="twd-wi-cat__items">
                            <li>Imágenes sin lazy-load</li>
                            <li>Scripts sin defer</li>
                            <li>Formatos no modernos</li>
                            <li>Dimensiones ausentes</li>
                            <li>Theme-color</li>
                        </ul>
                    </div>

                </div>
            </div>
        </div>

    </div><!-- /.twd-wi-wrap -->
    <?php
}
