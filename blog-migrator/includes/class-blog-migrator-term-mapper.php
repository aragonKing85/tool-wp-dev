<?php
if ( !defined('ABSPATH') ) exit;

/**
 * Clase para mapear y resolver términos (categorías/tags) durante la migración
 * 
 * Funcionalidades:
 * - Matching de términos: primero por slug, luego por nombre
 * - Validación HTTP + logging de errores
 * - Mapeo persistente origen→destino (transient)
 * - Creación de términos si no existen
 */
class Blog_Migrator_Term_Mapper {

    /**
     * Resolver un término del origen y mapearlo al destino
     * 
     * @param int $term_id ID del término en el sitio origen
     * @param string $taxonomy Taxonomía (category, post_tag)
     * @param string $domain Dominio del sitio origen
     * @return int|false term_id del sitio destino o false si falla
     */
    public static function resolve_term($term_id, $taxonomy, $domain) {
        // 1. Verificar si ya existe en el mapeo
        $mapping = self::get_mapping($taxonomy);
        if (isset($mapping[$term_id])) {
            return $mapping[$term_id];
        }

        // 2. Obtener datos del término desde la API origen
        $term_data = self::fetch_term_from_api($term_id, $taxonomy, $domain);
        if (!$term_data) {
            return false; // El error ya fue loggeado en fetch_term_from_api
        }

        // 3. Buscar o crear el término en el destino
        $dest_term_id = self::get_or_create_term($term_data['name'], $term_data['slug'], $taxonomy);

        // 4. Guardar en el mapeo
        if ($dest_term_id) {
            self::save_mapping($taxonomy, $term_id, $dest_term_id);
        }

        return $dest_term_id;
    }

    /**
     * Fetch término desde la API REST del origen con validación HTTP
     * 
     * @param int $term_id ID del término en el origen
     * @param string $taxonomy category o post_tag
     * @param string $domain Dominio del sitio origen
     * @return array|false Array con 'name' y 'slug', o false si falla
     */
    private static function fetch_term_from_api($term_id, $taxonomy, $domain) {
        $endpoint_path = $taxonomy === 'category' ? 'categories' : 'tags';
        $endpoint = rtrim($domain, '/') . "/wp-json/wp/v2/{$endpoint_path}/{$term_id}";

        $response = wp_remote_get($endpoint, ['timeout' => 15]);

        // Validar errores de conexión
        if (is_wp_error($response)) {
            self::log_error("Error fetching {$taxonomy} {$term_id}: " . $response->get_error_message());
            return false;
        }

        // Validar código HTTP
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            self::log_error("{$taxonomy} {$term_id} returned HTTP {$http_code}");
            return false;
        }

        $term = json_decode(wp_remote_retrieve_body($response), true);

        // Validar que tenga nombre
        if (empty($term['name'])) {
            self::log_error("{$taxonomy} {$term_id} has no name");
            return false;
        }

        self::log_info("Fetched {$taxonomy} {$term_id}: {$term['name']} (slug: {$term['slug']})");

        return [
            'name' => $term['name'],
            'slug' => $term['slug'],
        ];
    }

    /**
     * Buscar término existente o crearlo (matching: slug primero, nombre después)
     * 
     * @param string $name Nombre del término
     * @param string $slug Slug del término
     * @param string $taxonomy Taxonomía
     * @return int|false term_id del término en destino
     */
    public static function get_or_create_term($name, $slug, $taxonomy) {
        // 1. Intentar match por SLUG primero
        $existing = get_term_by('slug', $slug, $taxonomy);

        if ($existing) {
            self::log_info("Term found by slug '{$slug}': {$existing->name} (ID: {$existing->term_id})");
            return $existing->term_id;
        }

        // 2. Si no existe por slug, intentar por NOMBRE
        $existing = get_term_by('name', $name, $taxonomy);

        if ($existing) {
            self::log_info("Term found by name '{$name}': {$existing->name} (slug: {$existing->slug}, ID: {$existing->term_id})");
            return $existing->term_id;
        }

        // 3. No existe, crear nuevo término
        // Verificar que el slug esté libre (por si acaso)
        $slug_check = get_term_by('slug', $slug, $taxonomy);
        $final_slug = $slug_check ? sanitize_title($slug) . '-imported' : sanitize_title($slug);

        $new_term = wp_insert_term($name, $taxonomy, [
            'slug' => $final_slug,
        ]);

        if (is_wp_error($new_term)) {
            self::log_error("Failed to create {$taxonomy} '{$name}': " . $new_term->get_error_message());
            return false;
        }

        self::log_info("Created new {$taxonomy}: {$name} (slug: {$final_slug}, ID: {$new_term['term_id']})");

        return $new_term['term_id'];
    }

    /**
     * Obtener mapeo persistente de términos (origen → destino)
     * 
     * @param string $taxonomy category o post_tag
     * @return array Mapeo [origin_id => dest_id]
     */
    public static function get_mapping($taxonomy) {
        $key = "bm_{$taxonomy}_mapping";
        return get_transient($key) ?: [];
    }

    /**
     * Guardar mapeo de término (origen → destino)
     * 
     * @param string $taxonomy category o post_tag
     * @param int $origin_id ID en el sitio origen
     * @param int $dest_id ID en el sitio destino
     */
    public static function save_mapping($taxonomy, $origin_id, $dest_id) {
        $key = "bm_{$taxonomy}_mapping";
        $mapping = self::get_mapping($taxonomy);
        $mapping[$origin_id] = $dest_id;
        set_transient($key, $mapping, WEEK_IN_SECONDS); // 7 días
    }

    /**
     * Limpiar todos los mapeos
     */
    public static function clear_all_mappings() {
        delete_transient('bm_category_mapping');
        delete_transient('bm_post_tag_mapping');
    }

    /**
     * Logging de errores (siempre + debug.log si WP_DEBUG)
     */
    private static function log_error($message) {
        $log = "[Blog Migrator - Term Mapper ERROR] " . $message;
        error_log($log);
    }

    /**
     * Logging de info (solo si WP_DEBUG)
     */
    private static function log_info($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log = "[Blog Migrator - Term Mapper INFO] " . $message;
            error_log($log);
        }
    }
}
