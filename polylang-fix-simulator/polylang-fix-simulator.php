<?php

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    $hook = add_submenu_page(
        TWD_MENU,
        'Polylang Fixer Manual',
        'Polylang Fixer Manual',
        'manage_options',
        'polylang-fix-manual',
        'pll_fix_manual_page'
    );

    // Carga Select2 desde CDN para los selectores.
    add_action('admin_enqueue_scripts', function ($current_hook) use ($hook) {
        if ($current_hook !== $hook) return;

        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_add_inline_script('select2', "
            jQuery(document).ready(function($){
                $('select.pll-select').select2({
                    width: '100%',
                    placeholder: 'Buscar post...',
                    allowClear: true
                });
            });
        ");
    });
});

function pll_fix_manual_page() {
    if (!function_exists('pll_get_post_translations')) {
        echo '<div class="notice notice-error"><p>❌ Polylang no está activo o no se detecta correctamente.</p></div>';
        return;
    }

    $langs = pll_languages_list();
    $base_lang = $langs[0];
    $results = [];
if (isset($_POST['pll_fix_action'])) {
    check_admin_referer('pll_fix_manual_nonce');
    $results = pll_fix_manual_run(false, $langs); // false = ejecutar directamente
}

    echo '<div class="wrap">';
    echo '<h1>🌍 Polylang Fixer Manual</h1>';
    echo '<p>Selecciona manualmente las traducciones entre idiomas. Puedes simular primero para verificar los enlaces antes de aplicar los cambios reales.</p>';

    echo '<form method="post">';
    wp_nonce_field('pll_fix_manual_nonce');

    echo '<button type="submit" name="pll_fix_action" value="execute" class="button button-primary">⚙️ Guardar asociaciones</button></p>';

    echo '<table class="widefat striped"><thead><tr>';
    foreach ($langs as $lang) echo '<th>' . strtoupper($lang) . '</th>';
    echo '<th>Estado</th></tr></thead><tbody>';

    $posts_base = get_posts([
        'post_type' => 'post',
        'posts_per_page' => -1,
        'lang' => $base_lang,
    ]);

    foreach ($posts_base as $post_base) {
        $translations = pll_get_post_translations($post_base->ID);
        $is_complete = count($translations) === count($langs);
        echo '<tr>';

        foreach ($langs as $lang) {
            $current = $translations[$lang] ?? null;

            // Mostrar la celda del idioma actual
            if ($lang === $base_lang) {
                echo '<td width="30%"><strong>' . esc_html($post_base->post_title) . '</strong><br><small>ID: ' . $post_base->ID . '</small></td>';
                continue;
            }

            // Si ya hay una traducción asociada
            if ($current) {
                $p = get_post($current);
                echo '<td width="30%"><input type="hidden" name="assoc[' . $post_base->ID . '][' . $lang . ']" value="' . esc_attr($p->ID) . '">';
                echo esc_html($p->post_title) . ' (#' . $p->ID . ')';
                echo '</td>';
            } else {
                // Mostrar selector solo si falta traducción
                $posts_lang = get_posts([
                    'post_type' => 'post',
                    'posts_per_page' => -1,
                    'lang' => $lang,
                ]);
                echo '<td width="30%"><select name="assoc[' . $post_base->ID . '][' . $lang . ']" class="pll-select">';
                echo '<option value="">— Buscar post —</option>';
                foreach ($posts_lang as $p) {
                    echo '<option value="' . esc_attr($p->ID) . '">' . esc_html($p->post_title) . ' (#' . $p->ID . ')</option>';
                }
                echo '</select></td>';
            }
        }

        echo '<td>' . ($is_complete ? '✅ Completado' : '🕓 Pendiente') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</form>';

    // Resultados (si se procesaron)
    if (!empty($results)) {
        echo '<h2>Resultados:</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($langs as $lang) echo '<th>' . strtoupper($lang) . '</th>';
        echo '<th>Estado</th></tr></thead><tbody>';
        foreach ($results as $r) {
            echo '<tr>';
            foreach ($langs as $lang) echo '<td width="30%">' . esc_html($r[$lang] ?? '—') . '</td>';
            echo '<td>' . esc_html($r['status']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}

/**
 * Procesa asociaciones nuevas o simuladas
 */
function pll_fix_manual_run($simulate, $langs) {
    $results = [];
    if (empty($_POST['assoc'])) return [];

    foreach ($_POST['assoc'] as $base_id => $assoc_langs) {
        $assoc = [];
        $base_post = get_post($base_id);
        $assoc[pll_get_post_language($base_id)] = $base_id;
        $translations = [pll_get_post_language($base_id) => "{$base_post->post_title} (#{$base_post->ID})"];

        foreach ($assoc_langs as $lang => $target_id) {
            if ($target_id) {
                $p = get_post($target_id);
                $assoc[$lang] = $p->ID;
                $translations[$lang] = "{$p->post_title} (#{$p->ID})";
            } else {
                $translations[$lang] = '—';
            }
        }

        if (count($assoc) > 1) {
            if (!$simulate) {
                pll_save_post_translations($assoc);
                $translations['status'] = '✅ Asociado';
            } else {
                $translations['status'] = '🔍 Simulado';
            }
        } else {
            $translations['status'] = '⚠️ Sin suficientes idiomas';
        }

        $results[] = $translations;
    }

    return $results;
}
