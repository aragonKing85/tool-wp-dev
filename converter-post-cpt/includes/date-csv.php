<?php
if (!defined('ABSPATH')) exit;

class PTC_Basic_Date_CSV {
  const OPT_DATE_JOBS = 'ptc_basic_date_jobs';
  const OPT_DATE_JOB_TMP = 'ptc_basic_date_job_tmp'; // staging del CSV parseado
  const META_DATE_LAST = '_ptc_basic_date_last';

  /** @var PTC_Basic_Converter */
  private $core;

  public function __construct($core) {
    $this->core = $core;

    add_action('admin_menu', [$this, 'admin_menu_link'], 20);

    add_action('wp_ajax_ptc_basic_dates_upload', [$this, 'ajax_upload_csv']);
    add_action('wp_ajax_ptc_basic_dates_search', [$this, 'ajax_search_posts']);
    add_action('wp_ajax_ptc_basic_dates_apply', [$this, 'ajax_apply_dates_batch']);

    add_action('wp_ajax_ptc_basic_dates_save_overrides', [$this, 'ajax_save_overrides']);

  }

  public function admin_menu_link() {
    // No añade menú nuevo: reutiliza la misma página. Solo añade "tab" por querystring.
    // (No hace falta nada aquí, pero lo dejo por si en el futuro quieres menú separado)
  }

  public function ajax_save_overrides() {
  $this->ensure();
  check_ajax_referer(PTC_Basic_Converter::NONCE_ACTION, 'nonce');

  $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
  $overrides = isset($_POST['overrides']) && is_array($_POST['overrides']) ? $_POST['overrides'] : [];

  $staging = get_option(self::OPT_DATE_JOB_TMP, []);
  if (empty($staging['job_id']) || $staging['job_id'] !== $job_id) {
    wp_send_json_error(['code'=>'job_not_found','message'=>'No staging para este job.'], 404);
  }

  $rows = $staging['rows'] ?? [];
  foreach ($overrides as $rowIndex => $postId) {
    $rowIndex = (int)$rowIndex;
    $postId = (int)$postId;
    if (!isset($rows[$rowIndex])) continue;
    if ($postId <= 0) continue;

    $rows[$rowIndex]['match'] = $rows[$rowIndex]['match'] ?? [];
    $rows[$rowIndex]['match']['id'] = $postId;
    $rows[$rowIndex]['status'] = 'OK';
    $rows[$rowIndex]['message'] = 'Asignado manualmente.';
  }

  $staging['rows'] = $rows;
  update_option(self::OPT_DATE_JOB_TMP, $staging, false);

  wp_send_json_success(['message'=>'Overrides guardados.']);
}

  public function render_tab() {
    if (!current_user_can(PTC_Basic_Converter::CAPABILITY)) {
      echo '<div class="notice notice-error"><p>No tienes permisos suficientes.</p></div>';
      return;
    }

    $post_types = get_post_types(['show_ui' => true], 'objects');
    ?>
    <div class="ptc-card">
      <h2>Fechas por CSV</h2>
      <p class="ptc-muted">
        CSV con dos columnas: <strong>Título</strong> y <strong>Fecha</strong>. Se hace matching “fuzzy” (sin acentos) y se muestra una previsualización.
      </p>

      <form id="ptc-dates-upload-form" method="post" enctype="multipart/form-data">
        <div class="ptc-filters-row">
          <label>
            Post type objetivo
            <select name="target_post_type" id="ptc-dates-post-type">
              <?php foreach ($post_types as $k => $obj): if ($k === 'attachment') continue; ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($k, 'capitulo'); ?>>
                  <?php echo esc_html(($obj->labels->singular_name ?? $k) . " ({$k})"); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            Zona horaria
            <select name="tz_mode" id="ptc-dates-tz">
              <option value="site" selected>Hora local del sitio</option>
              <option value="utc">UTC</option>
            </select>
          </label>

          <label>
            CSV
            <input type="file" name="csv" accept=".csv,text/csv" required />
          </label>

          <button class="button button-primary" id="ptc-dates-upload">Cargar y previsualizar</button>
        </div>
      </form>

      <hr />

      <div class="ptc-progress">
        <div class="ptc-progress-bar">
          <div class="ptc-progress-bar-inner" id="ptc-dates-progress-inner" style="width:0%"></div>
        </div>
        <div class="ptc-progress-meta">
          <span id="ptc-dates-progress-text">0 / 0</span>
          <span id="ptc-dates-progress-percent">0%</span>
        </div>
      </div>

      <div class="ptc-messages" id="ptc-dates-messages"></div>

      <div id="ptc-dates-preview"></div>
    </div>
    <?php
  }

