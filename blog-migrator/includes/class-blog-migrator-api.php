<?php
/**
 * Blog Migrator API — Conexión y migración desde sitio WordPress origen.
 *
 * @package Tool_WP_Dev
 * @module  blog-migrator
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase principal para manejar la conexión con la API externa.
 */
class Blog_Migrator_API {

    /** -------------------------------------------------------------------------
     * 1. Comprobar si el dominio tiene API REST accesible
     * ---------------------------------------------------------------------- */
    public static function check_connection() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $domain   = esc_url_raw( $_POST['domain'] ?? '' );
        $endpoint = rtrim( $domain, '/' ) . '/wp-json/wp/v2/posts?per_page=1';

        $response = wp_remote_get( $endpoint, [ 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'No se pudo conectar al dominio.' ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            wp_send_json_error( [ 'message' => 'La API REST no está accesible o no es un WordPress válido.' ] );
        }

        wp_send_json_success( [ 'message' => 'Conexión exitosa a la API REST.' ] );
    }

    /** -------------------------------------------------------------------------
     * 2. Detectar idiomas disponibles (Polylang o WPML)
     * ---------------------------------------------------------------------- */
    public static function get_languages() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $domain = esc_url_raw( $_POST['domain'] ?? '' );
        $langs  = [];

        // Intentar Polylang
        $pll_res = wp_remote_get( rtrim( $domain, '/' ) . '/wp-json/polylang/v1/languages', [ 'timeout' => 15 ] );
        if ( ! is_wp_error( $pll_res ) && wp_remote_retrieve_response_code( $pll_res ) === 200 ) {
            foreach ( json_decode( wp_remote_retrieve_body( $pll_res ), true ) as $lang ) {
                $langs[] = [ 'slug' => $lang['slug'], 'name' => $lang['name'] ];
            }
            wp_send_json_success( [ 'source' => 'polylang', 'languages' => $langs ] );
        }

        // Intentar WPML
        $wpml_res = wp_remote_get( rtrim( $domain, '/' ) . '/wp-json/wpml/v1/languages', [ 'timeout' => 15 ] );
        if ( ! is_wp_error( $wpml_res ) && wp_remote_retrieve_response_code( $wpml_res ) === 200 ) {
            foreach ( json_decode( wp_remote_retrieve_body( $wpml_res ), true ) as $lang ) {
                $langs[] = [ 'slug' => $lang['code'], 'name' => $lang['native_name'] ];
            }
            wp_send_json_success( [ 'source' => 'wpml', 'languages' => $langs ] );
        }

