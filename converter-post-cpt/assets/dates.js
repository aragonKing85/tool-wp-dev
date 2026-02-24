jQuery(function ($) {
  const $msg = $('#ptc-dates-messages');
  const $preview = $('#ptc-dates-preview');
  const $bar = $('#ptc-dates-progress-inner');
  const $txt = $('#ptc-dates-progress-text');
  const $pct = $('#ptc-dates-progress-percent');

  function esc(s){ return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;"); }
  function add(rows){
    rows.forEach(r=>{
      const cls = (r.status||'INFO').toLowerCase();
      const pid = r.post_id ? `#${r.post_id}` : '';
      const oldUrl = r.old_url ? `<div class="ptc-muted">Old: <a href="${r.old_url}" target="_blank" rel="noopener">${esc(r.old_url)}</a></div>` : '';
      const newUrl = r.new_url ? `<div class="ptc-muted">New: <a href="${r.new_url}" target="_blank" rel="noopener">${esc(r.new_url)}</a></div>` : '';
      $msg.prepend(`<div class="ptc-msg ptc-${cls}">
        <div class="ptc-msg-head"><strong>${esc(r.status||'INFO')}</strong> ${pid}</div>
        <div class="ptc-msg-body">${esc(r.message||'')}</div>${oldUrl}${newUrl}
      </div>`);
    });
  }
  function prog(done,total){
    const p = total ? Math.round((done/total)*100) : 0;
    $bar.css('width', p+'%'); $txt.text(`${done} / ${total}`); $pct.text(p+'%');
  }

  let staging = null; // rows + job_id

  function renderPreview(data){
    staging = data;

    const rows = data.rows || [];
    const target = data.target_post_type;
    let html = `<h3>Previsualización (${data.total} filas) · Unmatched: ${data.unmatched}</h3>`;
    html += `<p class="ptc-muted">Umbral auto-match: ${data.threshold}% · Post type: <strong>${esc(target)}</strong></p>`;

    html += `<table class="widefat striped">
      <thead><tr>
        <th>Fila</th><th>Título CSV</th><th>Fecha</th><th>Match</th><th>%</th><th>Acción</th>
      </tr></thead><tbody>`;

    rows.forEach((r, i) => {
      const best = r.match;
      const status = r.status;
      const score = best ? best.score : '';
      const matchText = best ? `${esc(best.title)} (ID:${best.id})` : '—';

      // candidates select
      const cands = (r.candidates||[]);
      const opts = cands.map(c => `<option value="${c.id}" ${best && c.id===best.id ? 'selected':''}>${esc(c.title)} (ID:${c.id}) · ${c.score}%</option>`).join('');
      const needs = status !== 'OK';

      html += `<tr data-row="${i}">
        <td>${r.row}</td>
        <td>${esc(r.csv_title)}</td>
        <td><code>${esc(r.csv_date)}</code></td>
        <td>${matchText}</td>
        <td>${score}</td>
        <td>
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <select class="ptc-date-cands" ${cands.length? '' : 'disabled'}>
              ${opts || '<option value="">(sin candidatos)</option>'}
            </select>

            <input class="ptc-date-search" type="text" placeholder="Buscar…" style="min-width:180px;" />
            <button class="button ptc-date-search-btn" data-post-type="${esc(target)}">Buscar</button>

            <select class="ptc-date-search-results" style="min-width:240px;">
              <option value="">— resultados —</option>
            </select>
            <button class="button ptc-date-assign">Asignar</button>

            ${needs ? `<span class="ptc-muted">Revisar</span>` : `<span class="ptc-muted">OK</span>`}
          </div>
        </td>
      </tr>`;
    });

    html += `</tbody></table>
      <p style="margin-top:10px;">
        <button class="button button-primary" id="ptc-dates-apply">Aplicar fechas (batch)</button>
      </p>`;

    $preview.html(html);
  }

  // Upload CSV -> preview
  $('#ptc-dates-upload-form').on('submit', function(e){
    e.preventDefault();
    $msg.empty(); $preview.empty(); prog(0,0);

    const fd = new FormData(this);
    fd.append('action','ptc_basic_dates_upload');
    fd.append('nonce', PTCBasicDates.nonce);

    add([{status:'INFO', message:'Subiendo CSV y generando previsualización…'}]);

    $.ajax({
      url: PTCBasicDates.ajaxUrl,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false
    }).done(res=>{
      if(!res || !res.success){
        add([{status:'ERROR', message: res?.data?.message || 'Error cargando CSV.'}]);
        return;
      }
      add([{status:'OK', message:`Previsualización lista. Job: ${res.data.job_id}`}]);
      renderPreview(res.data);
    }).fail(()=>{
      add([{status:'ERROR', message:'Fallo de red subiendo CSV.'}]);
    });
  });

  // Buscar AJAX
  $preview.on('click', '.ptc-date-search-btn', function(e){
    e.preventDefault();
    const $tr = $(this).closest('tr');
    const q = $tr.find('.ptc-date-search').val().trim();
    const postType = $(this).data('post-type');
    const $sel = $tr.find('.ptc-date-search-results');
    $sel.html('<option value="">Buscando…</option>');

    $.post(PTCBasicDates.ajaxUrl, {
      action: 'ptc_basic_dates_search',
      nonce: PTCBasicDates.nonce,
      q,
      post_type: postType
    }).done(res=>{
      if(!res || !res.success){
        $sel.html('<option value="">Error</option>');
        add([{status:'ERROR', message: res?.data?.message || 'Error en búsqueda.'}]);
        return;
      }
      const items = res.data.items || [];
      if(!items.length){
        $sel.html('<option value="">Sin resultados</option>');
        return;
      }
      $sel.html('<option value="">— resultados —</option>' + items.map(it=>`<option value="${it.id}">${esc(it.title)} (ID:${it.id})</option>`).join(''));
    }).fail(()=>{
      $sel.html('<option value="">Fallo de red</option>');
    });
  });

  // Asignar selección manual (desde candidatos o resultados)
  $preview.on('click', '.ptc-date-assign', function(e){
    e.preventDefault();
    const $tr = $(this).closest('tr');
    const rowIndex = parseInt($tr.attr('data-row'), 10);
    if (!staging || !staging.rows || !staging.rows[rowIndex]) return;

    const fromCand = parseInt($tr.find('.ptc-date-cands').val(), 10);
    const fromSearch = parseInt($tr.find('.ptc-date-search-results').val(), 10);
    const chosen = fromSearch || fromCand;

    if(!chosen){
      add([{status:'ERROR', message:'No has seleccionado ningún post para asignar.'}]);
      return;
    }

    // Update local staging: set match.id
    staging.rows[rowIndex].match = staging.rows[rowIndex].match || {};
    staging.rows[rowIndex].match.id = chosen;
    staging.rows[rowIndex].status = 'OK';
    staging.rows[rowIndex].message = 'Asignado manualmente.';

    add([{status:'OK', message:`Fila ${staging.rows[rowIndex].row}: asignada a ID ${chosen}.`}]);
    $.post(PTCBasicDates.ajaxUrl, {
  action: 'ptc_basic_dates_save_overrides',
  nonce: PTCBasicDates.nonce,
  job_id: staging.job_id,
  overrides: { [rowIndex]: chosen }
}).done(res=>{
  if(!res || !res.success){
    add([{status:'ERROR', message: res?.data?.message || 'No se pudieron guardar overrides.'}]);
    return;
  }
  add([{status:'OK', message:`Fila ${staging.rows[rowIndex].row}: override guardado (ID ${chosen}).`}]);
});
  });

  // Aplicar batch
  async function apply(jobId, total){
    let offset = 0;
    prog(0,total);

    while(offset < total){
      // enviamos staging actualizado al servidor en el option ya guardado: aquí simplifico
      // (para producción, lo ideal es un endpoint "save overrides").
      // Para no complicar, asumimos que si reasignas manualmente, vuelves a subir CSV o mantenemos en client.
      // En esta versión, el apply usa el staging del servidor, por lo que SOLO el auto-match se aplica.
      // Si quieres que lo manual se aplique también, te lo ajusto con un endpoint de "commit overrides".

      const res = await $.post(PTCBasicDates.ajaxUrl, {
        action: 'ptc_basic_dates_apply',
        nonce: PTCBasicDates.nonce,
        job_id: jobId,
        offset
      });

      if(!res || !res.success){
        add([{status:'ERROR', message: res?.data?.message || 'Error aplicando fechas.'}]);
        return;
      }

      add(res.data.messages || []);
      offset = res.data.processed || (offset + PTCBasicDates.batchSize);
      prog(offset,total);

      if(res.data.done) break;
    }

    add([{status:'OK', message:`Job de fechas completado (${total} filas).`}]);
  }

  $preview.on('click', '#ptc-dates-apply', function(e){
    e.preventDefault();
    if(!staging || !staging.job_id){
      add([{status:'ERROR', message:'No hay staging cargado. Sube el CSV primero.'}]);
      return;
    }
    add([{status:'INFO', message:'Aplicando fechas en batch…'}]);
    apply(staging.job_id, staging.total || (staging.rows||[]).length);
  });
});
