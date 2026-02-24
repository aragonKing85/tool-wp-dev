<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MDR_List_Table extends WP_List_Table {
    private $filters = [];

    public function __construct($args = []) {
        parent::__construct([
            'singular' => 'redirect',
            'plural'   => 'redirects',
            'ajax'     => false
        ]);
        $this->filters = $args['filters'] ?? [];
    }

public function get_columns() {
    return [
        'cb'          => '',
        'source_path' => 'Origen',
        'status_code' => 'Código',
        'target_url'  => 'Destino',
        'final_code'  => 'Código final',
        'enabled'     => 'Estado',
        'hits'        => 'Hits',
        'priority'    => 'Prioridad',
        'actions'     => 'Acciones'
    ];
}

    protected function get_sortable_columns() {
        return [
            'id'          => ['id', true],
            'status_code' => ['status_code', false],
            'hits'        => ['hits', false],
            'priority'    => ['priority', true],
            'last_hit'    => ['last_hit', false],
        ];
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="ids[]" value="%d" />', (int)$item->id);
    }

protected function column_default($item, $column_name) {
    switch ($column_name) {
case 'source_path':
    $url = preg_match('#^https?://#i', $item->source_path)
        ? esc_url($item->source_path)
        : esc_url(home_url($item->source_path));

    return sprintf(
        '<code title="%s">%s</code> <a href="%s" target="_blank" class="text-decoration-none ms-1" title="Ver origen"><i class="bi bi-box-arrow-up-right"></i></a>',
        esc_attr($item->source_path),
        esc_html($item->source_path),
        $url
    );

        case 'status_code':
            return '<span class="badge text-bg-secondary">' . (int)$item->status_code . '</span>';

case 'target_url':
    if ((int)$item->status_code === 410) {
        return '<em>—</em>';
    }

    $url = preg_match('#^https?://#i', $item->target_url)
        ? esc_url($item->target_url)
        : esc_url(home_url($item->target_url));

    return sprintf(
        '<code title="%s">%s</code> <a href="%s" target="_blank" class="text-decoration-none ms-1" title="Ver destino"><i class="bi bi-box-arrow-up-right"></i></a>',
        esc_attr($item->target_url),
        esc_html($item->target_url),
        $url
    );

        case 'final_code':
            $final = get_transient('mdr_check_' . $item->id);
            if ($final) {
                $color = ($final >= 200 && $final < 400) ? 'success' : 'danger';
                return '<span class="badge text-bg-' . $color . '">' . esc_html($final) . '</span>';
            }
            return '<span class="text-muted">—</span>';

        case 'enabled':
            $color = $item->enabled ? 'success' : 'secondary';
            $label = $item->enabled ? 'Activa' : 'Inactiva';
            return '<span class="badge text-bg-' . $color . '">' . $label . '</span>';

        case 'hits':
            return '<span class="text-muted small">' . (int)$item->hits . '</span>';

        case 'priority':
            return '<span class="text-muted small">' . (int)$item->priority . '</span>';

        case 'actions':
            $id = (int)$item->id;
            $edit    = admin_url('admin.php?page=mdp-redirects&edit=' . $id);
            $toggle  = wp_nonce_url(admin_url('admin-post.php?action=mdr_toggle&id=' . $id), 'mdr_toggle');
            $delete  = wp_nonce_url(admin_url('admin-post.php?action=mdr_delete&id=' . $id), 'mdr_delete');

            return '
              <div class="d-flex gap-2">
                <a href="' . $edit . '" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil-square"></i></a>
                <a href="' . $toggle . '" class="btn btn-sm btn-outline-warning" title="Activar/Desactivar"><i class="bi bi-power"></i></a>
                <a href="' . $delete . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'¿Eliminar?\')" title="Eliminar"><i class="bi bi-trash"></i></a>
                <button type="button" class="btn btn-sm btn-outline-secondary mdr-check" data-id="' . $id . '" data-source="' . esc_attr($item->source_path) . '" data-target="' . esc_attr($item->target_url) . '" data-code="' . (int)$item->status_code . '" title="Comprobar"><i class="bi bi-arrow-repeat"></i></button>
              </div>';
    }
}

    public function get_bulk_actions() {
        return [
            'bulk_enable'  => 'Activar',
            'bulk_disable' => 'Desactivar',
            'bulk_delete'  => 'Eliminar'
        ];
    }

    public function process_bulk_action() {
        if (empty($_POST['ids']) || !current_user_can('manage_options')) return;
        check_admin_referer('mdr_list_table');

        $ids = array_map('intval', (array)$_POST['ids']);
        foreach ($ids as $id) {
            if ($this->current_action() === 'bulk_delete') {
                MDR_DB::delete($id);
            } elseif ($this->current_action() === 'bulk_enable') {
                MDR_DB::update($id, ['enabled' => 1]);
            } elseif ($this->current_action() === 'bulk_disable') {
                MDR_DB::update($id, ['enabled' => 0]);
            }
        }
    }

    public function prepare_items() {
        $per_page = 20;

        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $search = sanitize_text_field($_GET['s'] ?? '');
        $code   = isset($_GET['code']) && $_GET['code'] !== '' ? (int)$_GET['code'] : null;
        $regex  = isset($_GET['regex']) && $_GET['regex'] !== '' ? (int)$_GET['regex'] : null;
        $enabled= isset($_GET['enabled']) && $_GET['enabled'] !== '' ? (int)$_GET['enabled'] : null;

        $orderby = sanitize_key($_GET['orderby'] ?? 'priority');
        $order   = strtoupper($_GET['order'] ?? 'ASC');
        if (!in_array($order, ['ASC','DESC'], true)) $order = 'ASC';

        $result = MDR_DB::paged_filtered([
            'page'    => $paged,
            'per_page'=> $per_page,
            'search'  => $search,
            'code'    => $code,
            'regex'   => $regex,
            'enabled' => $enabled,
            'orderby' => $orderby,
            'order'   => $order
        ]);

        $this->items = $result['items'];
        $total_items = $result['total'];

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'source_path'];

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => max(1, ceil($total_items / $per_page))
        ]);
    }



}
