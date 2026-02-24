<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
$table = $wpdb->prefix . 'mdp_redirects';
$wpdb->query("DROP TABLE IF EXISTS {$table}");
delete_option('mdr_version');