  public function ajax_upload_csv() {
    $this->ensure();
    check_ajax_referer(PTC_Basic_Converter::NONCE_ACTION, 'nonce');

    if (empty($_FILES['csv'])) {
      wp_send_json_error(['code'=>'missing_file','message'=>'No se recibió ningún CSV.'], 400);
    }

    $target = isset($_POST['target_post_type']) ? sanitize_key(wp_unslash($_POST['target_post_type'])) : 'capitulo';
    if (!post_type_exists($target)) {
      wp_send_json_error(['code'=>'invalid_post_type','message'=>"El post_type '{$target}' no existe."], 400);
    }

    $tz_mode = isset($_POST['tz_mode']) ? sanitize_key(wp_unslash($_POST['tz_mode'])) : 'site';
    if (!in_array($tz_mode, ['site','utc'], true)) $tz_mode = 'site';

    $file = $_FILES['csv'];
    if (!empty($file['error'])) {
      wp_send_json_error(['code'=>'upload_error','message'=>'Error subiendo el CSV (código '.$file['error'].').'], 400);
    }

    $tmp = $file['tmp_name'];
    $content = file_get_contents($tmp);
    if ($content === false || trim($content) === '') {
      wp_send_json_error(['code'=>'empty_csv','message'=>'El CSV está vacío o no se pudo leer.'], 400);
    }

    // Detectar separador: si hay más ; que , en la primera línea, asumimos ;
    $lines = preg_split("/\r\n|\n|\r/", $content);
    $firstLine = $lines[0] ?? '';
    $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $rows = $this->parse_csv($content, $sep);
    if (empty($rows)) {
      wp_send_json_error(['code'=>'no_rows','message'=>'No se detectaron filas válidas en el CSV.'], 400);
    }

    // Index rápido de títulos del post_type (1058 = OK)
    $index = $this->build_title_index($target);

    // Intentar matching
    $mapped = [];
    $unmatched = 0;
    foreach ($rows as $i => $r) {
      $csv_title = $r['title'];
      $csv_date_raw = $r['date_raw'];

      $dt = $this->parse_date($csv_date_raw, $tz_mode);
      if (!$dt) {
        $mapped[] = [
          'row' => $i+1,
          'csv_title' => $csv_title,
          'csv_date' => $csv_date_raw,
          'status' => 'ERROR',
          'message' => 'Fecha inválida o no reconocible.',
          'match' => null,
          'candidates' => [],
        ];
        continue;
      }

      [$best, $candidates] = $this->fuzzy_match($csv_title, $index, 5);

      $auto_ok = $best && ($best['score'] >= 78); // umbral
      if (!$auto_ok) $unmatched++;

      $mapped[] = [
        'row' => $i+1,
        'csv_title' => $csv_title,
        'csv_date' => $dt->format('Y-m-d H:i:s'),
        'status' => $auto_ok ? 'OK' : 'NEEDS',
        'message' => $auto_ok ? 'Match automático.' : 'Requiere revisión/selección manual.',
        'match' => $best,
        'candidates' => $candidates,
      ];
    }

    $job_id = 'datejob_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);

    // Guardar staging para aplicar después
    update_option(self::OPT_DATE_JOB_TMP, [
      'job_id' => $job_id,
      'created_at' => time(),
      'created_by' => get_current_user_id(),
      'target_post_type' => $target,
      'tz_mode' => $tz_mode,
      'rows' => $mapped,
    ], false);

    wp_send_json_success([
      'job_id' => $job_id,
      'target_post_type' => $target,
      'tz_mode' => $tz_mode,
      'rows' => $mapped,
      'unmatched' => $unmatched,
      'total' => count($mapped),
      'threshold' => 78,
    ]);
  }

  public function ajax_search_posts() {
    $this->ensure();
    check_ajax_referer(PTC_Basic_Converter::NONCE_ACTION, 'nonce');

    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'capitulo';

    if (!$q || strlen($q) < 2) {
      wp_send_json_success(['items' => []]);
    }
    if (!post_type_exists($post_type)) {
      wp_send_json_error(['code'=>'invalid_post_type','message'=>'post_type inválido.'], 400);
    }

    $res = new WP_Query([
      'post_type' => $post_type,
      'post_status' => ['publish','draft','pending','private','future'],
      's' => $q,
      'posts_per_page' => 20,
      'fields' => 'ids',
    ]);

    $items = [];
    foreach ($res->posts as $id) {
      $items[] = [
        'id' => (int)$id,
        'title' => get_the_title($id),
      ];
    }

    wp_send_json_success(['items' => $items]);
  }

