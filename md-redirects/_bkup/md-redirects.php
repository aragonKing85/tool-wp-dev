<?php
/**
 * Plugin Name: MDP Redirects
 * Description: Gestor de redirecciones con verificación manual vía HTTP.
 * Version: 0.1.0
 * Author: Mindset Digital
 * Text Domain: mdp-redirects
 */

if (!defined('ABSPATH')) exit;

define('MDR_VERSION', '0.1.1');
define('MDR_PLUGIN_FILE', __FILE__);
define('MDR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MDR_TABLE', 'mdp_redirects');

require_once MDR_PLUGIN_DIR . 'includes/helpers.php';
require_once MDR_PLUGIN_DIR . 'includes/class-mdr-activator.php';
require_once MDR_PLUGIN_DIR . 'includes/class-mdr-deactivator.php';
require_once MDR_PLUGIN_DIR . 'includes/class-mdr-db.php';
require_once MDR_PLUGIN_DIR . 'includes/class-mdr-matcher.php';
require_once MDR_PLUGIN_DIR . 'includes/class-mdr-admin-page.php';
require_once MDR_PLUGIN_DIR . 'includes/class-mdr-checker.php';

register_activation_hook(__FILE__, ['MDR_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['MDR_Deactivator', 'deactivate']);

add_action('plugins_loaded', function () {
    // Carga admin
    if (is_admin()) {
        new MDR_Admin_Page();
        new MDR_Checker();
    }
    // Hook de redirección en frontend
    add_action('template_redirect', ['MDR_Matcher', 'maybe_redirect'], 0);
});
