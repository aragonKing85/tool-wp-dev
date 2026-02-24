<div class="wrap" id="app">
    <h1>🛠️ Blog Migrator</h1>
    <p>Introduce el dominio del sitio WordPress desde el que quieres importar el blog.</p>

    <div style="margin-bottom: 1em;">
        <input v-model="domain" type="text" placeholder="https://ejemplo.com" style="width: 400px;">
        <button class="button" @click="checkConnection">Comprobar conexión</button>
        <button class="button" @click="loadLanguages" :disabled="!connected">Detectar idiomas</button>
        <select v-if="languages.length" v-model="selectedLang" style="margin-left: 10px;">
            <option value="">Todos los idiomas</option>
            <option v-for="lang in languages" :value="lang.slug">{{ lang.name }}</option>
        </select>
        <button class="button" @click="explorePosts" :disabled="!connected">Explorar posts</button>
        <button class="button button-primary" @click="importSelected" :disabled="!connected || selectedPosts.length === 0">
            Importar seleccionados
        </button>
    </div>

    <div v-if="message" :style="{color: messageColor}">
        <p>{{ message }}</p>
    </div>

    <div v-if="loading">Cargando...</div>

    <!-- 🎛️ Selector Global de Estado -->
    <div v-if="posts.length > 0" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
        <label for="bm-post-status-mode" style="font-weight: bold; margin-right: 10px; display: inline-block; min-width: 200px;">
            📝 Estado de los posts al migrar:
        </label>
        <select id="bm-post-status-mode" v-model="postStatusMode" style="padding: 5px 10px; min-width: 250px;">
            <option value="original">Por defecto (respeta el estado original)</option>
            <option value="draft">Todos borrador</option>
            <option value="publish">Todos publicados</option>
        </select>
        <p style="margin: 10px 0 0 0; color: #666; font-size: 0.9em;">
            <em>Este modo se aplicará a TODOS los posts durante la migración.</em>
        </p>
    </div>

    <!-- 📊 Barra de Progreso (BATCHING) -->
    <div v-if="jobStatus && jobStatus.exists" class="bm-progress-container" style="margin: 20px 0; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
        <h3 style="margin-top: 0;">📊 Progreso de Importación</h3>
        
        <!-- Barra visual -->
        <div style="background: #fff; border: 1px solid #c3c4c7; height: 30px; border-radius: 3px; overflow: hidden; margin-bottom: 10px;">
            <div :style="{
                width: jobProgress + '%',
                height: '100%',
                background: jobStatus.state.status === 'completed' ? '#00a32a' : '#2271b1',
                transition: 'width 0.3s ease'
            }"></div>
        </div>

        <!-- Contadores -->
        <div style="display: flex; gap: 20px; margin-bottom: 10px;">
            <div><strong>Progreso:</strong> {{ jobProgress.toFixed(1) }}%</div>
            <div><strong>Posts:</strong> {{ jobStatus.state.imported_count }} / {{ jobStatus.state.total }}</div>
            <div><strong>Lote:</strong> {{ jobStatus.state.current_batch }} / {{ jobStatus.state.total_batches }}</div>
            <div v-if="jobStatus.state.failed_count > 0" style="color: #d63638;">
                <strong> Fallidos:</strong> {{ jobStatus.state.failed_count }}
            </div>
        </div>

        <!-- Estado actual -->
        <div><strong>Estado:</strong> 
            <span v-if="jobStatus.state.status === 'running'">⚙️ Procesando lote {{ jobStatus.state.current_batch }}...</span>
            <span v-else-if="jobStatus.state.status === 'completed'">✅ Completado</span>
            <span v-else>{{ jobStatus.state.status }}</span>
        </div>

        <!-- Modo de estado -->
        <div v-if="jobStatus.state.post_status_mode" style="margin-top: 5px;">
            <strong>Modo de estado:</strong> 
            <span v-if="jobStatus.state.post_status_mode === 'original'">📄 Respeta estado original</span>
            <span v-else-if="jobStatus.state.post_status_mode === 'draft'">📝 Todos borrador</span>
            <span v-else-if="jobStatus.state.post_status_mode === 'publish'">🌐 Todos publicados</span>
        </div>

        <!-- Errores -->
        <div v-if="jobStatus.state.errors && jobStatus.state.errors.length > 0" style="margin-top: 10px;">
            <details>
                <summary style="cursor: pointer; color: #d63638;"><strong>❌ {{ jobStatus.state.errors.length }} lote(s) fallido(s)</strong></summary>
                <ul style="margin: 10px 0;">
                    <li v-for="err in jobStatus.state.errors" :key="err.batch" style="margin: 5px 0;">
                        <strong>Lote {{ err.batch }}:</strong> {{ err.message }} ({{ err.attempts }} intentos)
                        <br>
                        <small>Posts: {{ err.posts.map(p => p.title).join(', ').substring(0, 100) }}...</small>
                    </li>
                </ul>
            </details>
        </div>

        <!-- Botón Cancelar -->
        <button v-if="jobStatus.state.status === 'running'" class="button" @click="cancelJob" style="margin-top: 10px;">Cancelar Importación</button>
    </div>

    <!-- tabla igual que antes -->
    <table v-if="posts.length > 0" class="widefat striped" style="margin-top: 20px;">
        <thead>
            <tr>
                <th><input type="checkbox" v-model="selectAll" @change="toggleAll"></th>
                <th>Título</th>
                <th>Fecha</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="p in posts" :key="p.id">
                <td><input type="checkbox" v-model="p.selected"></td>
                <td><a :href="p.link" target="_blank">{{ p.title }}</a></td>
                <td>{{ new Date(p.date).toLocaleDateString() }}</td>
                <td>
                    <select v-model="p.status">
                        <option value="draft">Borrador</option>
                        <option value="publish">Publicado</option>
                    </select>
                </td>
            </tr>
        </tbody>
    </table>
</div>
