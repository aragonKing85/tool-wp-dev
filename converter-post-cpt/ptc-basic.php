<?php


if (!defined('ABSPATH')) exit;

class PTC_Basic_Converter
{
    const CAPABILITY = 'manage_options';
    const NONCE_ACTION = 'ptc_basic_nonce_action';
    const NONCE_NAME = 'ptc_basic_nonce';
    const OPT_JOBS = 'ptc_basic_jobs';
    const META_LAST = '_ptc_basic_last';
    const DEFAULT_BATCH = 15;

    private $page_hook = '';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_ptc_basic_get_dest_tax', [$this, 'ajax_get_dest_tax']);
        add_action('wp_ajax_ptc_basic_start_job', [$this, 'ajax_start_job']);
        add_action('wp_ajax_ptc_basic_process_job', [$this, 'ajax_process_job']);
        add_action('wp_ajax_ptc_basic_revert_job', [$this, 'ajax_revert_job']);


        new PTC_Basic_Date_CSV($this);
    }

    public function admin_menu()
    {
        $this->page_hook = add_submenu_page(
            TWD_MENU,
            'Convertidor Post → CPT',
            'Convertidor Post → CPT',
            self::CAPABILITY,
            'ptc-basic',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets($hook)
    {
        if (!$this->page_hook || $hook !== $this->page_hook) return;

        wp_enqueue_style('ptc-basic-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '1.0.0');
        wp_enqueue_script('ptc-basic-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '1.0.0', true);

        wp_localize_script('ptc-basic-admin', 'PTCBasic', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'batchSize' => self::DEFAULT_BATCH,
        ]);

        wp_enqueue_script('ptc-basic-dates', plugin_dir_url(__FILE__) . 'assets/dates.js', ['jquery'], '1.0.0', true);
        wp_localize_script('ptc-basic-dates', 'PTCBasicDates', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'batchSize' => self::DEFAULT_BATCH,
        ]);
    }

    private function ensure_capability()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error([
                'code' => 'forbidden',
                'message' => 'No tienes permisos para ejecutar esta acción (se requiere manage_options).',
            ], 403);
        }
    }

    private function get_jobs()
    {
        $jobs = get_option(self::OPT_JOBS, []);
        return is_array($jobs) ? $jobs : [];
    }

    private function save_jobs($jobs)
    {
        update_option(self::OPT_JOBS, $jobs, false);
    }

    private function get_post_types_for_select()
    {
        $types = get_post_types(['show_ui' => true], 'objects');
        $out = [];
        foreach ($types as $k => $obj) {
            if ($k === 'attachment') continue;
            // Permitimos seleccionar page/cpt también; el usuario dijo "post a otro CPT", pero a veces hay CPT con UI rara.
            $out[$k] = $obj->labels->singular_name . " ({$k})";
        }
        return $out;
    }

    private function get_dest_taxonomies($dest_post_type)
    {
        $tax_objects = get_object_taxonomies($dest_post_type, 'objects');
        $tax = [];
        foreach ($tax_objects as $t) {
            if (empty($t->name)) continue;
            $tax[$t->name] = [
                'name' => $t->name,
                'label' => $t->labels->singular_name ?? $t->name,
                'hierarchical' => (bool) $t->hierarchical,
            ];
        }
        return $tax;
    }

    private function get_terms_for_tax($tax_name)
    {
        $terms = get_terms([
            'taxonomy' => $tax_name,
            'hide_empty' => false,
            'number' => 0,
        ]);
        if (is_wp_error($terms)) return $terms;

        $out = [];
        foreach ($terms as $term) {
            $out[] = [
                'id' => (int)$term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => (int)$term->parent,
            ];
        }
        return $out;
    }

    public function render_admin_page()
    {

        if (!current_user_can(self::CAPABILITY)) {
            echo '<div class="notice notice-error"><p>No tienes permisos suficientes.</p></div>';
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'convert';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a class="nav-tab ' . ($tab === 'convert' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('tab', 'convert')) . '">Conversión Post → CPT</a>';
        echo '<a class="nav-tab ' . ($tab === 'dates' ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('tab', 'dates')) . '">Fechas por CSV</a>';
        echo '</h2>';

      if($tab === 'convert') {
          $post_types = $this->get_post_types_for_select();

        // Filtros (sobre posts)
        $paged = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $cat = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
        $tag = isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '';
        $author = isset($_GET['author']) ? (int)$_GET['author'] : 0;
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 100;
        $allowed_per_page = [20, 50, 100, 200];
        if (!in_array($per_page, $allowed_per_page, true)) {
            $per_page = 100;
        }

        $args = [
            'post_type' => 'post',
            'post_status' => $status ? $status : ['publish', 'draft', 'pending', 'private', 'future'],
            's' => $s ?: '',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $tax_query = [];
        if ($cat > 0) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => [$cat],
            ];
        }
        if ($tag) {
            $tax_query[] = [
                'taxonomy' => 'post_tag',
                'field' => 'slug',
                'terms' => [$tag],
            ];
        }
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }
        if ($author > 0) {
            $args['author'] = $author;
        }

        $q = new WP_Query($args);
        $ids = $q->posts;
        $total_found = (int)$q->found_posts;
        $first_item = $total_found ? (($paged - 1) * $per_page + 1) : 0;
        $last_item  = min($total_found, $paged * $per_page);
        $cats = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'number' => 0]);
        $tags = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => false, 'number' => 0]);
        $authors = get_users(['who' => 'authors']);

        $jobs = $this->get_jobs();
        // Orden: más reciente primero
        uasort($jobs, function ($a, $b) {
            return ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0);
        });

