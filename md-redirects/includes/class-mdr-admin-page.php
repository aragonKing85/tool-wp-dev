<?php
if (!defined('ABSPATH')) exit;

class MDR_Admin_Page
{
  private $slug      = 'mdp-redirects';
  private $page_hook = '';

  public function __construct()
  {
    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_enqueue_scripts', [$this, 'assets']);
    add_action('admin_post_mdr_save', [$this, 'handle_save']);
    add_action('admin_post_mdr_delete', [$this, 'handle_delete']);
    add_action('admin_post_mdr_toggle', [$this, 'handle_toggle']);
    add_action('admin_post_mdr_export', [$this, 'handle_export']);
    add_action('admin_post_mdr_import', [$this, 'handle_import']);
    add_action('admin_post_mdr_delete_all', [$this, 'handle_delete_all']);
  }

  public function menu()
  {
    $this->page_hook = add_submenu_page(
      TWD_MENU,
      __('Redirecciones', 'mdp-redirects'),
      __('Redirecciones', 'mdp-redirects'),
      'manage_options',
      $this->slug,
      [$this, 'render']
    );
  }

  public function assets($hook)
  {
    if (!$this->page_hook || $hook !== $this->page_hook) return;

    // Bootstrap 5 (CDN)
    wp_enqueue_style(
      'bootstrap-5',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
      [],
      '5.3.3'
    );
    wp_enqueue_script(
      'bootstrap-5',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
      ['jquery'],
      '5.3.3',
      true
    );
    wp_enqueue_style(
      'bootstrap-icons',
      'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
      [],
      '1.11.3'
    );

    // Estilos/JS propios
    wp_enqueue_style('mdr-admin', MDR_PLUGIN_URL . 'assets/admin.css', ['bootstrap-5'], MDR_VERSION);
    wp_enqueue_script('mdr-admin', MDR_PLUGIN_URL . 'assets/admin.js', ['jquery'], MDR_VERSION, true);
    wp_localize_script('mdr-admin', 'MDR', [
      'ajaxurl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce('mdr-check'),
    ]);
  }


  public function handle_save()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('mdr_save');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    $data = [
      'source_path' => sanitize_text_field($_POST['source_path'] ?? ''),
      'target_url'  => esc_url_raw($_POST['target_url'] ?? ''),
      'status_code' => (int)($_POST['status_code'] ?? 301),
      'is_regex'    => !empty($_POST['is_regex']) ? 1 : 0,
      'enabled'     => !empty($_POST['enabled']) ? 1 : 0,
      'priority'    => (int)($_POST['priority'] ?? 0),
    ];

    if (empty($data['source_path'])) {
      wp_redirect(add_query_arg('mdr_msg', 'missing_source', wp_get_referer()));
      exit;
    }

