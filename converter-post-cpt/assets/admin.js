jQuery(function ($) {
  const $taxWrap = $('#ptc-taxonomies');
  const $dest = $('#ptc-dest-post-type');
  const $messages = $('#ptc-messages');
  const $progressInner = $('#ptc-progress-inner');
  const $progressText = $('#ptc-progress-text');
  const $progressPercent = $('#ptc-progress-percent');

  function msgRow(row) {
    const status = row.status || 'INFO';
    const pid = row.post_id ? `#${row.post_id}` : '';
    const oldUrl = row.old_url ? `<div class="ptc-muted">Old: <a href="${row.old_url}" target="_blank" rel="noopener">${row.old_url}</a></div>` : '';
    const newUrl = row.new_url ? `<div class="ptc-muted">New: <a href="${row.new_url}" target="_blank" rel="noopener">${row.new_url}</a></div>` : '';
    return `
      <div class="ptc-msg ptc-${status.toLowerCase()}">
        <div class="ptc-msg-head"><strong>${status}</strong> ${pid}</div>
        <div class="ptc-msg-body">${escapeHtml(row.message || '')}</div>
        ${oldUrl}${newUrl}
      </div>
    `;
  }

  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function setProgress(processed, total) {
    const pct = total > 0 ? Math.round((processed / total) * 100) : 0;
    $progressInner.css('width', pct + '%');
    $progressText.text(`${processed} / ${total}`);
    $progressPercent.text(pct + '%');
  }

  function addMessages(rows) {
    if (!rows || !rows.length) return;
    const html = rows.map(msgRow).join('');
    $messages.prepend(html);
  }

  $('#ptc-select-all').on('change', function () {
    const checked = $(this).is(':checked');
    $('.ptc-post').prop('checked', checked);
  });

  $dest.on('change', function () {
    const dest = $(this).val();
    $taxWrap.html('<div class="ptc-muted">Cargando taxonomías...</div>');
    if (!dest) {
      $taxWrap.html('<div class="ptc-muted">Selecciona un CPT destino para cargar sus taxonomías.</div>');
      return;
    }

    $.post(PTCBasic.ajaxUrl, {
      action: 'ptc_basic_get_dest_tax',
      nonce: PTCBasic.nonce,
      dest
    }).done(function (res) {
      if (!res || !res.success) {
        const msg = res?.data?.message || 'Error al cargar taxonomías.';
        $taxWrap.html(`<div class="ptc-error">${escapeHtml(msg)}</div>`);
        return;
      }

      const tax = res.data.taxonomies || [];
      if (!tax.length) {
        $taxWrap.html('<div class="ptc-muted">Este CPT no tiene taxonomías asignables.</div>');
        return;
      }

      let html = '';
      tax.forEach(t => {
        if (t.error) {
          html += `<div class="ptc-tax">
            <div class="ptc-tax-title">${escapeHtml(t.label)} <span class="ptc-muted">(${escapeHtml(t.taxonomy)})</span></div>
            <div class="ptc-error">Error cargando términos: ${escapeHtml(t.error)}</div>
          </div>`;
          return;
        }

        const options = (t.terms || []).map(term => {
          return `<option value="${term.id}">${escapeHtml(term.name)} (ID:${term.id})</option>`;
        }).join('');

        html += `
          <div class="ptc-tax">
            <div class="ptc-tax-title">${escapeHtml(t.label)} <span class="ptc-muted">(${escapeHtml(t.taxonomy)})</span></div>
            <select class="ptc-terms" multiple data-tax="${escapeHtml(t.taxonomy)}">
              ${options}
            </select>
            <div class="ptc-muted">Ctrl/Cmd para seleccionar múltiples.</div>
          </div>
        `;
      });

      $taxWrap.html(html);
    }).fail(function () {
      $taxWrap.html('<div class="ptc-error">Fallo de red al cargar taxonomías.</div>');
    });
  });

  function collectSelection() {
    return $('.ptc-post:checked').map(function () { return parseInt($(this).val(), 10); }).get();
  }

  function collectTermsByTax() {
    const termsByTax = {};
    $taxWrap.find('.ptc-terms').each(function () {
      const tax = $(this).data('tax');
      const vals = $(this).val() || [];
      termsByTax[tax] = vals.map(v => parseInt(v, 10));
    });
    return termsByTax;
  }

  async function processJob(jobId, total) {
    let offset = 0;
    setProgress(0, total);

    while (offset < total) {
      // eslint-disable-next-line no-await-in-loop
      const res = await $.post(PTCBasic.ajaxUrl, {
        action: 'ptc_basic_process_job',
        nonce: PTCBasic.nonce,
        job_id: jobId,
        offset
      });

      if (!res || !res.success) {
        const msg = res?.data?.message || 'Error desconocido en procesamiento.';
        addMessages([{ status: 'ERROR', message: msg }]);
        return;
      }

      addMessages(res.data.messages || []);
      offset = res.data.processed || (offset + PTCBasic.batchSize);
      setProgress(offset, total);

      if (res.data.done) break;
    }

    addMessages([{ status: 'OK', message: `Job completado (${total} items).` }]);
  }

  async function revertJob(jobId, total) {
    let offset = 0;
    setProgress(0, total);

    while (offset < total) {
      // eslint-disable-next-line no-await-in-loop
      const res = await $.post(PTCBasic.ajaxUrl, {
        action: 'ptc_basic_revert_job',
        nonce: PTCBasic.nonce,
        job_id: jobId,
        offset
      });

      if (!res || !res.success) {
        const msg = res?.data?.message || 'Error desconocido en reversión.';
        addMessages([{ status: 'ERROR', message: msg }]);
        return;
      }

      addMessages(res.data.messages || []);
      offset = res.data.processed || (offset + PTCBasic.batchSize);
      setProgress(offset, total);

      if (res.data.done) break;
    }

    addMessages([{ status: 'OK', message: `Reversión completada (${total} items). Recarga la página para ver contadores actualizados.` }]);
  }

  $('#ptc-start').on('click', function (e) {
    e.preventDefault();
    $messages.empty();

    const dest = $dest.val();
    if (!dest) {
      addMessages([{ status: 'ERROR', message: 'Selecciona un CPT destino.' }]);
      return;
    }

    const postIds = collectSelection();
    if (!postIds.length) {
      addMessages([{ status: 'ERROR', message: 'Selecciona al menos un post.' }]);
      return;
    }

    const termsByTax = collectTermsByTax();

    addMessages([{ status: 'INFO', message: `Creando job… (${postIds.length} posts)` }]);

    $.post(PTCBasic.ajaxUrl, {
      action: 'ptc_basic_start_job',
      nonce: PTCBasic.nonce,
      dest,
      post_ids: postIds,
      terms_by_tax: termsByTax
    }).done(function (res) {
      if (!res || !res.success) {
        const msg = res?.data?.message || 'Error al crear job.';
        addMessages([{ status: 'ERROR', message: msg }]);
        return;
      }

      const jobId = res.data.job_id;
      const total = res.data.total;

      addMessages([{ status: 'OK', message: `Job creado: ${jobId}. Iniciando conversión en batch…` }]);
      processJob(jobId, total);
    }).fail(function () {
      addMessages([{ status: 'ERROR', message: 'Fallo de red al crear el job.' }]);
    });
  });

  $('.ptc-basic').on('click', '.ptc-revert', function (e) {
    e.preventDefault();
    $messages.empty();

    const jobId = $(this).data('job');
    if (!jobId) return;

    // total está en la fila: columna "Total" (4ª)
    const $row = $(this).closest('tr');
    const total = parseInt($row.find('td').eq(3).text(), 10) || 0;

    addMessages([{ status: 'INFO', message: `Iniciando reversión del job ${jobId}… (${total} items)` }]);
    revertJob(jobId, total);
  });
});