  public function ajax_apply_dates_batch() {
    $this->ensure();
    check_ajax_referer(PTC_Basic_Converter::NONCE_ACTION, 'nonce');

    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
    $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;

    $staging = get_option(self::OPT_DATE_JOB_TMP, []);
    if (empty($staging['job_id']) || $staging['job_id'] !== $job_id) {
      wp_send_json_error(['code'=>'job_not_found','message'=>'No se encontró el staging del job (¿recargaste o expiró?). Vuelve a subir el CSV.'], 404);
    }

    $rows = $staging['rows'] ?? [];
    $tz_mode = $staging['tz_mode'] ?? 'site';
    $target = $staging['target_post_type'] ?? 'capitulo';
    $batch = PTC_Basic_Converter::DEFAULT_BATCH;

    $slice = array_slice($rows, $offset, $batch);
    $total = count($rows);

    global $wpdb;

    $messages = [];
    $ok = 0;
    $err = 0;

    foreach ($slice as $idx => $row) {
      // Resolver el post_id:
      $post_id = 0;

      // El frontend puede enviar override: row_overrides[rowNumber] = postId
      // pero para simplificar, lo guardamos en la propia fila vía "match.id".
      if (!empty($row['match']['id'])) {
        $post_id = (int)$row['match']['id'];
      }

      if ($row['status'] === 'ERROR') {
        $err++;
        $messages[] = ['status'=>'ERROR','post_id'=>0,'message'=>"Fila {$row['row']}: {$row['csv_title']} → {$row['message']}"];
        continue;
      }

      if ($post_id <= 0) {
        $err++;
        $messages[] = ['status'=>'ERROR','post_id'=>0,'message'=>"Fila {$row['row']}: sin post asociado (requiere selección manual)."];
        continue;
      }

      $post = get_post($post_id);
      if (!$post) {
        $err++;
        $messages[] = ['status'=>'ERROR','post_id'=>$post_id,'message'=>"Fila {$row['row']}: post_id {$post_id} no existe."];
        continue;
      }
      if ($post->post_type !== $target) {
        $err++;
        $messages[] = ['status'=>'ERROR','post_id'=>$post_id,'message'=>"Fila {$row['row']}: el post {$post_id} no es '{$target}' (es '{$post->post_type}')."];
        continue;
      }

      $dt = $this->parse_date($row['csv_date'], $tz_mode);
      if (!$dt) {
        $err++;
        $messages[] = ['status'=>'ERROR','post_id'=>$post_id,'message'=>"Fila {$row['row']}: fecha no parseable '{$row['csv_date']}'."];
        continue;
      }

      // Calcular GMT
      $dt_gmt = clone $dt;
      $dt_gmt->setTimezone(new DateTimeZone('UTC'));

      $new_date = $dt->format('Y-m-d H:i:s');
      $new_date_gmt = $dt_gmt->format('Y-m-d H:i:s');

      $old_date = $post->post_date;
      $old_date_gmt = $post->post_date_gmt;
      $old_url = get_permalink($post_id);

      // Guardar reversible mínimo
      $meta_payload = [
        'job_id' => $job_id,
        'at' => time(),
        'user_id' => get_current_user_id(),
        'old_post_date' => $old_date,
        'old_post_date_gmt' => $old_date_gmt,
        'new_post_date' => $new_date,
        'new_post_date_gmt' => $new_date_gmt,
        'old_url' => $old_url,
      ];
      update_post_meta($post_id, self::META_DATE_LAST, wp_json_encode($meta_payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

      // Update directo a wp_posts para no tocar modified automáticamente
      $updated = $wpdb->update(
        $wpdb->posts,
        ['post_date' => $new_date, 'post_date_gmt' => $new_date_gmt],
        ['ID' => $post_id],
        ['%s','%s'],
        ['%d']
      );

      if ($updated === false) {
        $err++;
        $messages[] = ['status'=>'ERROR','post_id'=>$post_id,'message'=>"ID {$post_id}: error SQL actualizando fecha. ".$wpdb->last_error, 'old_url'=>$old_url];
        continue;
      }

      clean_post_cache($post_id);
      $new_url = get_permalink($post_id);

      $ok++;
      $messages[] = [
        'status'=>'OK',
        'post_id'=>$post_id,
        'message'=>"Fecha actualizada: {$old_date} → {$new_date}",
        'old_url'=>$old_url,
        'new_url'=>$new_url
      ];
    }

    $processed = min($total, $offset + count($slice));
    $done = ($processed >= $total);

    // Crear/actualizar job persistente (auditoría)
    $jobs = get_option(self::OPT_DATE_JOBS, []);
    if (!is_array($jobs)) $jobs = [];
    if (empty($jobs[$job_id])) {
      $jobs[$job_id] = [
        'created_at' => $staging['created_at'],
        'created_by' => $staging['created_by'],
        'target_post_type' => $target,
        'total' => $total,
        'ok' => 0,
        'error' => 0,
        'processed' => 0,
        'results' => [],
      ];
    }

    $jobs[$job_id]['processed'] = $processed;
    $jobs[$job_id]['ok'] += $ok;
    $jobs[$job_id]['error'] += $err;
    $jobs[$job_id]['results'] = array_merge($jobs[$job_id]['results'], $messages);
    update_option(self::OPT_DATE_JOBS, $jobs, false);

    wp_send_json_success([
      'job_id' => $job_id,
      'total' => $total,
      'processed' => $processed,
      'done' => $done,
      'messages' => $messages,
    ]);
  }

  // --------------------
  // Helpers
  // --------------------

  private function ensure() {
    if (!current_user_can(PTC_Basic_Converter::CAPABILITY)) {
      wp_send_json_error(['code'=>'forbidden','message'=>'No tienes permisos.'], 403);
    }
  }

  private function parse_csv($content, $sep) {
    $lines = preg_split("/\r\n|\n|\r/", $content);
    $out = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') continue;

      $cols = str_getcsv($line, $sep);
      if (!$cols || count($cols) < 2) continue;

      $title = trim((string)$cols[0]);
      $date  = trim((string)$cols[1]);

      if ($title === '' || $date === '') continue;

      // Saltar header típico
      if (mb_strtolower($title) === 'title' || mb_strtolower($title) === 'titulo') {
        continue;
      }

      $out[] = ['title' => $title, 'date_raw' => $date];
    }
    return $out;
  }

  private function parse_date($dateStr, $tz_mode) {
    $dateStr = trim((string)$dateStr);
    if ($dateStr === '') return null;

    $tz = ($tz_mode === 'utc') ? new DateTimeZone('UTC') : wp_timezone();

    // Normalizar: si solo viene YYYY-MM-DD, ponemos 00:00:00
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
      $dateStr .= ' 00:00:00';
    }

    // Intentar formatos comunes primero
    $formats = [
      'Y-m-d H:i:s',
      'Y-m-d H:i',
      'd/m/Y H:i:s',
      'd/m/Y H:i',
      'd/m/Y',
      'Y-m-d',
    ];

    foreach ($formats as $f) {
      $dt = DateTime::createFromFormat($f, $dateStr, $tz);
      if ($dt instanceof DateTime) return $dt;
    }

    // Fallback: strtotime (menos fiable)
    $ts = strtotime($dateStr);
    if ($ts === false) return null;

    $dt = new DateTime('@'.$ts);
    $dt->setTimezone($tz);
    return $dt;
  }

  private function norm_title($s) {
    $s = (string)$s;
    $s = remove_accents($s);
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
  }

  private function build_title_index($post_type) {
    $q = new WP_Query([
      'post_type' => $post_type,
      'post_status' => ['publish','draft','pending','private','future'],
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
    ]);

    $index = [];
    foreach ($q->posts as $id) {
      $t = get_the_title($id);
      $index[] = [
        'id' => (int)$id,
        'title' => $t,
        'norm' => $this->norm_title($t),
      ];
    }
    return $index;
  }

  private function fuzzy_match($csv_title, $index, $topN = 5) {
    $needle = $this->norm_title($csv_title);
    if ($needle === '') return [null, []];

    $scores = [];
    foreach ($index as $item) {
      $hay = $item['norm'];

      // similar_text es suficiente aquí; 1k items = ok.
      $pct = 0;
      similar_text($needle, $hay, $pct);

      $scores[] = [
        'id' => $item['id'],
        'title' => $item['title'],
        'score' => (int)round($pct),
      ];
    }

    usort($scores, fn($a,$b) => $b['score'] <=> $a['score']);
    $cands = array_slice($scores, 0, $topN);
    $best = $cands[0] ?? null;

    return [$best, $cands];
  }
}
