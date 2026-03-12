<div class="twd-bm-wrap" id="app">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="twd-bm-header">
        <div class="twd-bm-header__title">
            <span class="twd-bm-icon">&#128228;</span>
            <div>
                <h1>Blog Migrator</h1>
                <p>Importa posts desde cualquier WordPress con API REST accesible.</p>
            </div>
        </div>
        <span v-if="connected" class="twd-bm-conn-badge twd-bm-conn-badge--ok">&#10003; Conectado</span>
        <span v-else class="twd-bm-conn-badge twd-bm-conn-badge--off">&#9711; Sin conexión</span>
    </div>

    <!-- ── Notificaciones ───────────────────────────────────────────────────── -->
    <transition name="twd-bm-fade">
        <div v-if="notification" :class="['twd-bm-notice', 'twd-bm-notice--' + notification.type]">
            <span v-if="notification.type === 'loading'" class="twd-bm-spinner"></span>
            <span v-else class="twd-bm-notice__icon">{{ notification.type === 'success' ? '✓' : '✕' }}</span>
            {{ notification.message }}
        </div>
    </transition>

    <!-- ── Panel de configuración ────────────────────────────────────────────── -->
    <div class="twd-bm-config">

        <!-- Fila 1: Conexión + Explorar -->
        <div class="twd-bm-card">
            <div class="twd-bm-card__header"><h2>Origen</h2></div>
            <div class="twd-bm-card__body twd-bm-card__body--row">
                <input
                    v-model="domain"
                    type="url"
                    class="twd-bm-input"
                    placeholder="https://blog-origen.com"
                    @keydown.enter="checkConnection"
                >
                <button class="twd-bm-btn twd-bm-btn--secondary" @click="checkConnection" :disabled="!domain || loading">
                    Comprobar conexión
                </button>
                <button class="twd-bm-btn twd-bm-btn--ghost" @click="loadLanguages" :disabled="!connected || loading">
                    Detectar idiomas
                </button>
                <button class="twd-bm-btn twd-bm-btn--primary" @click="explorePosts" :disabled="!connected || loading">
                    <span v-if="loading" class="twd-bm-spinner twd-bm-spinner--sm"></span>
                    Explorar posts
                </button>
                <select v-if="languages.length" v-model="selectedLang" class="twd-bm-select">
                    <option value="">Todos los idiomas</option>
                    <option v-for="lang in languages" :key="lang.slug" :value="lang.slug">{{ lang.name }}</option>
                </select>
            </div>
        </div>

        <!-- Fila 2: Estado de importación + Posts por página -->
        <div class="twd-bm-config-row">
            <div class="twd-bm-card twd-bm-card--compact">
                <div class="twd-bm-card__header"><h2>Estado al importar</h2></div>
                <div class="twd-bm-card__body">
                    <select v-model="postStatusMode" class="twd-bm-select twd-bm-select--full">
                        <option value="original">Respetar estado original</option>
                        <option value="publish">Todos publicados</option>
                        <option value="draft">Todos borradores</option>
                        <option value="pending">Todos pendientes</option>
                        <option value="private">Todos privados</option>
                    </select>
                </div>
            </div>
            <div class="twd-bm-card twd-bm-card--compact">
                <div class="twd-bm-card__header"><h2>Posts por página</h2></div>
                <div class="twd-bm-card__body">
                    <select v-model="perPage" class="twd-bm-select twd-bm-select--full">
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                        <option :value="200">200</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Progreso de importación (batching) ────────────────────────────────── -->
    <div v-if="jobStatus && jobStatus.exists" class="twd-bm-card twd-bm-progress-card">
        <div class="twd-bm-card__header twd-bm-card__header--progress">
            <h2>Progreso de importación</h2>
            <div class="twd-bm-progress-actions">
                <button v-if="jobStatus.state.status === 'running'"
                    class="twd-bm-btn twd-bm-btn--danger-sm"
                    @click="cancelJob"
                >Cancelar</button>
                <button v-else
                    class="twd-bm-btn-close"
                    @click="dismissProgress"
                    title="Cerrar"
                >&times;</button>
            </div>
        </div>
        <div class="twd-bm-card__body">
            <div class="twd-bm-progress-track">
                <div class="twd-bm-progress-bar"
                    :class="{ 'twd-bm-progress-bar--done': jobStatus.state.status === 'completed' }"
                    :style="{ width: jobProgress.toFixed(1) + '%' }"
                ></div>
            </div>
            <div class="twd-bm-progress-stats">
                <div class="twd-bm-stat">
                    <span class="twd-bm-stat__val">{{ jobProgress.toFixed(1) }}%</span>
                    <span class="twd-bm-stat__lbl">Progreso</span>
                </div>
                <div class="twd-bm-stat">
                    <span class="twd-bm-stat__val">{{ jobStatus.state.imported_count }} / {{ jobStatus.state.total }}</span>
                    <span class="twd-bm-stat__lbl">Posts</span>
                </div>
                <div class="twd-bm-stat">
                    <span class="twd-bm-stat__val">{{ jobStatus.state.current_batch }} / {{ jobStatus.state.total_batches }}</span>
                    <span class="twd-bm-stat__lbl">Lote</span>
                </div>
                <div v-if="jobStatus.state.failed_count > 0" class="twd-bm-stat twd-bm-stat--err">
                    <span class="twd-bm-stat__val">{{ jobStatus.state.failed_count }}</span>
                    <span class="twd-bm-stat__lbl">Fallidos</span>
                </div>
                <div class="twd-bm-stat">
                    <span class="twd-bm-stat__val twd-bm-stat__val--status">
                        <template v-if="jobStatus.state.status === 'running'">&#9881; Procesando…</template>
                        <template v-else-if="jobStatus.state.status === 'completed'">&#10003; Completado</template>
                        <template v-else>{{ jobStatus.state.status }}</template>
                    </span>
                    <span class="twd-bm-stat__lbl">Estado</span>
                </div>
            </div>
            <details v-if="jobStatus.state.errors && jobStatus.state.errors.length > 0" class="twd-bm-errors">
                <summary>&#10005; {{ jobStatus.state.errors.length }} lote(s) fallido(s)</summary>
                <ul>
                    <li v-for="err in jobStatus.state.errors" :key="err.batch">
                        <strong>Lote {{ err.batch }}:</strong> {{ err.message }} ({{ err.attempts }} intentos)
                    </li>
                </ul>
            </details>
        </div>
    </div>

    <!-- ── Tabla de posts ────────────────────────────────────────────────────── -->
    <div v-if="allPosts.length > 0" class="twd-bm-table-wrap">

        <!-- Barra de acción -->
        <div class="twd-bm-action-bar">
            <div class="twd-bm-action-bar__left">
                <span class="twd-bm-selection-count">
                    <template v-if="selectedCount === 0">Ningún post seleccionado</template>
                    <template v-else>
                        <strong>{{ selectedCount }}</strong> {{ selectedCount === 1 ? 'post seleccionado' : 'posts seleccionados' }}
                    </template>
                </span>
                <span class="twd-bm-total-count">
                    {{ filteredPosts.length !== allPosts.length
                        ? filteredPosts.length + ' de ' + allPosts.length + ' posts'
                        : allPosts.length + ' posts totales' }}
                </span>
            </div>
            <div class="twd-bm-action-bar__right">
                <!-- Buscador -->
                <div class="twd-bm-search-wrap">
                    <span class="twd-bm-search-icon">&#128269;</span>
                    <input
                        v-model="searchQuery"
                        type="search"
                        class="twd-bm-search"
                        placeholder="Filtrar por título…"
                    >
                    <button v-if="searchQuery" class="twd-bm-search-clear" @click="searchQuery = ''" title="Limpiar búsqueda">&#10005;</button>
                </div>
                <!-- Importar -->
                <button
                    class="twd-bm-btn twd-bm-btn--import"
                    @click="importSelected"
                    :disabled="selectedCount === 0 || loading"
                >
                    &#8659; Importar seleccionados
                </button>
            </div>
        </div>

        <!-- Tabla -->
        <div class="twd-bm-table-container">
            <table class="twd-bm-table">
                <thead>
                    <tr>
                        <th class="twd-bm-th--check">
                            <input type="checkbox"
                                :checked="isAllCurrentPageSelected"
                                :indeterminate.prop="isSomeCurrentPageSelected"
                                @change="toggleAll"
                                class="twd-bm-checkbox"
                            >
                        </th>
                        <th>Título</th>
                        <th class="twd-bm-th--date">Fecha original</th>
                        <th class="twd-bm-th--status">Estado original</th>
                        <th class="twd-bm-th--cat">Categoría</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="filteredPosts.length === 0">
                        <td colspan="5" class="twd-bm-empty-row">
                            No se encontraron posts que coincidan con "<strong>{{ searchQuery }}</strong>".
                        </td>
                    </tr>
                    <tr v-for="p in paginatedPosts" :key="p.id"
                        :class="{ 'twd-bm-row--selected': isSelected(p.id) }"
                    >
                        <td class="twd-bm-td--check">
                            <input type="checkbox"
                                :checked="isSelected(p.id)"
                                @change="togglePost(p)"
                                class="twd-bm-checkbox"
                            >
                        </td>
                        <td>
                            <a :href="p.link" target="_blank" rel="noopener" class="twd-bm-post-link">{{ p.title }}</a>
                        </td>
                        <td class="twd-bm-td--muted">{{ formatDate(p.date) }}</td>
                        <td>
                            <span :class="['twd-bm-badge', 'twd-bm-badge--' + p.status]">{{ statusLabel(p.status) }}</span>
                        </td>
                        <td class="twd-bm-td--muted twd-bm-td--cats">
                            {{ p.categories && p.categories.length ? p.categories.join(', ') : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <div v-if="totalPages > 1" class="twd-bm-pagination">
            <button class="twd-bm-btn twd-bm-btn--ghost"
                @click="currentPage--"
                :disabled="currentPage <= 1"
            >&#8592; Anterior</button>

            <span class="twd-bm-pagination__info">
                Página <strong>{{ currentPage }}</strong> de <strong>{{ totalPages }}</strong>
            </span>

            <button class="twd-bm-btn twd-bm-btn--ghost"
                @click="currentPage++"
                :disabled="currentPage >= totalPages"
            >Siguiente &#8594;</button>
        </div>
    </div>


</div><!-- /#app -->
