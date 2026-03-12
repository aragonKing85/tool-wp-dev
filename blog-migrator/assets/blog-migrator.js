/**
 * Blog Migrator — Componente Vue principal.
 *
 * Arquitectura de datos:
 * - allPosts       : todos los posts descargados del servidor (una sola petición).
 * - filteredPosts  : subconjunto de allPosts filtrado por searchQuery (computed).
 * - paginatedPosts : página actual de filteredPosts según perPage (computed).
 *
 * La paginación y el buscador son 100% en cliente (Vue).
 * El servidor solo hace una petición que devuelve todos los posts.
 *
 * @package Tool_WP_Dev
 * @module  blog-migrator
 */
const { createApp, ref, computed, watch } = Vue;

createApp({
    setup() {

        /* ── Estado ──────────────────────────────────────────────────────── */
        const domain       = ref('');
        const connected    = ref(false);
        const languages    = ref([]);
        const selectedLang = ref('');
        const allPosts     = ref([]);    // Todos los posts (descargados de una vez)
        const loading      = ref(false);

        // Búsqueda y paginación en cliente
        const searchQuery = ref('');
        const currentPage = ref(1);
        const perPage     = ref(100);

        // Selector global de estado al importar
        const postStatusMode = ref('original');

        // Selección persistente entre páginas
        const selectedIds       = ref([]);    // array de IDs numéricos
        const selectedPostsData = ref({});    // id → post data (para import)

        // Notificaciones
        const notification = ref(null);
        let notifTimeout   = null;

        // Batching
        const jobStatus       = ref(null);
        const pollingInterval = ref(null);

        /* ── Notificaciones ──────────────────────────────────────────────── */

        /**
         * Muestra una notificación tipada.
         * Si type !== 'loading', desaparece automáticamente a los 5 s.
         */
        function notify(type, message) {
            clearTimeout(notifTimeout);
            notification.value = { type, message };
            if (type !== 'loading') {
                notifTimeout = setTimeout(() => { notification.value = null; }, 5000);
            }
        }

        /* ── AJAX ────────────────────────────────────────────────────────── */

        async function ajax(action, data = {}) {
            const body = new URLSearchParams({
                action,
                nonce:  bm_ajax.nonce,
                domain: domain.value,
                ...data,
            });
            const res = await fetch(bm_ajax.ajax_url, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
            });
            return res.json();
        }

        /* ── Helpers UI ──────────────────────────────────────────────────── */

        function statusLabel(status) {
            const map = {
                publish: 'Publicado',
                draft:   'Borrador',
                pending: 'Pendiente',
                private: 'Privado',
                future:  'Programado',
            };
            return map[status] || status;
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            try {
                return new Date(dateStr).toLocaleDateString('es-ES', {
                    year: 'numeric', month: 'short', day: 'numeric',
                });
            } catch { return dateStr; }
        }

        /* ── Posts filtrados y paginados (computed) ───────────────────────── */

        /** Posts filtrados por el buscador. */
        const filteredPosts = computed(() => {
            const q = searchQuery.value.trim().toLowerCase();
            if (!q) return allPosts.value;
            return allPosts.value.filter(p => p.title.toLowerCase().includes(q));
        });

        /** Total de páginas según el filtro actual. */
        const totalPages = computed(() =>
            Math.max(1, Math.ceil(filteredPosts.value.length / perPage.value))
        );

        /** Posts visibles en la página actual. */
        const paginatedPosts = computed(() => {
            const start = (currentPage.value - 1) * perPage.value;
            return filteredPosts.value.slice(start, start + perPage.value);
        });

        // Resetear a página 1 cuando cambia el buscador o el per_page
        watch(searchQuery, () => { currentPage.value = 1; });
        watch(perPage,     () => { currentPage.value = 1; });

        /* ── Selección ───────────────────────────────────────────────────── */

        function isSelected(id) {
            return selectedIds.value.includes(id);
        }

        function togglePost(post) {
            const idx = selectedIds.value.indexOf(post.id);
            if (idx === -1) {
                selectedIds.value.push(post.id);
                selectedPostsData.value[post.id] = post;
            } else {
                selectedIds.value.splice(idx, 1);
                delete selectedPostsData.value[post.id];
            }
        }

        /** Total seleccionados (todas las páginas). */
        const selectedCount = computed(() => selectedIds.value.length);

        /** Posts seleccionados con data completa para el import. */
        const selectedPosts = computed(() =>
            selectedIds.value.map(id => selectedPostsData.value[id]).filter(Boolean)
        );

        /** True si todos los posts de la página visible están seleccionados. */
        const isAllCurrentPageSelected = computed(() =>
            paginatedPosts.value.length > 0 &&
            paginatedPosts.value.every(p => isSelected(p.id))
        );

        /** True si solo algunos posts de la página visible están seleccionados. */
        const isSomeCurrentPageSelected = computed(() =>
            paginatedPosts.value.some(p => isSelected(p.id)) &&
            !isAllCurrentPageSelected.value
        );

        /** Selecciona / deselecciona todos los posts de la página actual. */
        function toggleAll(e) {
            const check = e.target.checked;
            paginatedPosts.value.forEach(p => {
                if (check) {
                    if (!isSelected(p.id)) {
                        selectedIds.value.push(p.id);
                        selectedPostsData.value[p.id] = p;
                    }
                } else {
                    const idx = selectedIds.value.indexOf(p.id);
                    if (idx !== -1) {
                        selectedIds.value.splice(idx, 1);
                        delete selectedPostsData.value[p.id];
                    }
                }
            });
        }

        /* ── Conexión ────────────────────────────────────────────────────── */

        async function checkConnection() {
            if (!domain.value.trim()) return notify('error', 'Introduce un dominio válido.');
            notify('loading', 'Comprobando conexión...');
            const res = await ajax('bm_check_connection');
            if (res.success) {
                connected.value = true;
                notify('success', res.data.message);
            } else {
                connected.value = false;
                notify('error', res.data.message);
            }
        }

        async function loadLanguages() {
            if (!connected.value) return;
            notify('loading', 'Buscando idiomas disponibles...');
            const res = await ajax('bm_get_languages');
            if (res.success && res.data.languages.length > 0) {
                languages.value = res.data.languages;
                notify('success', `${languages.value.length} idiomas detectados (${res.data.source}).`);
            } else {
                languages.value = [];
                notify('success', 'No se detectaron idiomas (sitio monolingüe o API no disponible).');
            }
        }

        /* ── Exploración (carga completa, paginación en Vue) ─────────────── */

        /**
         * Descarga TODOS los posts del origen en una sola petición PHP.
         * Vue pagina el resultado en cliente.
         */
        async function explorePosts() {
            if (!connected.value) return;
            loading.value  = true;
            currentPage.value  = 1;
            searchQuery.value  = '';
            allPosts.value     = [];
            notify('loading', 'Cargando todos los posts del origen…');

            const res = await ajax('bm_explore_posts', { lang: selectedLang.value });
            loading.value = false;

            if (!res.success) return notify('error', res.data.message);

            allPosts.value = res.data.posts;
            notify('success', `${res.data.count} posts encontrados.`);
        }

        /* ── Importación con batching ─────────────────────────────────────── */

        async function importSelected() {
            if (selectedPosts.value.length === 0) {
                return notify('error', 'Selecciona al menos un post.');
            }

            notify('loading', `Iniciando importación de ${selectedPosts.value.length} posts…`);
            loading.value = true;

            const selected = selectedPosts.value.map(p => ({
                id:     p.id,
                status: p.status,  // estado original del API origen
                title:  p.title,
            }));

            const initRes = await ajax('bm_start_import', {
                selected:         JSON.stringify(selected),
                batch_size:       25,
                post_status_mode: postStatusMode.value,
            });

            if (!initRes.success) {
                loading.value = false;
                return notify('error', initRes.data.message);
            }

            const totalBatches = initRes.data.job_state.total_batches;
            startPolling();
            await getJobStatus();

            for (let i = 0; i < totalBatches; i++) {
                notify('loading', `Procesando lote ${i + 1} de ${totalBatches}…`);
                const batchRes = await ajax('bm_process_batch', { batch_index: i });

                if (!batchRes.success) {
                    loading.value = false;
                    stopPolling();
                    return notify('error', `Error en lote ${i + 1}: ${batchRes.data?.message}`);
                }

                if (batchRes.data.skipped) {
                    notify('error', `Lote ${i + 1} saltado: ${batchRes.data.error}`);
                }

                await getJobStatus();
            }

            loading.value = false;
            stopPolling();
            await getJobStatus();

            const finalState = jobStatus.value?.state;
            if (finalState) {
                notify('success',
                    `Importación completada: ${finalState.imported_count} importados, ${finalState.failed_count} fallidos.`
                );
            }

            // Limpiar selección
            selectedIds.value       = [];
            selectedPostsData.value = {};
        }

        /* ── Job status & polling ─────────────────────────────────────────── */

        const jobProgress = computed(() => {
            if (!jobStatus.value?.exists) return 0;
            const s = jobStatus.value.state;
            return s.total === 0 ? 0 : (s.processed / s.total) * 100;
        });

        async function getJobStatus() {
            const res = await ajax('bm_get_job_status');
            if (res.success) jobStatus.value = res.data;
        }

        function startPolling() {
            if (pollingInterval.value) return;
            pollingInterval.value = setInterval(getJobStatus, 2000);
        }

        function stopPolling() {
            if (pollingInterval.value) {
                clearInterval(pollingInterval.value);
                pollingInterval.value = null;
            }
        }

        async function cancelJob() {
            stopPolling();
            const res = await ajax('bm_cancel_job');
            if (res.success) {
                jobStatus.value = null;
                notify('success', 'Importación cancelada.');
            }
        }

        /** Cierra el panel de progreso (solo cuando el job ya no está running). */
        async function dismissProgress() {
            await ajax('bm_cancel_job'); // Limpia el estado de la BD
            jobStatus.value = null;
        }

        /* ── Init ─────────────────────────────────────────────────────────── */

        getJobStatus();

        /* ── Expose ───────────────────────────────────────────────────────── */
        return {
            // Estado
            domain, connected, languages, selectedLang,
            allPosts, paginatedPosts, filteredPosts,
            loading, searchQuery, perPage,
            currentPage, totalPages,
            postStatusMode, notification,
            // Selección
            selectedCount, isAllCurrentPageSelected, isSomeCurrentPageSelected,
            isSelected, togglePost, toggleAll,
            // Acciones
            checkConnection, loadLanguages, explorePosts, importSelected,
            // Helpers
            statusLabel, formatDate,
            // Batching
            jobStatus, jobProgress, cancelJob, dismissProgress,
        };
    }
}).mount('#app');