?>
        <div class="wrap ptc-basic">
            <h1>Convertidor Post → CPT</h1>

            <div class="ptc-grid">
                <div class="ptc-card">
                    <h2>1) Filtra y selecciona posts</h2>

                    <form method="get" class="ptc-filters">
                        <input type="hidden" name="page" value="ptc-basic" />
                        <div class="ptc-filters-row">
                            <label>
                                Buscar
                                <input type="text" name="s" value="<?php echo esc_attr($s); ?>" />
                            </label>

                            <label>
                                Categoría
                                <select name="cat">
                                    <option value="0">— Todas —</option>
                                    <?php foreach ($cats as $c): ?>
                                        <option value="<?php echo (int)$c->term_id; ?>" <?php selected($cat, (int)$c->term_id); ?>>
                                            <?php echo esc_html($c->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Tag
                                <select name="tag">
                                    <option value="">— Todos —</option>
                                    <?php foreach ($tags as $t): ?>
                                        <option value="<?php echo esc_attr($t->slug); ?>" <?php selected($tag, $t->slug); ?>>
                                            <?php echo esc_html($t->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Autor
                                <select name="author">
                                    <option value="0">— Todos —</option>
                                    <?php foreach ($authors as $u): ?>
                                        <option value="<?php echo (int)$u->ID; ?>" <?php selected($author, (int)$u->ID); ?>>
                                            <?php echo esc_html($u->display_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Estado
                                <select name="status">
                                    <option value="">— Todos —</option>
                                    <?php
                                    $st = ['publish' => 'publish', 'draft' => 'draft', 'pending' => 'pending', 'private' => 'private', 'future' => 'future'];
                                    foreach ($st as $k => $lbl):
                                    ?>
                                        <option value="<?php echo esc_attr($k); ?>" <?php selected($status, $k); ?>>
                                            <?php echo esc_html($lbl); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Posts por página
                                <select name="per_page">
                                    <?php foreach ([20, 50, 100, 200] as $n): ?>
                                        <option value="<?php echo (int)$n; ?>" <?php selected($per_page, (int)$n); ?>>
                                            <?php echo (int)$n; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="button">Aplicar filtros</button>
                        </div>
                    </form>
                    <div class="ptc-results-meta">
                        <strong>
                            <?php echo esc_html("Resultados: {$total_found}"); ?>
                        </strong>
                        <span class="ptc-muted">
                            <?php echo esc_html(" · Mostrando {$first_item}–{$last_item}"); ?>
                        </span>
                    </div>

                    <div class="ptc-table-wrap">
                        <table class="widefat striped ptc-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;"><input type="checkbox" id="ptc-select-all" /></th>
                                    <th>Título</th>
                                    <th>Fecha</th>
                                    <th>Autor</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ids)): ?>
                                    <tr>
                                        <td colspan="5">No hay posts con estos filtros.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ids as $id):
                                        $p = get_post($id);
                                        $author_name = $p ? get_the_author_meta('display_name', $p->post_author) : '';
                                    ?>
                                        <tr>
                                            <td><input type="checkbox" class="ptc-post" value="<?php echo (int)$id; ?>" /></td>
                                            <td>
                                                <strong><?php echo esc_html(get_the_title($id)); ?></strong>
                                                <div class="ptc-muted">ID: <?php echo (int)$id; ?> · <?php echo esc_html(get_permalink($id)); ?></div>
                                            </td>
                                            <td><?php echo esc_html(get_the_date('Y-m-d H:i', $id)); ?></td>
                                            <td><?php echo esc_html($author_name); ?></td>
                                            <td><?php echo esc_html($p ? $p->post_status : ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $total_pages = (int)$q->max_num_pages;
                    $total_pages = (int) $q->max_num_pages;

                    if ($total_pages > 1) {
                        // Construimos una URL "limpia" preservando filtros pero sin paged, y luego añadimos paged como placeholder.
                        $base_url = remove_query_arg('paged');
                        $base_url = add_query_arg([], $base_url);

                        echo '<div class="ptc-pagination">';
                        echo paginate_links([
                            'base'      => esc_url_raw(add_query_arg('paged', '%#%', $base_url)),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => $total_pages,
                            'prev_text' => '«',
                            'next_text' => '»',
                            'type'      => 'plain',
                        ]);
                        echo '</div>';
                    }

                    ?>
                </div>

                <div class="ptc-card">
                    <h2>2) Configura destino y términos</h2>

                    <div class="ptc-form">
                        <label>
                            CPT destino
                            <select id="ptc-dest-post-type">
                                <option value="">— Selecciona —</option>
                                <?php foreach ($post_types as $k => $label): ?>
                                    <?php if ($k === 'post') continue; ?>
                                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div id="ptc-taxonomies" class="ptc-taxonomies">
                            <div class="ptc-muted">Selecciona un CPT destino para cargar sus taxonomías.</div>
                        </div>

                        <div class="ptc-actions">
                            <button class="button button-primary" id="ptc-start">Convertir seleccionados</button>
                        </div>
                    </div>

                    <hr />

                    <h2>3) Progreso y logs</h2>

                    <div class="ptc-progress">
                        <div class="ptc-progress-bar">
                            <div class="ptc-progress-bar-inner" id="ptc-progress-inner" style="width:0%"></div>
                        </div>
                        <div class="ptc-progress-meta">
                            <span id="ptc-progress-text">0 / 0</span>
                            <span id="ptc-progress-percent">0%</span>
                        </div>
                    </div>

                    <div class="ptc-messages" id="ptc-messages"></div>

                    <hr />

                    <h2>Jobs (para revertir)</h2>
                    <p class="ptc-muted">Cada conversión genera un “job”. Puedes revertir un job completo (batch) restaurando post_type y términos previos de las taxonomías del CPT destino.</p>

                    <?php if (empty($jobs)): ?>
                        <div class="ptc-muted">Aún no hay jobs.</div>
                    <?php else: ?>
                        <table class="widefat striped ptc-jobs">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Fecha</th>
                                    <th>Destino</th>
                                    <th>Total</th>
                                    <th>OK</th>
                                    <th>ERROR</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job_id => $job): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($job_id); ?></code></td>
                                        <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)($job['created_at'] ?? 0))); ?></td>
                                        <td><?php echo esc_html($job['dest_post_type'] ?? ''); ?></td>
                                        <td><?php echo (int)($job['total'] ?? 0); ?></td>
                                        <td><?php echo (int)($job['ok'] ?? 0); ?></td>
                                        <td><?php echo (int)($job['error'] ?? 0); ?></td>
                                        <td>
                                            <button class="button ptc-revert" data-job="<?php echo esc_attr($job_id); ?>">Revertir job</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                </div>
            </div>
        </div>
