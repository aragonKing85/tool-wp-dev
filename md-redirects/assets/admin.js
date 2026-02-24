jQuery(function ($) {
  $(document).on("click", ".mdr-check", function (e) {
    e.preventDefault();
    const $btn = $(this);
$btn.prop("disabled", true).html('<i class="bi bi-hourglass-split"></i> Comprobando...');


    $.post(MDR.ajaxurl, {
      action: "mdr_check",
      nonce: MDR.nonce,
      source: $btn.data("source"),
      target: $btn.data("target"),
      code: $btn.data("code"),
      id: $btn.data("id"),
    })
      .done(function (resp) {
        if (!resp || !resp.success) {
          alert("Error en la comprobación.");
          return;
        }
        const d = resp.data;
      const msg = [
  `Origen: ${d.source_url}`,
  `HTTP origen: ${d.source_http}`,
  d.location ? `Location: ${d.location}` : 'Location: —',
  `Código final destino: ${d.target_http ?? 'n/a'}`,
  d.ok ? '✅ OK' : '❌ Error o redirección incorrecta'
].join('\n');

        alert(msg);

        // Actualiza badge "Código final"
   const finalCode = d.target_http ?? d.source_http;
const color = (finalCode >= 200 && finalCode < 400) ? 'success' : 'danger';
const badge = `<span class="badge text-bg-${color}">${finalCode}</span>`;
const color2 =  (d.source_http >= 200 && d.source_http < 400) ? 'success' : 'danger';
const badgeOrigin = `<span class="badge text-bg-${color2}">${d.source_http}</span>`;
$btn.closest('tr').find('td:nth-child(5)').html(badge);
$btn.closest('tr').find('td:nth-child(3)').html(badgeOrigin);
      })
      .always(function () {
        $btn.prop("disabled", false).html('<i class="bi bi-arrow-repeat"></i>');
      });
  });


    // --- NUEVO: Comprobar todas ---
$('#mdr-check-all').on('click', function(e){
    e.preventDefault();
    const $btn = $(this);
    $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Comprobando todas...');

    $.post(MDR.ajaxurl, {
      action: 'mdr_check_all',
      nonce: MDR.nonce
    }).done(function(resp){
      if (!resp || !resp.success) {
        alert('Error al comprobar redirecciones.');
        return;
      }

      const results = resp.data.results;
      let ok = 0, fail = 0;

      results.forEach(r => {
        const row = $('tr[data-id="'+r.id+'"]');
        if (!row.length) return;

        const code = r.final_code ?? 'ERR';
        const color = (r.ok) ? 'success' : 'danger';
        // const codeInit = r.final_code ?? 'ERR';
        // const color2 = (r.ok) ? 'success' : 'danger';

        // Actualizar la celda "Código final"
        const cell = row.find('td:nth-child(5)');
        if (cell.length) {
          cell.html(`<span class="badge text-bg-${color}">${code}</span>`);
        }

        r.ok ? ok++ : fail++;
      });

      alert(`✅ ${ok} correctas | ❌ ${fail} con error`);
    }).fail(function(){
      alert('No se pudo conectar con el servidor.');
    }).always(function(){
      $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Comprobar todas');
    });
  });


$('#mdr-detect-loops').on('click', function(e){
    e.preventDefault();
    const $btn = $(this);
    const $table = $('#mdr-loop-results');
    const $tbody = $('#mdr-loop-body');

    $btn.prop('disabled', true)
        .html('<i class="bi bi-hourglass-split"></i> Detectando...');

    // Ocultamos la tabla mientras busca
    $table.addClass('d-none');
    $tbody.html('<tr><td colspan="4" class="text-center text-muted py-3">Analizando redirecciones...</td></tr>');

    $.post(MDR.ajaxurl, {
      action: 'mdr_detect_loops',
      nonce: MDR.nonce
    }).done(function(resp){
      if (!resp || !resp.success) {
        alert('Error al analizar las redirecciones.');
        return;
      }

 const loops = resp.data.loops;
$tbody.empty();

if (!loops.length) {
  $tbody.html('<tr><td colspan="4" class="text-center text-success py-3">✅ No se han detectado bucles de redirección.</td></tr>');
} else {
  loops.forEach((pair, i) => {
    const src = pair.source;
    const tgt = pair.target;
    const editUrl = MDR.admin_page + '&s=' + encodeURIComponent(src);

    $tbody.append(`
      <tr>
        <td><code>${src}</code></td>
        <td><code>${tgt}</code></td>
        <td><span class="badge text-bg-warning">Bucle</span></td>
        <td class="text-center">
          <a href="${editUrl}" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil-square"></i></a>
          <button data-src="${src}" class="btn btn-sm btn-outline-danger mdr-del-loop" title="Eliminar"><i class="bi bi-trash"></i></button>
        </td>
      </tr>
    `);
  });
}


      $table.removeClass('d-none');
    }).fail(function(){
      alert('No se pudo conectar con el servidor.');
    }).always(function(){
      $btn.prop('disabled', false)
          .html('<i class="bi bi-arrow-repeat"></i> Detectar bucles');
    });
  });

  // Acción de eliminar en resultados
  $(document).on('click', '.mdr-del-loop', function(){
    if (!confirm('¿Seguro que quieres eliminar esta redirección del bucle?')) return;

    const src = $(this).data('src');
    $.post(MDR.ajaxurl, {
      action: 'mdr_delete_by_source',
      nonce: MDR.nonce,
      source: src
    }).done(function(resp){
      if (resp.success) {
        $(`button[data-src="${src}"]`).closest('tr').remove();
      } else {
        alert(resp.data?.message || 'No se pudo eliminar la redirección.');
      }
    });
  });

  //end
});
