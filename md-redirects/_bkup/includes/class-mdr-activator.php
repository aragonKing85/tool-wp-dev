<?php
if (!defined('ABSPATH')) exit;

class MDR_Activator {
    public static function activate() {
        global $wpdb;

        $table = mdr_table_name(); // viene del helper
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_path TEXT NOT NULL,
            target_url TEXT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            is_regex TINYINT(1) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_hit DATETIME NULL,
            priority INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            INDEX enabled_idx (enabled),
            INDEX prio_idx (priority),
            INDEX status_idx (status_code)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        add_option('mdr_version', MDR_VERSION);
    }
}