<?php
      }

      if ($tab === 'dates') {
  // Instancia temporal para render (o mejor, guarda la instancia en el core)
  $dates = new PTC_Basic_Date_CSV($this);
  $dates->render_tab();
  return;
}

    }

    public function ajax_get_dest_tax()
    {
        $this->ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $dest = isset($_POST['dest']) ? sanitize_key(wp_unslash($_POST['dest'])) : '';
        if (!$dest) {
            wp_send_json_error(['code' => 'missing_dest', 'message' => 'Falta el CPT destino.'], 400);
        }

        if (!post_type_exists($dest)) {
            wp_send_json_error(['code' => 'invalid_dest', 'message' => "El CPT destino '{$dest}' no existe en este WordPress."], 400);
        }

        $tax = $this->get_dest_taxonomies($dest);
        $payload = [];

        foreach ($tax as $tax_name => $info) {
            $terms = $this->get_terms_for_tax($tax_name);
            if (is_wp_error($terms)) {
                $payload[] = [
                    'taxonomy' => $tax_name,
                    'label' => $info['label'],
                    'hierarchical' => $info['hierarchical'],
                    'error' => $terms->get_error_message(),
                    'terms' => [],
                ];
                continue;
            }

            $payload[] = [
                'taxonomy' => $tax_name,
                'label' => $info['label'],
                'hierarchical' => $info['hierarchical'],
                'terms' => $terms,
            ];
        }

        wp_send_json_success(['taxonomies' => $payload]);
    }

    public function ajax_start_job()
    {
        $this->ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $dest = isset($_POST['dest']) ? sanitize_key(wp_unslash($_POST['dest'])) : '';
        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        $terms_by_tax = isset($_POST['terms_by_tax']) && is_array($_POST['terms_by_tax']) ? $_POST['terms_by_tax'] : [];

        if (!$dest) {
            wp_send_json_error(['code' => 'missing_dest', 'message' => 'Falta el CPT destino.'], 400);
        }
        if (!post_type_exists($dest)) {
            wp_send_json_error(['code' => 'invalid_dest', 'message' => "El CPT destino '{$dest}' no existe."], 400);
        }
        if (empty($post_ids)) {
            wp_send_json_error(['code' => 'empty_selection', 'message' => 'No has seleccionado ningún post.'], 400);
        }

        // Asegurar que términos sean arrays de ints
        $clean_terms = [];
        foreach ($terms_by_tax as $tax => $ids) {
            $tax = sanitize_key($tax);
            if (!taxonomy_exists($tax)) continue;
            $clean_terms[$tax] = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        }

        $dest_tax = $this->get_dest_taxonomies($dest);
        // Filtrar a taxonomías del destino
        $clean_terms = array_intersect_key($clean_terms, $dest_tax);

        $job_id = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
        $jobs = $this->get_jobs();

        $jobs[$job_id] = [
            'created_at' => time(),
            'created_by' => get_current_user_id(),
            'dest_post_type' => $dest,
            'terms_by_tax' => $clean_terms,
            'post_ids' => array_values($post_ids),
            'total' => count($post_ids),
            'processed' => 0,
            'ok' => 0,
            'error' => 0,
            'results' => [], // per post: status, message, old_url, new_url
        ];

        $this->save_jobs($jobs);

        wp_send_json_success([
            'job_id' => $job_id,
            'total' => count($post_ids),
            'batch' => self::DEFAULT_BATCH,
        ]);
    }

    public function ajax_process_job()
    {
        $this->ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;

        if (!$job_id) {
            wp_send_json_error(['code' => 'missing_job', 'message' => 'Falta job_id.'], 400);
        }

        $jobs = $this->get_jobs();
        if (empty($jobs[$job_id])) {
            wp_send_json_error(['code' => 'job_not_found', 'message' => 'El job no existe o fue eliminado.'], 404);
        }

        $job = $jobs[$job_id];
        $dest = $job['dest_post_type'];
        $terms_by_tax = $job['terms_by_tax'];
        $ids = $job['post_ids'];
        $total = (int)$job['total'];

        $batch = self::DEFAULT_BATCH;
        $slice = array_slice($ids, $offset, $batch);

        $messages = [];
        $ok = 0;
        $err = 0;

        foreach ($slice as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                $err++;
                $messages[] = $this->log_result($jobs, $job_id, $post_id, 'ERROR', "Post no encontrado (ID {$post_id}).", '', '');
                continue;
            }
            if ($post->post_type !== 'post') {
                // Evitamos convertir cosas que ya no son post para reducir “accidentes”
                $err++;
                $messages[] = $this->log_result($jobs, $job_id, $post_id, 'ERROR', "El post ID {$post_id} ya no es 'post' (actual: {$post->post_type}).", get_permalink($post_id), get_permalink($post_id));
                continue;
            }

            $old_url = get_permalink($post_id);

            // Guardar estado mínimo reversible: old post_type + old terms de taxonomías destino
            $old_terms_state = [];
            foreach ($terms_by_tax as $tax => $_terms) {
                $current = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
                if (is_wp_error($current)) {
                    // Si falla, guardamos error pero seguimos (no bloqueamos toda la conversión)
                    $old_terms_state[$tax] = ['_error' => $current->get_error_message(), 'ids' => []];
                } else {
                    $old_terms_state[$tax] = ['ids' => array_map('intval', $current)];
                }
            }

            $meta_payload = [
                'job_id' => $job_id,
                'at' => time(),
                'user_id' => get_current_user_id(),
                'old_post_type' => $post->post_type,
                'dest_post_type' => $dest,
                'old_terms_state' => $old_terms_state,
                'old_url' => $old_url,
            ];
            update_post_meta($post_id, self::META_LAST, wp_json_encode($meta_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // 1) Cambiar post_type (no toca fechas)
            $r = set_post_type($post_id, $dest);
            if (!$r) {
                $err++;
                $messages[] = $this->log_result($jobs, $job_id, $post_id, 'ERROR', "Falló set_post_type() para ID {$post_id}.", $old_url, $old_url);
                continue;
            }

            // 2) Limpiar términos en taxonomías destino y asignar los nuevos (reemplazar)
            $tax_errors = [];
            foreach ($terms_by_tax as $tax => $term_ids) {
                // limpiar
                $clr = wp_set_object_terms($post_id, [], $tax, false);
                if (is_wp_error($clr)) {
                    $tax_errors[] = "No se pudo limpiar tax '{$tax}': " . $clr->get_error_message();
                    continue;
                }

                if (!empty($term_ids)) {
                    $set = wp_set_object_terms($post_id, $term_ids, $tax, false);
                    if (is_wp_error($set)) {
                        $tax_errors[] = "No se pudo asignar términos en '{$tax}': " . $set->get_error_message();
                    }
                }
            }

            // 3) Recalcular URL nueva
            $new_url = get_permalink($post_id);

            // 4) Actualizar meta con new_url
            $meta_payload['new_url'] = $new_url;
            update_post_meta($post_id, self::META_LAST, wp_json_encode($meta_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if (!empty($tax_errors)) {
                $err++;
                $messages[] = $this->log_result(
                    $jobs,
                    $job_id,
                    $post_id,
                    'ERROR',
                    "Convertido a '{$dest}' pero con errores en taxonomías: " . implode(' | ', $tax_errors),
                    $old_url,
                    $new_url
                );
                continue;
            }

            $ok++;
            $messages[] = $this->log_result(
                $jobs,
                $job_id,
                $post_id,
                'OK',
                "Convertido correctamente a '{$dest}'.",
                $old_url,
                $new_url
            );
        }

        // actualizar contadores
        $jobs[$job_id]['processed'] = min($total, $offset + count($slice));
        $jobs[$job_id]['ok'] = (int)$jobs[$job_id]['ok'] + $ok;
        $jobs[$job_id]['error'] = (int)$jobs[$job_id]['error'] + $err;

        $this->save_jobs($jobs);

        $processed = (int)$jobs[$job_id]['processed'];
        $done = ($processed >= $total);

        wp_send_json_success([
            'job_id' => $job_id,
            'total' => $total,
            'processed' => $processed,
            'done' => $done,
            'messages' => $messages,
        ]);
    }

    public function ajax_revert_job()
    {
        $this->ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;

        if (!$job_id) {
            wp_send_json_error(['code' => 'missing_job', 'message' => 'Falta job_id.'], 400);
        }

        $jobs = $this->get_jobs();
        if (empty($jobs[$job_id])) {
            wp_send_json_error(['code' => 'job_not_found', 'message' => 'El job no existe o fue eliminado.'], 404);
        }

        $job = $jobs[$job_id];
        $ids = $job['post_ids'];
        $total = (int)$job['total'];
        $batch = self::DEFAULT_BATCH;
        $slice = array_slice($ids, $offset, $batch);

        $messages = [];
        $ok = 0;
        $err = 0;

        foreach ($slice as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                $err++;
                $messages[] = ['status' => 'ERROR', 'post_id' => $post_id, 'message' => "Post no encontrado (ID {$post_id})."];
                continue;
            }

            $raw = get_post_meta($post_id, self::META_LAST, true);
            if (!$raw) {
                $err++;
                $messages[] = ['status' => 'ERROR', 'post_id' => $post_id, 'message' => "No hay datos reversibles en meta para ID {$post_id} (no fue convertido por este plugin o se borró el meta)."];
                continue;
            }

            $state = json_decode($raw, true);
            if (empty($state['job_id']) || $state['job_id'] !== $job_id) {
                $err++;
                $messages[] = ['status' => 'ERROR', 'post_id' => $post_id, 'message' => "El meta reversible de ID {$post_id} no corresponde a este job (job esperado: {$job_id})."];
                continue;
            }

            $old_type = sanitize_key($state['old_post_type'] ?? '');
            if (!$old_type || !post_type_exists($old_type)) {
                $err++;
                $messages[] = ['status' => 'ERROR', 'post_id' => $post_id, 'message' => "old_post_type inválido o inexistente en ID {$post_id}."];
                continue;
            }

            $before_url = get_permalink($post_id);

            $r = set_post_type($post_id, $old_type);
            if (!$r) {
                $err++;
                $messages[] = ['status' => 'ERROR', 'post_id' => $post_id, 'message' => "Falló set_post_type() al revertir ID {$post_id}."];
                continue;
            }

            // Restaurar términos previos SOLO de taxonomías destino del job
            $old_terms_state = $state['old_terms_state'] ?? [];
            $tax_errors = [];
            if (is_array($old_terms_state)) {
                foreach ($old_terms_state as $tax => $info) {
                    $tax = sanitize_key($tax);
                    if (!taxonomy_exists($tax)) continue;

                    // limpiar
                    $clr = wp_set_object_terms($post_id, [], $tax, false);
                    if (is_wp_error($clr)) {
                        $tax_errors[] = "No se pudo limpiar '{$tax}' al revertir: " . $clr->get_error_message();
                        continue;
                    }

                    $ids_restore = [];
                    if (is_array($info) && isset($info['ids']) && is_array($info['ids'])) {
                        $ids_restore = array_values(array_filter(array_map('intval', $info['ids'])));
                    }

                    if (!empty($ids_restore)) {
                        $set = wp_set_object_terms($post_id, $ids_restore, $tax, false);
                        if (is_wp_error($set)) {
                            $tax_errors[] = "No se pudo restaurar '{$tax}': " . $set->get_error_message();
                        }
                    }
                }
            }

            $after_url = get_permalink($post_id);

            if (!empty($tax_errors)) {
                $err++;
                $messages[] = [
                    'status' => 'ERROR',
                    'post_id' => $post_id,
                    'message' => "Revertido a '{$old_type}' pero con errores en taxonomías: " . implode(' | ', $tax_errors),
                    'old_url' => $before_url,
                    'new_url' => $after_url,
                ];
                continue;
            }

            $ok++;
            $messages[] = [
                'status' => 'OK',
                'post_id' => $post_id,
                'message' => "Revertido correctamente a '{$old_type}'.",
                'old_url' => $before_url,
                'new_url' => $after_url,
            ];
        }

        // No reescribimos el job histórico; pero sí podemos añadir contadores de reversión si quieres.
        $processed = min($total, $offset + count($slice));
        $done = ($processed >= $total);

        wp_send_json_success([
            'job_id' => $job_id,
            'total' => $total,
            'processed' => $processed,
            'done' => $done,
            'messages' => $messages,
            'ok' => $ok,
            'error' => $err,
        ]);
    }

    private function log_result(&$jobs, $job_id, $post_id, $status, $message, $old_url, $new_url)
    {
        $row = [
            'status' => $status,
            'post_id' => (int)$post_id,
            'message' => $message,
            'old_url' => $old_url,
            'new_url' => $new_url,
            'at' => time(),
        ];

        // Persistimos también en job->results (para auditoría)
        if (!isset($jobs[$job_id]['results']) || !is_array($jobs[$job_id]['results'])) {
            $jobs[$job_id]['results'] = [];
        }
        $jobs[$job_id]['results'][] = $row;

        return $row;
    }
}
require_once __DIR__ . '/includes/date-csv.php';

new PTC_Basic_Converter();
