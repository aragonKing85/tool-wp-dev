<?php
if (!defined('ABSPATH')) exit;

class MDR_Checker {
    public function __construct() {
        add_action('wp_ajax_mdr_check',           [$this, 'handle']);
        add_action('wp_ajax_mdr_check_all',       [$this, 'handle_check_all']);
        add_action('wp_ajax_mdr_detect_loops',    [$this, 'handle_detect_loops']);
        add_action('wp_ajax_mdr_delete_by_source',[$this, 'handle_delete_by_source']);
    }

    /** Comprobación individual */
    public function handle() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('mdr-check', 'nonce');

        $source = trim((string)($_POST['source'] ?? ''));
        $target = trim((string)($_POST['target'] ?? ''));
        $code   = (int)($_POST['code'] ?? 301);
        $id     = (int)($_POST['id'] ?? 0);
        if ($source === '') wp_send_json_error(['message' => 'Falta source_path']);

        // URLs absolutas (respetando query vars si vienen en $source/$target)
        $source_url = preg_match('#^https?://#i', $source) ? $source : home_url(ltrim($source, '/'));
        $target_url = $target ? (preg_match('#^https?://#i', $target) ? $target : home_url(ltrim($target, '/'))) : '';

        // 1) SOURCE: no seguir redirecciones, para ver Location y primer código
        $src_resp  = wp_remote_get($source_url, ['timeout' => 10, 'redirection' => 0, 'sslverify' => false]);
        $source_err  = is_wp_error($src_resp) ? $src_resp->get_error_message() : null;
        $source_http = $source_err ? null : (int) wp_remote_retrieve_response_code($src_resp);
        $location    = $source_err ? null : wp_remote_retrieve_header($src_resp, 'location');

        // 2) TARGET: seguir redirecciones hasta el final (código real del destino)
        $target_http = null;
        $target_err  = null;
        if ($code !== 410 && $target_url) {
            $tgt_resp   = wp_remote_get($target_url, ['timeout' => 10, 'redirection' => 5, 'sslverify' => false]);
            if (is_wp_error($tgt_resp)) {
                $target_err  = $tgt_resp->get_error_message();
            } else {
                $target_http = (int) wp_remote_retrieve_response_code($tgt_resp);
            }
        }

        // Guardar en transient SOLO números (para que la tabla no se rompa)
        if ($id) {
            $to_store = is_int($target_http) ? $target_http : (is_int($source_http) ? $source_http : null);
            set_transient('mdr_check_' . $id, $to_store, HOUR_IN_SECONDS);
        }

        $ok = (is_int($target_http) && $target_http >= 200 && $target_http < 400);

        wp_send_json_success([
            'source_url'   => $source_url,
            'source_http'  => $source_http,          // int|null
            'location'     => $location ?: null,
            'target_http'  => $target_http,          // int|null
            'target_error' => $target_err,           // string|null
            'ok'           => $ok
        ]);
    }

    /** Comprobación masiva */
    public function handle_check_all() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('mdr-check', 'nonce');

        mdr_invalidate_rules_cache();
        $rules = MDR_DB::all();
        if (empty($rules)) wp_send_json_success(['count' => 0, 'results' => []]);

        $results = [];
        foreach ($rules as $rule) {
            $target_url = $rule->target_url
                ? (preg_match('#^https?://#i', $rule->target_url) ? $rule->target_url : home_url(ltrim($rule->target_url, '/')))
                : '';

            $final_code = null;
            $error_txt  = null;

            if ((int)$rule->status_code !== 410 && $target_url) {
                $resp = wp_remote_get($target_url, ['timeout' => 10, 'redirection' => 5, 'sslverify' => false]);
                if (is_wp_error($resp)) {
                    $error_txt  = $resp->get_error_message();
                } else {
                    $final_code = (int) wp_remote_retrieve_response_code($resp);
                }
            }

            // Transient: solo número o null
            set_transient('mdr_check_' . (int)$rule->id, is_int($final_code) ? $final_code : null, HOUR_IN_SECONDS);

            $results[] = [
                'id'         => (int)$rule->id,
                'final_code' => $final_code,                 // int|null
                'error'      => $error_txt,                  // string|null
                'ok'         => (is_int($final_code) && $final_code >= 200 && $final_code < 400),
            ];
        }

        wp_send_json_success([
            'count'   => count($results),
            'results' => $results,
        ]);
    }

    /** Detección de bucles (pares únicos) */
    public function handle_detect_loops() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('mdr-check', 'nonce');

        global $wpdb;
        $table = mdr_table_name();
        $rows = $wpdb->get_results("SELECT id, source_path, target_url, status_code FROM {$table}");
        if (!$rows) wp_send_json_success(['count' => 0, 'loops' => []]);

        $home = untrailingslashit(home_url());
        $redirects = [];
        foreach ($rows as $r) {
            if ((int)$r->status_code === 410 || empty($r->target_url)) continue;
            $source = untrailingslashit(str_replace($home, '', $r->source_path));
            $target = untrailingslashit(str_replace($home, '', $r->target_url));
            if ($source === $target) continue;
            $redirects[$source] = $target;
        }

        $loops = [];
        $visited_global = [];
        foreach ($redirects as $src => $tgt) {
            if (isset($visited_global[$src])) continue;

            $visited = [$src];
            $current = $tgt;
            while (isset($redirects[$current])) {
                if (in_array($current, $visited, true)) {
                    $start = array_search($current, $visited, true);
                    $cycle = array_slice($visited, $start);
                    $cycle[] = $current;
                    if (count($cycle) > 2) {
                        $loops[] = $cycle;
                        foreach ($cycle as $c) $visited_global[$c] = true;
                    }
                    break;
                }
                $visited[] = $current;
                $current = $redirects[$current];
            }
        }

        $pairs = [];
        foreach ($loops as $loop) {
            for ($i = 0; $i < count($loop) - 1; $i++) {
                $pairs[] = ['source' => $loop[$i], 'target' => $loop[$i + 1]];
            }
        }
        $pairs = array_unique(array_map('serialize', $pairs));
        $pairs = array_map('unserialize', $pairs);

        wp_send_json_success(['count' => count($pairs), 'loops' => $pairs]);
    }

    /** Eliminar por source_path (para tabla de bucles) */
    public function handle_delete_by_source() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('mdr-check', 'nonce');

        $source = sanitize_text_field($_POST['source'] ?? '');
        if ($source === '') wp_send_json_error(['message' => 'Source vacío']);

        global $wpdb;
        $table = mdr_table_name();
        $deleted = $wpdb->delete($table, ['source_path' => $source]);

        if ($deleted === false) wp_send_json_error(['message' => 'Error al eliminar']);

        delete_transient(mdr_rules_cache_key());
        wp_send_json_success(['message' => 'Redirección eliminada']);
    }
}
