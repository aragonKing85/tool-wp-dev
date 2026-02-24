<?php
if (!defined('ABSPATH')) exit;

function mdr_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . MDR_TABLE;
}

function mdr_now(): string {
    return current_time('mysql', 1); // UTC
}

function mdr_clean_path($path, bool $include_query = false) {
    // Normaliza: sin dominio, mantiene leading slash
    $url = wp_parse_url($path);

    $clean = ($url['path'] ?? '/');

    // Opcional: quitar trailing slash excepto raíz
    if ($clean !== '/' && str_ends_with($clean, '/')) {
        $clean = rtrim($clean, '/');
    }

    // 🧩 NUEVO: incluir query string si se solicita
    if ($include_query && !empty($url['query'])) {
        $clean .= '?' . $url['query'];
    }

    return $clean;
}


function mdr_rules_cache_key(): string {
    return 'mdr_rules_enabled_v1';
}

function mdr_invalidate_rules_cache(): void {
    delete_transient(mdr_rules_cache_key());
}
