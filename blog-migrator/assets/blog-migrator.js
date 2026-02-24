const { createApp, ref, computed, watch } = Vue;

createApp({
    setup() {
        const domain = ref('');
        const connected = ref(false);
        const languages = ref([]);
        const selectedLang = ref('');
        const posts = ref([]);
        const message = ref('');
        const messageColor = ref('#333');
        const loading = ref(false);
        const selectAll = ref(false);

        // 🎛️ Selector global de estado
        const postStatusMode = ref('original');

        // 📊 BATCHING: Estado del job
        const jobStatus = ref(null);
        const pollingInterval = ref(null);

        function log(msg, type = 'info') {
            message.value = msg;
            messageColor.value = type === 'error' ? 'red' : type === 'success' ? 'green' : '#333';
        }

        async function post(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', bm_ajax.nonce);
            formData.append('domain', domain.value);
            for (const key in data) formData.append(key, data[key]);

            const res = await fetch(bm_ajax.ajax_url, {
                method: 'POST',
                body: formData
            });
            return await res.json();
        }

        async function checkConnection() {
            if (!domain.value) return log('Introduce un dominio válido.', 'error');
            log('Comprobando conexión...');
            loading.value = true;
            const res = await post('bm_check_connection');
            loading.value = false;

            if (res.success) {
                connected.value = true;
                log(res.data.message, 'success');
            } else {
                connected.value = false;
                log(res.data.message, 'error');
            }
        }

        async function loadLanguages() {
            if (!connected.value) return;
            log('Buscando idiomas disponibles...');
            loading.value = true;
            const res = await post('bm_get_languages');
            loading.value = false;

            if (res.success && res.data.languages.length > 0) {
                languages.value = res.data.languages;
                log(`Detectados ${languages.value.length} idiomas (${res.data.source}).`, 'success');
            } else {
                log('No se detectaron idiomas (sitio monolingüe o API no disponible).', 'info');
                languages.value = [];
            }
        }

        async function explorePosts() {
            posts.value = [];
            loading.value = true;
            log('Explorando posts...');
            const res = await post('bm_explore_posts', { lang: selectedLang.value });
            loading.value = false;

            if (!res.success) return log(res.data.message, 'error');
            posts.value = res.data.posts.map(p => ({
                ...p,
                selected: false,
                status: 'draft'
            }));
            selectAll.value = false;
            log(`Se encontraron ${res.data.count} posts.`, 'success');
        }

        function toggleAll() {
            posts.value.forEach(p => (p.selected = selectAll.value));
        }

        watch(posts, () => {
            selectAll.value = posts.value.length > 0 && posts.value.every(p => p.selected);
        }, { deep: true });

        const selectedPosts = computed(() => posts.value.filter(p => p.selected));

        // 📊 BATCHING: Computed de progreso
        const jobProgress = computed(() => {
            if (!jobStatus.value || !jobStatus.value.exists) return 0;
            const state = jobStatus.value.state;
            if (state.total === 0) return 0;
            return (state.processed / state.total) * 100;
        });

        // 📊 BATCHING: Obtener estado del job
        async function getJobStatus() {
            const res = await post('bm_get_job_status');
            if (res.success) {
                jobStatus.value = res.data;
            }
        }

        // 📊 BATCHING: Iniciar polling
        function startPolling() {
            if (pollingInterval.value) return;
            pollingInterval.value = setInterval(async () => {
                await getJobStatus();
            }, 1500); // Poll cada 1.5 segundos
        }

        // 📊 BATCHING: Detener polling
        function stopPolling() {
            if (pollingInterval.value) {
                clearInterval(pollingInterval.value);
                pollingInterval.value = null;
            }
        }

        // 📊 BATCHING: Procesar lote
        async function processBatch(batchIndex) {
            const res = await post('bm_process_batch', { batch_index: batchIndex });
            return res;
        }

        // 📊 BATCHING: Importación con batching
        async function importSelected() {
            if (selectedPosts.value.length === 0) return log('Selecciona al menos un post.', 'error');

            log(`Iniciando importación de ${selectedPosts.value.length} posts en lotes de 25...`, 'info');
            loading.value = true;

            // 1. Iniciar job
            const initRes = await post('bm_start_import', {
                selected: JSON.stringify(selectedPosts.value.map(p => ({
                    id: p.id,
                    status: p.status,
                    title: p.title // Para logs
                }))),
                batch_size: 25,
                post_status_mode: postStatusMode.value // Modo global
            });

            if (!initRes.success) {
                loading.value = false;
                return log(initRes.data.message, 'error');
            }

            log('Job iniciado. Procesando lotes...', 'info');

            // 2. Iniciar polling para actualizar UI
            startPolling();
            await getJobStatus(); // Primera carga

            // 3. Procesar lotes uno a uno
            const totalBatches = initRes.data.job_state.total_batches;

            for (let i = 0; i < totalBatches; i++) {
                // Actualizar estado antes de procesar
                await getJobStatus();

                log(`Procesando lote ${i + 1} de ${totalBatches}...`, 'info');

                const batchRes = await processBatch(i);

                if (!batchRes.success) {
                    loading.value = false;
                    stopPolling();
                    return log(`Error procesando lote ${i}: ${batchRes.data.message}`, 'error');
                }

                // Verificar si se saltó el lote
                if (batchRes.data.skipped) {
                    log(`⚠️ Lote ${i} saltado tras fallos: ${batchRes.data.error}`, 'error');
                }
            }

            // 4. Finalizar
            loading.value = false;
            stopPolling();
            await getJobStatus(); // Última actualización

            const finalState = jobStatus.value.state;
            log(`✅ Importación completada: ${finalState.imported_count} importados, ${finalState.failed_count} fallidos.`, 'success');
        }

        // 📊 BATCHING: Cancelar job
        async function cancelJob() {
            if (!confirm('¿Cancelar la importación en curso?')) return;

            stopPolling();
            const res = await post('bm_cancel_job');

            if (res.success) {
                jobStatus.value = null;
                log('Importación cancelada.', 'info');
            }
        }

        // Verificar si hay job en curso al cargar
        getJobStatus();

        return {
            domain,
            connected,
            languages,
            selectedLang,
            posts,
            message,
            messageColor,
            loading,
            selectAll,
            checkConnection,
            loadLanguages,
            explorePosts,
            toggleAll,
            selectedPosts,
            importSelected,
            // Batching
            jobStatus,
            jobProgress,
            cancelJob,
            // Selector global de estado
            postStatusMode
        };
    }
}).mount('#app');
