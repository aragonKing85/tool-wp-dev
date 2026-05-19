<?php
/**
 * OG IMG Helper — Vista de configuración del admin.
 *
 * @package Tool_WP_Dev
 * @module  og-img-helper
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$og_active      = (bool) get_option( 'twd_og_active', 0 );
$og_image_id    = absint( get_option( 'twd_og_image_id', 0 ) );
$og_image_url   = esc_url( get_option( 'twd_og_image_url', '' ) );
$og_title       = get_option( 'twd_og_title', '' );
$og_description = get_option( 'twd_og_description', '' );
$og_type        = get_option( 'twd_og_type', 'website' );

$thumb_url = '';
if ( $og_image_id > 0 ) {
    $thumb_src = wp_get_attachment_image_src( $og_image_id, 'medium' );
    if ( $thumb_src ) {
        $thumb_url = $thumb_src[0];
    }
}

$saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
?>
<div class="twd-og-wrap">

    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="twd-og-header">
        <div class="twd-og-header__title">
            <span class="twd-og-icon">&#128247;</span>
            <div>
                <h1>OG IMG Helper</h1>
                <p>Controla la imagen que se muestra al compartir en redes sociales y mensajería.</p>
            </div>
        </div>
    </div>

    <?php if ( $saved ) : ?>
    <div class="twd-og-notice twd-og-notice--success">
        &#10003; Configuración guardada correctamente.
    </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="twd_og_save">
        <?php wp_nonce_field( 'twd_save_og_settings' ); ?>

        <!-- ── Columnas ───────────────────────────────────────────────────── -->
        <div class="twd-og-layout">

            <!-- Col izquierda: controles -->
            <div class="twd-og-panel">

                <!-- Switch activación -->
                <div class="twd-og-card">
                    <div class="twd-og-card__header">
                        <h2>Estado de la herramienta</h2>
                    </div>
                    <div class="twd-og-card__body twd-og-card__body--row">
                        <label class="twd-og-switch" for="twd_og_active">
                            <input
                                type="checkbox"
                                id="twd_og_active"
                                name="twd_og_active"
                                value="1"
                                <?php checked( $og_active ); ?>
                            >
                            <span class="twd-og-switch__track">
                                <span class="twd-og-switch__thumb"></span>
                            </span>
                        </label>
                        <div class="twd-og-switch__label">
                            <strong id="twd-og-status-label">
                                <?php echo $og_active ? 'Activo' : 'Inactivo'; ?>
                            </strong>
                            <span>
                                <?php echo $og_active
                                    ? 'Las meta tags OG se inyectan en el <code>&lt;head&gt;</code>.'
                                    : 'No se inyecta ninguna meta tag OG.'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Selector de imagen -->
                <div class="twd-og-card">
                    <div class="twd-og-card__header">
                        <h2>Imagen OG global</h2>
                        <p>Se usará cuando el post o página no tenga imagen destacada propia.</p>
                    </div>
                    <div class="twd-og-card__body">
                        <input type="hidden" id="twd_og_image_id" name="twd_og_image_id" value="<?php echo esc_attr( $og_image_id ); ?>">

                        <div class="twd-og-img-preview" id="twd-og-img-preview">
                            <?php if ( $thumb_url ) : ?>
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="Imagen OG actual" id="twd-og-thumb">
                            <?php else : ?>
                                <div class="twd-og-img-preview__placeholder" id="twd-og-placeholder">
                                    <span>&#128247;</span>
                                    <p>Sin imagen seleccionada</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="twd-og-img-actions">
                            <button type="button" class="twd-og-btn twd-og-btn--primary" id="twd-og-select-img">
                                <?php echo $og_image_id ? '&#9998; Cambiar imagen' : '&#128247; Seleccionar imagen'; ?>
                            </button>
                            <?php if ( $og_image_id ) : ?>
                            <button type="button" class="twd-og-btn twd-og-btn--ghost" id="twd-og-remove-img">
                                &#128465; Eliminar imagen
                            </button>
                            <?php endif; ?>
                        </div>

                        <p class="twd-og-hint">
                            Tamaño recomendado: <strong>1200 × 630 px</strong>. Formatos: JPG, PNG o WebP.
                        </p>
                    </div>
                </div>

                <!-- Metadatos OG globales -->
                <div class="twd-og-card">
                    <div class="twd-og-card__header">
                        <h2>Metadatos OG globales</h2>
                        <p>Valores de reserva cuando el post o página no define los suyos propios.</p>
                    </div>
                    <div class="twd-og-card__body">

                        <!-- og:title -->
                        <div class="twd-og-field">
                            <label class="twd-og-field__label" for="twd_og_title">
                                <code>og:title</code>
                                <span class="twd-og-field__tag">Genérico</span>
                            </label>
                            <input
                                type="text"
                                id="twd_og_title"
                                name="twd_og_title"
                                class="twd-input"
                                value="<?php echo esc_attr( $og_title ); ?>"
                                placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                                maxlength="95"
                            >
                            <p class="twd-og-field__hint">Si está vacío se usa el nombre del sitio. Los posts usan su propio título automáticamente.</p>
                        </div>

                        <!-- og:description -->
                        <div class="twd-og-field">
                            <label class="twd-og-field__label" for="twd_og_description">
                                <code>og:description</code>
                                <span class="twd-og-field__tag">Genérico</span>
                            </label>
                            <textarea
                                id="twd_og_description"
                                name="twd_og_description"
                                class="twd-textarea"
                                rows="3"
                                maxlength="200"
                                placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>"
                            ><?php echo esc_textarea( $og_description ); ?></textarea>
                            <p class="twd-og-field__hint">Si está vacío se usa el tagline del sitio. Los posts usan su excerpt automáticamente.</p>
                        </div>

                        <!-- og:type -->
                        <div class="twd-og-field">
                            <label class="twd-og-field__label" for="twd_og_type">
                                <code>og:type</code>
                                <span class="twd-og-field__tag">Genérico</span>
                            </label>
                            <select id="twd_og_type" name="twd_og_type" class="twd-select">
                                <option value="website" <?php selected( $og_type, 'website' ); ?>>website — Sitio web genérico</option>
                                <option value="article" <?php selected( $og_type, 'article' ); ?>>article — Blog / publicaciones</option>
                                <option value="blog"    <?php selected( $og_type, 'blog' ); ?>>blog — Blog</option>
                            </select>
                            <p class="twd-og-field__hint">Los posts y páginas individuales usan <code>article</code> automáticamente.</p>
                        </div>

                        <!-- og:site_name (solo lectura) -->
                        <div class="twd-og-field">
                            <label class="twd-og-field__label">
                                <code>og:site_name</code>
                                <span class="twd-og-field__tag twd-og-field__tag--auto">Automático</span>
                            </label>
                            <div class="twd-og-readonly">
                                <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
                            </div>
                            <p class="twd-og-field__hint">Se obtiene siempre del nombre del sitio en WordPress.</p>
                        </div>

                    </div>
                </div>

                <!-- Botón guardar -->
                <div class="twd-og-save-row">
                    <button type="submit" class="twd-og-btn twd-og-btn--save">
                        &#10003; Guardar configuración
                    </button>
                </div>
            </div>

            <!-- Col derecha: previsualizador -->
            <div class="twd-og-preview-panel">
                <div class="twd-og-card">
                    <div class="twd-og-card__header">
                        <h2>Previsualizador</h2>
                        <div class="twd-og-tabs" role="tablist">
                            <button type="button" class="twd-og-tab twd-og-tab--active" data-tab="social" role="tab">
                                &#127760; Redes sociales
                            </button>
                            <button type="button" class="twd-og-tab" data-tab="whatsapp" role="tab">
                                &#128172; WhatsApp
                            </button>
                        </div>
                    </div>
                    <div class="twd-og-card__body">

                        <!-- Vista redes sociales (Facebook / LinkedIn) -->
                        <div class="twd-og-preview twd-og-preview--social" id="twd-preview-social">
                            <div class="twd-og-preview__img-wrap">
                                <?php if ( $og_image_url ) : ?>
                                    <img src="<?php echo esc_url( $og_image_url ); ?>" alt="Preview OG" id="twd-social-img">
                                <?php else : ?>
                                    <div class="twd-og-preview__no-img" id="twd-social-img">
                                        <span>&#128247;</span>
                                        <p>Sin imagen seleccionada</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="twd-og-preview__info">
                                <span class="twd-og-preview__domain"><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ); ?></span>
                                <strong class="twd-og-preview__title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
                                <span class="twd-og-preview__desc"><?php echo esc_html( get_bloginfo( 'description' ) ); ?></span>
                            </div>
                        </div>

                        <!-- Vista WhatsApp -->
                        <div class="twd-og-preview twd-og-preview--whatsapp twd-og-preview--hidden" id="twd-preview-whatsapp">
                            <div class="twd-og-wa-bubble">
                                <div class="twd-og-wa-thumb">
                                    <?php if ( $og_image_url ) : ?>
                                        <img src="<?php echo esc_url( $og_image_url ); ?>" alt="Preview WhatsApp" id="twd-wa-img">
                                    <?php else : ?>
                                        <div class="twd-og-wa-thumb__placeholder" id="twd-wa-img">
                                            <span>&#128247;</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="twd-og-wa-info">
                                    <strong class="twd-og-wa-info__title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
                                    <span class="twd-og-wa-info__url"><?php echo esc_html( home_url() ); ?></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Información sobre prioridad -->
                <div class="twd-og-info-box">
                    <strong>&#9432; Prioridad de imagen</strong>
                    <ol>
                        <li>Imagen destacada del post/página</li>
                        <li>Imagen global configurada aquí</li>
                    </ol>
                </div>
            </div>

        </div><!-- /.twd-og-layout -->

    </form>

</div><!-- /.twd-og-wrap -->
