<?php

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

// Activation/deactivation hooks registrados en tool-wp-dev.php (plugin principal).

add_action('plugins_loaded', function () {
    // Carga admin
    if (is_admin()) {
        new MDR_Admin_Page();
        new MDR_Checker();
    }
    // Hook de redirección en frontend
    add_action('template_redirect', ['MDR_Matcher', 'maybe_redirect'], 0);
});
