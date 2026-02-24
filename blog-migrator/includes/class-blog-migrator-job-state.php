<?php
if ( !defined('ABSPATH') ) exit;

/**
 * Gestión de estado persistente para jobs de migración
 * 
 * Permite:
 * - Iniciar migración con parámetros
 * - Procesar por lotes (batching)
 * - Guardar progreso en wp_options
 * - Reanudar tras interrupción
 * - Tracking de errores y posts importados
 */
class Blog_Migrator_Job_State {

    private $job_id;
    private $option_key;

    /**
     * Constructor
     * 
     * @param string $job_id Identificador del job (default: 'default')
     */
    public function __construct($job_id = 'default') {
        $this->job_id = sanitize_key($job_id);
        $this->option_key = 'bm_job_' . $this->job_id;
    }

    /**
     * Inicializar nuevo job
     * 
     * @param array $posts Posts a importar [['id' => X, 'status' => 'draft', ...], ...]
     * @param array $params Parámetros adicionales ['domain' => '...', 'batch_size' => 25, ...]
     */
    public function init($posts, $params = []) {
        $batch_size = isset($params['batch_size']) ? intval($params['batch_size']) : 25;
        
        // Validar tamaño de lote (min 25, max 30)
        if ($batch_size < 25) $batch_size = 25;
        if ($batch_size > 30) $batch_size = 30;

        $total = count($posts);
        $total_batches = ceil($total / $batch_size);

        $state = [
            'status' => 'idle',
            'total' => $total,
            'processed' => 0,
            'batch_size' => $batch_size,
            'current_batch' => 0,
            'total_batches' => $total_batches,
            'imported_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'errors' => [],
            'post_mapping' => [], // origin_id => dest_id
            'selected_posts' => $posts,
            'domain' => $params['domain'] ?? '',
            'post_status_mode' => $params['post_status_mode'] ?? 'original', // Modo de estado
            'started_at' => current_time('timestamp'),
            'updated_at' => current_time('timestamp'),
        ];

        update_option($this->option_key, $state, false); // No autoload
        
        $this->log_info("Job initialized: {$total} posts, {$total_batches} batches of {$batch_size}");
        
        return $state;
    }

    /**
     * Obtener estado actual del job
     * 
     * @return array|false Estado o false si no existe
     */
    public function get() {
        $state = get_option($this->option_key, false);
        
        if (!$state) {
            return false;
        }

        return $state;
    }

    /**
     * Actualizar campos del estado
     * 
     * @param array $data Campos a actualizar ['processed' => 10, 'status' => 'running', ...]
     */
    public function update($data) {
        $state = $this->get();
        
        if (!$state) {
            $this->log_error("Cannot update: job does not exist");
            return false;
        }

        $state = array_merge($state, $data);
        $state['updated_at'] = current_time('timestamp');

        update_option($this->option_key, $state, false);
        
        return $state;
    }

    /**
     * Añadir error de lote al log
     * 
     * @param int $batch_index Índice del lote fallido
     * @param array $error_data ['posts' => [...], 'message' => '...', 'attempts' => 3]
     */
    public function add_error($batch_index, $error_data) {
        $state = $this->get();
        
        if (!$state) return false;

        $error = [
            'batch' => $batch_index,
            'timestamp' => current_time('timestamp'),
            'posts' => $error_data['posts'] ?? [],
            'message' => $error_data['message'] ?? 'Unknown error',
            'attempts' => $error_data['attempts'] ?? 1,
        ];

        $state['errors'][] = $error;
        
        update_option($this->option_key, $state, false);
        
        $this->log_error("Batch {$batch_index} failed after {$error['attempts']} attempts: {$error['message']}");
        
        return true;
    }

    /**
     * Guardar mapeo de post importado
     * 
     * @param int $origin_id ID del post en sitio origen
     * @param int $dest_id ID del post en sitio destino
     */
    public function add_imported_post($origin_id, $dest_id) {
        $state = $this->get();
        
        if (!$state) return false;

        $state['post_mapping'][$origin_id] = $dest_id;
        
        update_option($this->option_key, $state, false);
        
        return true;
    }

    /**
     * Obtener ID de post destino por ID origen (si existe)
     * 
     * @param int $origin_id ID del post en origen
     * @return int|false ID en destino o false
     */
    public function get_imported_post_id($origin_id) {
        $state = $this->get();
        
        if (!$state || !isset($state['post_mapping'][$origin_id])) {
            return false;
        }

        return $state['post_mapping'][$origin_id];
    }

    /**
     * Verificar si el job está completo
     * 
     * @return bool
     */
    public function is_complete() {
        $state = $this->get();
        
        if (!$state) return false;

        return $state['status'] === 'completed' || $state['current_batch'] >= $state['total_batches'];
    }

    /**
     * Marcar job como completado
     */
    public function mark_complete() {
        $state = $this->get();
        
        if (!$state) return false;

        $state['status'] = 'completed';
        $state['updated_at'] = current_time('timestamp');
        
        update_option($this->option_key, $state, false);
        
        $this->log_info("Job completed: {$state['imported_count']} imported, {$state['failed_count']} failed");
        
        return $state;
    }

    /**
     * Resetear/Cancelar job
     */
    public function reset() {
        delete_option($this->option_key);
        $this->log_info("Job reset");
    }

    /**
     * Obtener posts del lote actual
     * 
     * @param int $batch_index Índice del lote (0-based)
     * @return array Posts del lote
     */
    public function get_batch_posts($batch_index) {
        $state = $this->get();
        
        if (!$state) return [];

        $offset = $batch_index * $state['batch_size'];
        $posts = array_slice($state['selected_posts'], $offset, $state['batch_size']);
        
        return $posts;
    }

    /**
     * Incrementar contador de procesados
     * 
     * @param int $count Cantidad a incrementar
     */
    public function increment_processed($count) {
        $state = $this->get();
        
        if (!$state) return false;

        $state['processed'] += $count;
        $state['updated_at'] = current_time('timestamp');
        
        update_option($this->option_key, $state, false);
        
        return $state;
    }

    /**
     * Incrementar contador de importados
     * 
     * @param int $count Cantidad a incrementar
     */
    public function increment_imported($count) {
        $state = $this->get();
        
        if (!$state) return false;

        $state['imported_count'] += $count;
        $state['updated_at'] = current_time('timestamp');
        
        update_option($this->option_key, $state, false);
        
        return $state;
    }

    /**
     * Incrementar contador de fallidos
     * 
     * @param int $count Cantidad a incrementar
     */
    public function increment_failed($count) {
        $state = $this->get();
        
        if (!$state) return false;

        $state['failed_count'] += $count;
        $state['updated_at'] = current_time('timestamp');
        
        update_option($this->option_key, $state, false);
        
        return $state;
    }

    /**
     * Logging de errores
     */
    private function log_error($message) {
        error_log("[Blog Migrator - Job State ERROR] " . $message);
    }

    /**
     * Logging de info
     */
    private function log_info($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Blog Migrator - Job State INFO] " . $message);
        }
    }
}