    if ($id) {
      MDR_DB::update($id, $data);
      $msg = 'Se ha actualizado correctametne';
    } else {
      MDR_DB::create($data);
      $msg = 'Se ha añadido correctamente';
    }
    wp_redirect(add_query_arg('mdr_msg', $msg, admin_url('admin.php?page=' . $this->slug)));
    exit;
  }

  public function handle_delete()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('mdr_delete');
    $id = (int)($_GET['id'] ?? 0);
    if ($id) MDR_DB::delete($id);
    wp_redirect(add_query_arg('mdr_msg', 'Redirección eliminada con éxito', admin_url('admin.php?page=' . $this->slug)));
    exit;
  }

  public function handle_toggle()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('mdr_toggle');
    $id = (int)($_GET['id'] ?? 0);
    $row = MDR_DB::get($id);
    if ($row) {
      MDR_DB::update($id, ['enabled' => $row->enabled ? 0 : 1]);
    }
    wp_redirect(add_query_arg('mdr_msg', 'Redirección actualizada', admin_url('admin.php?page=' . $this->slug)));
    exit;
  }

  public function render()
  {
    if (!current_user_can('manage_options')) return;

    require_once MDR_PLUGIN_DIR . 'includes/class-mdr-list-table.php';

    $list_table = new MDR_List_Table();
    $list_table->process_bulk_action();
    $list_table->prepare_items();

    $edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    $editing = $edit_id ? MDR_DB::get($edit_id) : null;
    $is_edit = (bool) $editing;
?>

    <div class="wrap container-fluid py-3" style="background-color: white;">
      <h1 class="mb-4 d-flex justify-content-between align-items-center">
        <?php esc_html_e('Redirecciones', 'mdp-redirects'); ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#mdrFormCollapse" aria-expanded="<?php echo $is_edit ? 'true' : 'false'; ?>">
          <?php echo $is_edit ? 'Editar redirección' : '➕ Añadir nueva'; ?>
        </button>
      </h1>

      <?php if (!empty($_GET['mdr_msg'])): ?>
        <div class="alert alert-success"><?php echo esc_html($_GET['mdr_msg']); ?></div>
      <?php endif; ?>

      <!-- Formulario Añadir/Editar -->
      <div class="row mb-4">
        <div class="col">
          <div class="collapse <?php echo $is_edit ? 'show' : ''; ?>" id="mdrFormCollapse">
            <div class="card shadow-sm mb-4">
              <div class="card-body">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="row g-3">
                  <?php wp_nonce_field('mdr_save'); ?>
                  <input type="hidden" name="action" value="mdr_save">
                  <input type="hidden" name="id" value="<?php echo esc_attr($editing->id ?? 0); ?>">

                  <div class="col-md-6">
                    <label class="form-label" for="source_path">Origen (ruta o regex)</label>
                    <input class="form-control" name="source_path" id="source_path" type="text"
                      placeholder="/viejo o ^/blog/(.*)$" value="<?php echo esc_attr($editing->source_path ?? ''); ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="target_url">Destino (URL)</label>
                    <input class="form-control" name="target_url" id="target_url" type="text"
                      placeholder="/nuevo o https://dominio.com/nuevo" value="<?php echo esc_attr($editing->target_url ?? ''); ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" for="status_code">Código</label>
                    <select class="form-select" name="status_code" id="status_code">
                      <?php $codes = [301 => '301 (Permanente)', 302 => '302 (Temporal)', 410 => '410 (Gone)'];
                      $current = (int)($editing->status_code ?? 301);
                      foreach ($codes as $c => $label)
                        echo '<option value="' . $c . '"' . selected($current, $c, false) . '>' . $label . '</option>'; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" for="priority">Prioridad</label>
                    <input class="form-control" name="priority" id="priority" type="number" value="<?php echo esc_attr($editing->priority ?? 0); ?>">
                  </div>
                  <div class="col-md-3 d-flex align-items-center">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" name="is_regex" id="is_regex"
                        value="1" <?php checked(!empty($editing->is_regex)); ?>>
                      <label class="form-check-label" for="is_regex">Regex</label>
                    </div>
                  </div>

                  <div class="col-md-3 d-flex align-items-center">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="enabled"
                        value="1" <?php checked(!empty($editing->enabled) || !$editing); ?>>
                      <label class="form-check-label" for="enabled">Activa</label>
                    </div>
                  </div>

                  <div class="col-12 d-flex gap-2 mt-2">
                    <button class="btn btn-success" type="submit"><?php echo $is_edit ? 'Guardar cambios' : 'Crear redirección'; ?></button>
                    <?php if ($is_edit): ?>
                      <a class="btn btn-outline-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->slug)); ?>">Cancelar</a>
                    <?php endif; ?>
                  </div>
                </form>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- Filtros + Listado -->
      <div class="d-flex justify-content-start mb-3 gap-3">
        <button id="mdr-check-all" class="btn btn-outline-primary btn-sm">
          <span class="spinner-border spinner-border-sm d-none" id="mdr-spinner"></span>
          <i class="bi bi-arrow-repeat"></i> Comprobar todas
        </button>
        <button id="mdr-detect-loops" class="btn btn-outline-success">
          <i class="bi bi-arrow-repeat"></i> Detectar bucles
        </button>
      </div>


      <!-- resultados bucles -->
      <div id="mdr-loop-results" class="card shadow-sm mt-4 d-none">
        <div class="card-header bg-warning text-dark fw-semibold">
          <i class="bi bi-exclamation-triangle"></i> Bucles detectados
        </div>
        <div class="card-body p-0">
          <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Origen</th>
                <th>Destino</th>
                <th>Tipo</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody id="mdr-loop-body">
              <tr><td colspan="4" class="text-center text-muted py-3">No se han detectado bucles.</td></tr>
            </tbody>
          </table>
        </div>
      </div>


      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="page" value="<?php echo esc_attr($this->slug); ?>">
            <div class="col-md-4">
              <input class="form-control form-control-sm" type="search" name="s" value="<?php echo esc_attr($_GET['s'] ?? ''); ?>" placeholder="Buscar origen o destino">
            </div>
            <div class="col-md-1">
              <select class="form-select" name="code">
                <option value="">Código</option>
                <?php foreach ([301, 302, 410, 400, 404, 401, 500] as $c): ?>
                  <option value="<?php echo $c; ?>" <?php selected((int)($_GET['code'] ?? -1), $c); ?>><?php echo $c; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-1">
              <select class="form-select" name="regex">
                <option value="">Regex</option>
                <option value="1" <?php selected(($_GET['regex'] ?? '') === '1'); ?>>Sí</option>
                <option value="0" <?php selected(($_GET['regex'] ?? '') === '0'); ?>>No</option>
              </select>
            </div>
            <div class="col-md-1">
              <select class="form-select" name="enabled">
                <option value="">Activa</option>
                <option value="1" <?php selected(($_GET['enabled'] ?? '') === '1'); ?>>Sí</option>
                <option value="0" <?php selected(($_GET['enabled'] ?? '') === '0'); ?>>No</option>
              </select>
            </div>
            <div class="col-md-2">
              <button class="btn btn-outline-secondary btn-sm w-100" type="submit">Filtrar</button>
            </div>
               <div class="col-md-1">
              <button class="btn btn-outline-success btn-sm w-100" type="button" onclick="location.reload()">Actualizar</button>
            </div>
          </form>

          <form method="post">
            <?php wp_nonce_field('mdr_list_table'); ?>
            <?php $list_table->display(); ?>
          </form>
        </div>
      </div>

      <!-- Importar / Exportar -->
      <div class="card shadow-sm">
        <div class="card-header">Importar / Exportar CSV</div>
        <div class="card-body row g-3">
          <div class="col-md-6">
            <p><strong>Importar redirecciones</strong></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
              <?php wp_nonce_field('mdr_import'); ?>
              <input type="hidden" name="action" value="mdr_import">
              <div class="form-text mb-2">Formato: source_path,target_url,status_code,is_regex,enabled,priority</div>
              <input class="form-control mb-2" type="file" name="csv" accept=".csv" required>
              <button class="btn btn-success" type="submit">Importar CSV</button>
            </form>
          </div>
          <div class="col-md-6">
            <p><strong>Exportar redirecciones</strong></p>
            <p>Descárgate las redirecciones</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <?php wp_nonce_field('mdr_export'); ?>
              <input type="hidden" name="action" value="mdr_export">
              <button class="btn btn-outline-primary" type="submit">Exportar CSV</button>
            </form>
          </div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="mt-3 text-start">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('¿Seguro que quieres eliminar TODAS las redirecciones? Esta acción no se puede deshacer.');">
              <?php wp_nonce_field('mdr_delete_all'); ?>
              <input type="hidden" name="action" value="mdr_delete_all">
              <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash"></i> Eliminar todas las redirecciones
              </button>
            </form>
          </div>
        </div>
      </div>



    </div>

    <?php if (!empty($_GET['mdr_msg'])) : ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Esperar un momento para asegurar que el mensaje ya se ha renderizado
  setTimeout(() => {
    const url = new URL(window.location);
    url.searchParams.delete('mdr_msg');
    window.history.replaceState({}, document.title, url.toString());
  }, 100);

  setTimeout(() => {
    const msgContainer = document.querySelectorAll('.alert');
    msgContainer?.forEach(item=> {
      item.remove();
    })
  }, 2000);
});
</script>
<?php endif; ?>
<?php
  }

  public function handle_export()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('mdr_export');

    $rows = MDR_DB::all(); // todo
    $filename = 'redirects-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // Cabecera
    fputcsv($out, ['source_path', 'target_url', 'status_code', 'is_regex', 'enabled', 'priority']);
    foreach ($rows as $r) {
      fputcsv($out, [
        $r->source_path,
        $r->target_url,
        (int)$r->status_code,
        (int)$r->is_regex,
        (int)$r->enabled,
        (int)$r->priority
      ]);
    }
    fclose($out);
    exit;
  }
  public function handle_import()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('mdr_import');

    if (empty($_FILES['csv']['tmp_name'])) {
      wp_redirect(add_query_arg('mdr_msg', 'Archivo CSV requerido', admin_url('admin.php?page=' . $this->slug)));
      exit;
    }

    $file = $_FILES['csv']['tmp_name'];

    // 🔍 Detectar delimitador automáticamente (, o ;)
    $firstLine = fgets(fopen($file, 'r'));
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
    rewind(fopen($file, 'r'));

    $fh = fopen($file, 'r');
    if (!$fh) {
      wp_redirect(add_query_arg('mdr_msg', 'No se pudo abrir el CSV', admin_url('admin.php?page=' . $this->slug)));
      exit;
    }

    // Leer cabecera si existe
    $header = fgetcsv($fh, 0, $delimiter);
    $expected = ['source_path', 'target_url', 'status_code', 'is_regex', 'enabled', 'priority'];
    $has_header = is_array($header) && count(array_intersect(array_map('strtolower', $header), $expected)) >= 3;
    if (!$has_header) rewind($fh);

    $ok = 0;
    $fail = 0;
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
      // Saltar filas vacías
      if (empty(array_filter($row))) continue;

      $source = sanitize_text_field($row[0] ?? '');
      $target = esc_url_raw($row[1] ?? '');
      $code   = (int)($row[2] ?? 301);
      $regex  = (int)($row[3] ?? 0) ? 1 : 0;
      $enab   = (int)($row[4] ?? 1) ? 1 : 0;
      $prio   = (int)($row[5] ?? 0);

      if (!$source || !in_array($code, [301, 302, 410], true)) {
        $fail++;
        continue;
      }

      $id = MDR_DB::create([
        'source_path' => $source,
        'target_url'  => $code === 410 ? null : $target,
        'status_code' => $code,
        'is_regex'    => $regex,
        'enabled'     => $enab,
        'priority'    => $prio,
      ]);

      $id ? $ok++ : $fail++;
    }
    fclose($fh);

    wp_redirect(add_query_arg('mdr_msg', "Importado: {$ok} correctos / {$fail} fallidos", admin_url('admin.php?page=' . $this->slug)));
    exit;
  }
  public function handle_delete_all()
  {
    if (!current_user_can('manage_options')) wp_die('No tienes permisos suficientes.');
    check_admin_referer('mdr_delete_all');

    MDR_DB::delete_all();

    wp_redirect(add_query_arg('mdr_msg', 'Todas las redirecciones han sido eliminadas.', admin_url('admin.php?page=' . $this->slug)));
    exit;
  }
}
