<?php
if ( !defined('ABSPATH') ) exit;

/**
 * Clase principal para manejar la conexión con la API externa
 */
class Blog_Migrator_API {

    /** ------------------------------
     * 1️⃣ Comprobar si el dominio tiene API REST accesible
     * ------------------------------ */
    public static function check_connection() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        // Capability check
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $domain = esc_url_raw($_POST['domain']);
        $endpoint = rtrim($domain, '/') . '/wp-json/wp/v2/posts?per_page=1';

        $response = wp_remote_get($endpoint, ['timeout' => 15]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'No se pudo conectar al dominio.']);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            wp_send_json_error(['message' => 'La API REST no está accesible o no es un WordPress válido.']);
        }

        wp_send_json_success(['message' => 'Conexión exitosa a la API REST.']);
    }

    /** ------------------------------
     * 2️⃣ Detectar idiomas disponibles (Polylang o WPML)
     * ------------------------------ */
    public static function get_languages() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        // Capability check
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $domain = esc_url_raw($_POST['domain']);
        $langs = [];

        // Intentar Polylang
        $pll_endpoint = rtrim($domain, '/') . '/wp-json/polylang/v1/languages';
        $pll_res = wp_remote_get($pll_endpoint, ['timeout' => 15]);

        if (!is_wp_error($pll_res) && wp_remote_retrieve_response_code($pll_res) === 200) {
            $data = json_decode(wp_remote_retrieve_body($pll_res), true);
            foreach ($data as $lang) {
                $langs[] = [
                    'slug' => $lang['slug'],
                    'name' => $lang['name']
                ];
            }
            wp_send_json_success(['source' => 'polylang', 'languages' => $langs]);
        }

        // Intentar WPML
        $wpml_endpoint = rtrim($domain, '/') . '/wp-json/wpml/v1/languages';
        $wpml_res = wp_remote_get($wpml_endpoint, ['timeout' => 15]);

        if (!is_wp_error($wpml_res) && wp_remote_retrieve_response_code($wpml_res) === 200) {
            $data = json_decode(wp_remote_retrieve_body($wpml_res), true);
            foreach ($data as $lang) {
                $langs[] = [
                    'slug' => $lang['code'],
                    'name' => $lang['native_name']
                ];
            }
            wp_send_json_success(['source' => 'wpml', 'languages' => $langs]);
        }

        // Ninguna API disponible
        wp_send_json_success(['source' => 'none', 'languages' => []]);
    }

    /** ------------------------------
     * 3️⃣ Obtener listado de posts (con soporte de idioma)
     * ------------------------------ */
    public static function explore_posts() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        // Capability check
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $domain = esc_url_raw($_POST['domain']);
        $lang = sanitize_text_field($_POST['lang'] ?? '');
        $base_endpoint = rtrim($domain, '/') . '/wp-json/wp/v2/posts';
        $page = 1;
        $per_page = 20;
        $all_posts = [];

        while (true) {
            $endpoint = $base_endpoint . "?per_page={$per_page}&page={$page}&_embed";
            if ($lang) $endpoint .= "&lang={$lang}";

            $response = wp_remote_get($endpoint, ['timeout' => 20]);
            if (is_wp_error($response)) {
                wp_send_json_error(['message' => 'Error al conectar: ' . $response->get_error_message()]);
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code === 400 || $code === 404) break;
            if ($code !== 200) wp_send_json_error(['message' => "Error HTTP {$code}"]);

            $posts = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($posts)) break;

            foreach ($posts as $p) {
                $all_posts[] = [
                    'id'    => $p['id'],
                    'title' => $p['title']['rendered'],
                    'date'  => $p['date'],
                    'link'  => $p['link'],
                ];
            }

            if (count($posts) < $per_page) break;
            if ($page++ > 200) break;
        }

        wp_send_json_success(['posts' => $all_posts, 'count' => count($all_posts)]);
    }

    /** ------------------------------
     * 4️⃣ Importar posts con categorías, etiquetas e imagen destacada
     * ------------------------------ */
    public static function import_posts() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        // Capability check
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $domain = esc_url_raw($_POST['domain']);
        $selected = json_decode(stripslashes($_POST['selected'] ?? '[]'), true);

        if (empty($selected)) wp_send_json_error(['message' => 'No se recibieron posts para importar.']);

        $imported = [];

        foreach ($selected as $s) {
            $post_id = intval($s['id']);
            $status  = sanitize_text_field($s['status'] ?? 'draft');
            $endpoint = rtrim($domain, '/') . '/wp-json/wp/v2/posts/' . $post_id . '?_embed';

            $response = wp_remote_get($endpoint, ['timeout' => 20]);
            if (is_wp_error($response)) continue;

            $p = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($p['title']['rendered'])) continue;

            // Crear el post
            $new_post = [
                'post_title'   => wp_strip_all_tags($p['title']['rendered']),
                'post_content' => $p['content']['rendered'],
                'post_status'  => $status,
                'post_date'    => $p['date'],
                'post_author'  => get_current_user_id(),
            ];

            $new_id = wp_insert_post($new_post);
            if (is_wp_error($new_id)) {
                error_log("[Blog Migrator] Failed to create post: " . $new_id->get_error_message());
                continue;
            }

            // 🏷️ Categorías - Usar Term Mapper para matching correcto
            if (!empty($p['categories'])) {
                $cat_ids = [];
                foreach ($p['categories'] as $cat_id) {
                    $dest_term_id = Blog_Migrator_Term_Mapper::resolve_term($cat_id, 'category', $domain);
                    if ($dest_term_id) {
                        $cat_ids[] = $dest_term_id;
                    }
                }
                
                // Aplicar términos DESPUÉS de crear el post
                if (!empty($cat_ids)) {
                    $result = wp_set_post_terms($new_id, $cat_ids, 'category');
                    if (is_wp_error($result)) {
                        error_log("[Blog Migrator] Failed to set categories for post {$new_id}: " . $result->get_error_message());
                    } else {
                        error_log("[Blog Migrator] Post {$new_id} assigned " . count($cat_ids) . " categories: " . implode(',', $cat_ids));
                    }
                } else {
                    error_log("[Blog Migrator] WARNING: Post {$new_id} has NO categories assigned (origin had " . count($p['categories']) . " categories)");
                }
            }

            // 🏷️ Etiquetas - Usar Term Mapper para matching correcto
            if (!empty($p['tags'])) {
                $tag_ids = [];
                foreach ($p['tags'] as $tag_id) {
                    $dest_term_id = Blog_Migrator_Term_Mapper::resolve_term($tag_id, 'post_tag', $domain);
                    if ($dest_term_id) {
                        $tag_ids[] = $dest_term_id;
                    }
                }
                
                // Aplicar términos DESPUÉS de crear el post
                if (!empty($tag_ids)) {
                    $result = wp_set_post_terms($new_id, $tag_ids, 'post_tag');
                    if (is_wp_error($result)) {
                        error_log("[Blog Migrator] Failed to set tags for post {$new_id}: " . $result->get_error_message());
                    } else {
                        error_log("[Blog Migrator] Post {$new_id} assigned " . count($tag_ids) . " tags: " . implode(',', $tag_ids));
                    }
                }
            }

            // 🖼️ Imagen destacada
            if (!empty($p['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                $image_url = $p['_embedded']['wp:featuredmedia'][0]['source_url'];
                $tmp = download_url($image_url);

                if (!is_wp_error($tmp)) {
                    $file = [
                        'name'     => basename($image_url),
                        'type'     => mime_content_type($tmp),
                        'tmp_name' => $tmp,
                        'error'    => 0,
                        'size'     => filesize($tmp),
                    ];

                    $overrides = ['test_form' => false];
                    $file_info = wp_handle_sideload($file, $overrides);

                    if (!isset($file_info['error'])) {
                        $attachment = [
                            'post_mime_type' => $file_info['type'],
                            'post_title'     => sanitize_file_name($file_info['file']),
                            'post_content'   => '',
                            'post_status'    => 'inherit',
                        ];
                        $attach_id = wp_insert_attachment($attachment, $file_info['file'], $new_id);
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        $attach_data = wp_generate_attachment_metadata($attach_id, $file_info['file']);
                        wp_update_attachment_metadata($attach_id, $attach_data);
                        set_post_thumbnail($new_id, $attach_id);
                    }
                }
            }

            $imported[] = $new_id;
        }

        wp_send_json_success([
            'message' => 'Importación completada (con categorías, etiquetas e imagen destacada).',
            'count'   => count($imported)
        ]);
    }

    /** ------------------------------
     * 5️⃣ BATCHING: Iniciar job de importación
     * ------------------------------ */
    public static function start_import() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $domain = esc_url_raw($_POST['domain']);
        $selected = json_decode(stripslashes($_POST['selected'] ?? '[]'), true);
        $batch_size = intval($_POST['batch_size'] ?? 25);
        
        // 🎛️ Sanitizar modo de estado (whitelist strict)
        $post_status_mode = sanitize_text_field($_POST['post_status_mode'] ?? 'original');
        $allowed_modes = ['original', 'draft', 'publish'];
        if (!in_array($post_status_mode, $allowed_modes, true)) {
            $post_status_mode = 'original'; // Fallback seguro
        }

        if (empty($selected)) {
            wp_send_json_error(['message' => 'No se recibieron posts para importar.']);
        }

        // Inicializar job
        $job = new Blog_Migrator_Job_State();
        $state = $job->init($selected, [
            'domain' => $domain,
            'batch_size' => $batch_size,
            'post_status_mode' => $post_status_mode, // Guardar modo
        ]);

        // Actualizar estado a 'running'
        $job->update(['status' => 'running']);

        wp_send_json_success([
            'message' => 'Job iniciado correctamente.',
            'job_state' => $state,
        ]);
    }

    /** ------------------------------
     * 6️⃣ BATCHING: Procesar un lote con reintentos
     * ------------------------------ */
    public static function process_batch() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $batch_index = intval($_POST['batch_index'] ?? 0);

        $job = new Blog_Migrator_Job_State();
        $state = $job->get();

        if (!$state) {
            wp_send_json_error(['message' => 'No hay job activo.']);
        }

        // Obtener posts del lote
        $batch_posts = $job->get_batch_posts($batch_index);
        
        if (empty($batch_posts)) {
            wp_send_json_error(['message' => 'Lote vacío.']);
        }

        $domain = $state['domain'];
        $max_retries = 3;
        $backoff = [1, 3, 7]; // segundos

        // Intentar procesar el lote con reintentos
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            try {
                // Procesar lote
                $result = self::import_batch($batch_posts, $domain, $job);

                // Éxito: actualizar contadores
                $job->increment_processed(count($batch_posts));
                $job->increment_imported($result['imported_count']);
                $job->increment_failed($result['failed_count']);
                $job->update(['current_batch' => $batch_index + 1]);

                wp_send_json_success([
                    'batch' => $batch_index,
                    'imported' => $result['imported_count'],
                    'failed' => $result['failed_count'],
                    'attempt' => $attempt + 1,
                ]);
                return;

            } catch (Exception $e) {
                // Error: intentar reintento
                if ($attempt < $max_retries) {
                    // Esperar antes de reintentar
                    sleep($backoff[$attempt]);
                    error_log("[Blog Migrator] Batch {$batch_index} failed (attempt " . ($attempt + 1) . "): {$e->getMessage()}. Retrying...");
                } else {
                    // Tras 3 reintentos, saltar lote
                    error_log("[Blog Migrator] Batch {$batch_index} SKIPPED after {$max_retries} attempts: {$e->getMessage()}");
                    
                    $job->add_error($batch_index, [
                        'posts' => array_map(function($p) {
                            return ['id' => $p['id'], 'title' => $p['title'] ?? 'N/A'];
                        }, $batch_posts),
                        'message' => $e->getMessage(),
                        'attempts' => $max_retries + 1,
                    ]);

                    $job->increment_failed(count($batch_posts));
                    $job->increment_processed(count($batch_posts));
                    $job->update(['current_batch' => $batch_index + 1]);

                    wp_send_json_success([
                        'batch' => $batch_index,
                        'skipped' => true,
                        'error' => $e->getMessage(),
                        'attempts' => $max_retries + 1,
                    ]);
                    return;
                }
            }
        }
    }

    /** ------------------------------
     * 7️⃣ BATCHING: Importar un lote de posts (método privado reutilizable)
     * ------------------------------ */
    private static function import_batch($batch_posts, $domain, $job) {
        $imported_count = 0;
        $failed_count = 0;

        foreach ($batch_posts as $s) {
            $post_id = intval($s['id']);
            $status  = sanitize_text_field($s['status'] ?? 'draft');
            
            // Verificar si ya fue importado (idempotencia)
            $existing = $job->get_imported_post_id($post_id);
            if ($existing) {
                error_log("[Blog Migrator] Post {$post_id} already imported as {$existing}, skipping");
                continue;
            }

            $endpoint = rtrim($domain, '/') . '/wp-json/wp/v2/posts/' . $post_id . '?_embed';

            $response = wp_remote_get($endpoint, ['timeout' => 20]);
            
            if (is_wp_error($response)) {
                error_log("[Blog Migrator] Failed to fetch post {$post_id}: " . $response->get_error_message());
                $failed_count++;
                continue;
            }

            $p = json_decode(wp_remote_retrieve_body($response), true);
            
            if (empty($p['title']['rendered'])) {
                error_log("[Blog Migrator] Post {$post_id} has no title, skipping");
                $failed_count++;
                continue;
            }

            // Crear el post (REUTILIZA LÓGICA DEL COMMIT 1)
            $new_post = [
                'post_title'   => wp_strip_all_tags($p['title']['rendered']),
                'post_content' => $p['content']['rendered'],
                'post_status'  => $status,
                'post_date'    => $p['date'],
                'post_author'  => get_current_user_id(),
            ];

            $new_id = wp_insert_post($new_post);
            
            if (is_wp_error($new_id)) {
                error_log("[Blog Migrator] Failed to create post {$post_id}: " . $new_id->get_error_message());
                $failed_count++;
                continue;
            }

            // Guardar mapeo
            $job->add_imported_post($post_id, $new_id);

            // 🏷️ Categorías (usa Term Mapper del Commit 1)
            if (!empty($p['categories'])) {
                $cat_ids = [];
                foreach ($p['categories'] as $cat_id) {
                    $dest_term_id = Blog_Migrator_Term_Mapper::resolve_term($cat_id, 'category', $domain);
                    if ($dest_term_id) {
                        $cat_ids[] = $dest_term_id;
                    }
                }
                
                if (!empty($cat_ids)) {
                    $result = wp_set_post_terms($new_id, $cat_ids, 'category');
                    if (is_wp_error($result)) {
                        error_log("[Blog Migrator] Failed to set categories for post {$new_id}: " . $result->get_error_message());
                    }
                }
            }

            // 🏷️ Etiquetas (usa Term Mapper del Commit 1)
            if (!empty($p['tags'])) {
                $tag_ids = [];
                foreach ($p['tags'] as $tag_id) {
                    $dest_term_id = Blog_Migrator_Term_Mapper::resolve_term($tag_id, 'post_tag', $domain);
                    if ($dest_term_id) {
                        $tag_ids[] = $dest_term_id;
                    }
                }
                
                if (!empty($tag_ids)) {
                    wp_set_post_terms($new_id, $tag_ids, 'post_tag');
                }
            }

            // 🖼️ Imagen destacada (igual que antes)
            if (!empty($p['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                $image_url = $p['_embedded']['wp:featuredmedia'][0]['source_url'];
                $tmp = download_url($image_url);

                if (!is_wp_error($tmp)) {
                    $file = [
                        'name'     => basename($image_url),
                        'type'     => mime_content_type($tmp),
                        'tmp_name' => $tmp,
                        'error'    => 0,
                        'size'     => filesize($tmp),
                    ];

                    $overrides = ['test_form' => false];
                    $file_info = wp_handle_sideload($file, $overrides);

                    if (!isset($file_info['error'])) {
                        $attachment = [
                            'post_mime_type' => $file_info['type'],
                            'post_title'     => sanitize_file_name($file_info['file']),
                            'post_content'   => '',
                            'post_status'    => 'inherit',
                        ];
                        $attach_id = wp_insert_attachment($attachment, $file_info['file'], $new_id);
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        $attach_data = wp_generate_attachment_metadata($attach_id, $file_info['file']);
                        wp_update_attachment_metadata($attach_id, $attach_data);
                        set_post_thumbnail($new_id, $attach_id);
                    }
                }
            }

            $imported_count++;
        }

        return [
            'imported_count' => $imported_count,
            'failed_count' => $failed_count,
        ];
    }

    /** ------------------------------
     * 8️⃣ BATCHING: Obtener estado del job
     * ------------------------------ */
    public static function get_job_status() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $job = new Blog_Migrator_Job_State();
        $state = $job->get();

        if (!$state) {
            wp_send_json_success(['exists' => false]);
        } else {
            wp_send_json_success(['exists' => true, 'state' => $state]);
        }
    }

    /** ------------------------------
     * 9️⃣ BATCHING: Cancelar/Resetear job
     * ------------------------------ */
    public static function cancel_job() {
        check_ajax_referer('bm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.']);
        }

        $job = new Blog_Migrator_Job_State();
        $job->reset();

        wp_send_json_success(['message' => 'Job cancelado.']);
    }
}
