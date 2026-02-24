<?php
if (!defined('ABSPATH')) exit;

class MDR_Matcher {
    public static function maybe_redirect() {
        if (is_admin() || wp_doing_ajax() || is_feed()) return;
if (!empty($_GET)) return;
   // 🔹 Mantener parámetros para URLs con query vars
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';

// Guardamos la URI tal cual llega, sin perder el query string
$path = ltrim($request_uri, '/');

// También normalizamos versiones sin dominio para comparar con redirecciones guardadas
$host = home_url();
$full_url = untrailingslashit($host) . '/' . ltrim($path, '/');

        // Cache de reglas habilitadas (60s; ajusta a tus necesidades)
        $rules = get_transient(mdr_rules_cache_key());
        if ($rules === false) {
            $rules = MDR_DB::all(['enabled' => 1]); // ya ordena por priority
            set_transient(mdr_rules_cache_key(), $rules, 60);
        }

        foreach ($rules as $rule) {
            $matched = false;
            $target  = $rule->target_url;

        if ($rule->is_regex) {
    $pattern = '#' . $rule->source_path . '#i';
    $matched = preg_match($pattern, $path, $m) === 1;

    if ($matched && strpos($target, '$') !== false && !empty($m)) {
        for ($i = 1; $i < count($m); $i++) {
            $target = str_replace('$' . $i, $m[$i], $target);
        }
    }
} else {
    // 🧩 Comparación literal, incluyendo parámetros
     $rule_path = mdr_clean_path($rule->source_path, true);
    $path_variants = [
        $rule_path,
        ltrim($rule_path, '/'),  // por si se guardó sin "/"
        '/' . ltrim($rule_path, '/') // por si se guardó con "/"
    ];
    $matched = in_array($path, $path_variants, true);
}


            if ($matched) {
                $code = (int)$rule->status_code;
                if ($code === 410) {
                    MDR_DB::register_hit($rule->id);
                    status_header(410);
                    nocache_headers();
                    exit;
                }

                if ($target && !preg_match('#^https?://#i', $target)) {
                    $target = home_url($target);
                }

                if ($target) {
                    MDR_DB::register_hit($rule->id);
                    wp_redirect($target, in_array($code, [301,302,307,308], true) ? $code : 301);
                    exit;
                }
            }
        }
    }
}