        wp_send_json_success( [ 'source' => 'none', 'languages' => [] ] );
    }

    /** -------------------------------------------------------------------------
     * 3. Obtener TODOS los posts del origen (loop interno, paginación en Vue)
     *
     * Hace peticiones sucesivas de 100 en 100 (límite máximo de WP REST API).
     * Vue se encarga de paginar el listado resultante en cliente.
     * Devuelve: posts[] con id, title, date, date_gmt, status, link, categories[].
     * ---------------------------------------------------------------------- */
    public static function explore_posts() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $domain    = esc_url_raw( $_POST['domain'] ?? '' );
        $lang      = sanitize_text_field( $_POST['lang'] ?? '' );
        $base      = rtrim( $domain, '/' ) . '/wp-json/wp/v2/posts';
        $api_batch = 100; // Máximo permitido por la REST API de WordPress
        $page      = 1;
        $all       = [];

        while ( true ) {
            $endpoint = $base . "?per_page={$api_batch}&page={$page}&_embed";
            if ( $lang ) {
                $endpoint .= '&lang=' . rawurlencode( $lang );
            }

            $response = wp_remote_get( $endpoint, [ 'timeout' => 30 ] );

            if ( is_wp_error( $response ) ) {
                if ( empty( $all ) ) {
                    wp_send_json_error( [ 'message' => 'Error al conectar: ' . $response->get_error_message() ] );
                }
                break;
            }

            $code = wp_remote_retrieve_response_code( $response );

            // 400 = página fuera de rango; 404 = sin resultados
            if ( $code === 400 || $code === 404 ) break;

            if ( $code !== 200 ) {
                if ( empty( $all ) ) {
                    wp_send_json_error( [ 'message' => "Error HTTP {$code} al obtener posts." ] );
                }
                break;
            }

            $raw = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $raw ) || ! is_array( $raw ) ) break;

            foreach ( $raw as $p ) {
                // Extraer nombres de categorías desde los términos embebidos
                $cat_names = [];
                if ( ! empty( $p['_embedded']['wp:term'] ) ) {
                    foreach ( $p['_embedded']['wp:term'] as $term_group ) {
                        foreach ( $term_group as $term ) {
                            if ( ( $term['taxonomy'] ?? '' ) === 'category' ) {
                                $cat_names[] = sanitize_text_field( $term['name'] );
                            }
                        }
                    }
                }

                $all[] = [
                    'id'         => intval( $p['id'] ),
                    'title'      => wp_strip_all_tags( $p['title']['rendered'] ),
                    'date'       => sanitize_text_field( $p['date'] ),
                    'date_gmt'   => sanitize_text_field( $p['date_gmt'] ?? '' ),
                    'status'     => sanitize_text_field( $p['status'] ?? 'publish' ),
                    'link'       => esc_url( $p['link'] ),
                    'categories' => $cat_names,
                ];
            }

            // Si devolvió menos de api_batch, no hay más páginas
            if ( count( $raw ) < $api_batch ) break;

            // Límite de seguridad: máximo 200 páginas × 100 = 20 000 posts
            if ( $page++ >= 200 ) break;
        }

        wp_send_json_success( [
            'posts' => $all,
            'count' => count( $all ),
        ] );
    }

    /** -------------------------------------------------------------------------
     * 4. Importar posts (LEGACY — mantenido para compatibilidad)
     * ---------------------------------------------------------------------- */
    public static function import_posts() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $domain   = esc_url_raw( $_POST['domain'] ?? '' );
        $selected = json_decode( stripslashes( $_POST['selected'] ?? '[]' ), true );

        if ( empty( $selected ) ) {
            wp_send_json_error( [ 'message' => 'No se recibieron posts para importar.' ] );
        }

        $imported = [];

        foreach ( $selected as $s ) {
            $post_id  = intval( $s['id'] );
            $status   = sanitize_text_field( $s['status'] ?? 'draft' );
            $endpoint = rtrim( $domain, '/' ) . '/wp-json/wp/v2/posts/' . $post_id . '?_embed';

            $response = wp_remote_get( $endpoint, [ 'timeout' => 20 ] );
            if ( is_wp_error( $response ) ) continue;

            $p = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $p['title']['rendered'] ) ) continue;

            $new_post = [
                'post_title'    => wp_strip_all_tags( $p['title']['rendered'] ),
                'post_content'  => $p['content']['rendered'],
                'post_status'   => $status,
                'post_date'     => sanitize_text_field( $p['date'] ),
                'post_date_gmt' => sanitize_text_field( $p['date_gmt'] ?? '' ),
                'post_author'   => get_current_user_id(),
            ];

            $new_id = wp_insert_post( $new_post );
            if ( is_wp_error( $new_id ) ) continue;

            self::assign_terms( $p, $new_id, $domain );
            self::assign_featured_image( $p, $new_id );

            $imported[] = $new_id;
        }

        wp_send_json_success( [
            'message' => 'Importación completada.',
            'count'   => count( $imported ),
        ] );
    }

    /** -------------------------------------------------------------------------
     * 5. BATCHING: Iniciar job de importación
     * ---------------------------------------------------------------------- */
    public static function start_import() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $domain          = esc_url_raw( $_POST['domain'] ?? '' );
        $selected        = json_decode( stripslashes( $_POST['selected'] ?? '[]' ), true );
        $batch_size      = intval( $_POST['batch_size'] ?? 25 );
        $post_status_mode = sanitize_text_field( $_POST['post_status_mode'] ?? 'original' );

        // Whitelist de modos de estado
        $allowed_modes = [ 'original', 'draft', 'publish', 'pending', 'private' ];
        if ( ! in_array( $post_status_mode, $allowed_modes, true ) ) {
            $post_status_mode = 'original';
        }

        if ( empty( $selected ) ) {
            wp_send_json_error( [ 'message' => 'No se recibieron posts para importar.' ] );
        }

        $job   = new Blog_Migrator_Job_State();
        $state = $job->init( $selected, [
            'domain'           => $domain,
            'batch_size'       => $batch_size,
            'post_status_mode' => $post_status_mode,
        ] );

        $job->update( [ 'status' => 'running' ] );

        wp_send_json_success( [
            'message'   => 'Job iniciado correctamente.',
            'job_state' => $state,
        ] );
    }

    /** -------------------------------------------------------------------------
     * 6. BATCHING: Procesar un lote con reintentos
     * ---------------------------------------------------------------------- */
    public static function process_batch() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $batch_index = intval( $_POST['batch_index'] ?? 0 );
        $job         = new Blog_Migrator_Job_State();
        $state       = $job->get();

        if ( ! $state ) {
            wp_send_json_error( [ 'message' => 'No hay job activo.' ] );
        }

        $batch_posts = $job->get_batch_posts( $batch_index );
        if ( empty( $batch_posts ) ) {
            wp_send_json_error( [ 'message' => 'Lote vacío.' ] );
        }

        $domain      = $state['domain'];
        $max_retries = 3;
        $backoff     = [ 1, 3, 7 ];

        for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
            try {
                $result = self::import_batch( $batch_posts, $domain, $job );

                $job->increment_processed( count( $batch_posts ) );
                $job->increment_imported( $result['imported_count'] );
                $job->increment_failed( $result['failed_count'] );
                $job->update( [ 'current_batch' => $batch_index + 1 ] );

                // Si era el último lote, marcar el job como completado
                $fresh = $job->get();
                if ( $fresh && $fresh['current_batch'] >= $fresh['total_batches'] ) {
                    $job->mark_complete();
                }

                wp_send_json_success( [
                    'batch'    => $batch_index,
                    'imported' => $result['imported_count'],
                    'failed'   => $result['failed_count'],
                    'attempt'  => $attempt + 1,
                ] );
                return;

            } catch ( Exception $e ) {
                if ( $attempt < $max_retries ) {
                    sleep( $backoff[ $attempt ] );
                } else {
                    $job->add_error( $batch_index, [
                        'posts'    => array_map( fn( $p ) => [ 'id' => $p['id'], 'title' => $p['title'] ?? 'N/A' ], $batch_posts ),
                        'message'  => $e->getMessage(),
                        'attempts' => $max_retries + 1,
                    ] );
                    $job->increment_failed( count( $batch_posts ) );
                    $job->increment_processed( count( $batch_posts ) );
                    $job->update( [ 'current_batch' => $batch_index + 1 ] );

                    // Si era el último lote, marcar el job como completado
                    $fresh = $job->get();
                    if ( $fresh && $fresh['current_batch'] >= $fresh['total_batches'] ) {
                        $job->mark_complete();
                    }

                    wp_send_json_success( [
                        'batch'   => $batch_index,
                        'skipped' => true,
                        'error'   => $e->getMessage(),
                    ] );
                    return;
                }
            }
        }
    }

    /** -------------------------------------------------------------------------
     * 7. BATCHING: Importar un lote (método privado reutilizable)
     *
     * CORRECCIÓN DE BUGS:
     * - post_status_mode se aplica correctamente desde el job state.
     * - post_date_gmt se pasa a wp_insert_post para respetar la fecha original.
     * ---------------------------------------------------------------------- */
    private static function import_batch( $batch_posts, $domain, $job ) {
        $imported_count = 0;
        $failed_count   = 0;

        // Leer modo de estado desde el job state (fix: antes se ignoraba)
        $state            = $job->get();
        $post_status_mode = sanitize_text_field( $state['post_status_mode'] ?? 'original' );
        $allowed_statuses = [ 'publish', 'draft', 'pending', 'private' ];

        foreach ( $batch_posts as $s ) {
            $post_id = intval( $s['id'] );

            // Idempotencia: no reimportar si ya fue procesado
            if ( $job->get_imported_post_id( $post_id ) ) {
                continue;
            }

            $endpoint = rtrim( $domain, '/' ) . '/wp-json/wp/v2/posts/' . $post_id . '?_embed';
            $response = wp_remote_get( $endpoint, [ 'timeout' => 20 ] );

            if ( is_wp_error( $response ) ) {
                error_log( "[Blog Migrator] Failed to fetch post {$post_id}: " . $response->get_error_message() );
                $failed_count++;
                continue;
            }

            $p = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $p['title']['rendered'] ) ) {
                $failed_count++;
                continue;
            }

            // Determinar estado: el modo global tiene prioridad sobre el individual
            if ( $post_status_mode === 'original' ) {
                // Usar el estado original del post en el origen
                $status = sanitize_text_field( $s['status'] ?? $p['status'] ?? 'draft' );
            } else {
                // Sobreescribir con el modo global
                $status = in_array( $post_status_mode, $allowed_statuses, true )
                    ? $post_status_mode
                    : 'draft';
            }

            // Crear post respetando fecha original (fix: antes faltaba post_date_gmt)
            $new_post = [
                'post_title'    => wp_strip_all_tags( $p['title']['rendered'] ),
                'post_content'  => $p['content']['rendered'],
                'post_status'   => $status,
                'post_date'     => sanitize_text_field( $p['date'] ),
                'post_date_gmt' => sanitize_text_field( $p['date_gmt'] ?? '' ),
                'post_author'   => get_current_user_id(),
            ];

            $new_id = wp_insert_post( $new_post );

            if ( is_wp_error( $new_id ) ) {
                error_log( "[Blog Migrator] Failed to create post {$post_id}: " . $new_id->get_error_message() );
                $failed_count++;
                continue;
            }

            $job->add_imported_post( $post_id, $new_id );

            self::assign_terms( $p, $new_id, $domain );
            self::assign_featured_image( $p, $new_id );

            $imported_count++;
        }

        return [
            'imported_count' => $imported_count,
            'failed_count'   => $failed_count,
        ];
    }

    /** -------------------------------------------------------------------------
     * 8. BATCHING: Obtener estado del job
     * ---------------------------------------------------------------------- */
    public static function get_job_status() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $job   = new Blog_Migrator_Job_State();
        $state = $job->get();

        if ( ! $state ) {
            wp_send_json_success( [ 'exists' => false ] );
        } else {
            wp_send_json_success( [ 'exists' => true, 'state' => $state ] );
        }
    }

    /** -------------------------------------------------------------------------
     * 9. BATCHING: Cancelar/Resetear job
     * ---------------------------------------------------------------------- */
    public static function cancel_job() {
        check_ajax_referer( 'bm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        ( new Blog_Migrator_Job_State() )->reset();

        wp_send_json_success( [ 'message' => 'Job cancelado.' ] );
    }

    /** -------------------------------------------------------------------------
     * Helpers privados — categorías/etiquetas e imagen destacada
     * ---------------------------------------------------------------------- */

    /**
     * Asigna categorías y etiquetas a un post importado.
     *
     * @param array  $p      Datos del post desde la API origen.
     * @param int    $new_id ID del post destino.
     * @param string $domain Dominio del origen.
     */
    private static function assign_terms( $p, $new_id, $domain ) {
        if ( ! empty( $p['categories'] ) ) {
            $cat_ids = [];
            foreach ( $p['categories'] as $cat_id ) {
                $dest_id = Blog_Migrator_Term_Mapper::resolve_term( $cat_id, 'category', $domain );
                if ( $dest_id ) $cat_ids[] = $dest_id;
            }
            if ( ! empty( $cat_ids ) ) {
                wp_set_post_terms( $new_id, $cat_ids, 'category' );
            }
        }

        if ( ! empty( $p['tags'] ) ) {
            $tag_ids = [];
            foreach ( $p['tags'] as $tag_id ) {
                $dest_id = Blog_Migrator_Term_Mapper::resolve_term( $tag_id, 'post_tag', $domain );
                if ( $dest_id ) $tag_ids[] = $dest_id;
            }
            if ( ! empty( $tag_ids ) ) {
                wp_set_post_terms( $new_id, $tag_ids, 'post_tag' );
            }
        }
    }

    /**
     * Descarga y asigna la imagen destacada a un post importado.
     *
     * @param array $p      Datos del post desde la API origen.
     * @param int   $new_id ID del post destino.
     */
    private static function assign_featured_image( $p, $new_id ) {
        if ( empty( $p['_embedded']['wp:featuredmedia'][0]['source_url'] ) ) return;

        $image_url = $p['_embedded']['wp:featuredmedia'][0]['source_url'];
        $tmp       = download_url( $image_url );

        if ( is_wp_error( $tmp ) ) return;

        $file = [
            'name'     => basename( $image_url ),
            'type'     => mime_content_type( $tmp ),
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize( $tmp ),
        ];

        $file_info = wp_handle_sideload( $file, [ 'test_form' => false ] );
        if ( isset( $file_info['error'] ) ) return;

        $attach_id = wp_insert_attachment( [
            'post_mime_type' => $file_info['type'],
            'post_title'     => sanitize_file_name( $file_info['file'] ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $file_info['file'], $new_id );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file_info['file'] ) );
        set_post_thumbnail( $new_id, $attach_id );
    }
}
