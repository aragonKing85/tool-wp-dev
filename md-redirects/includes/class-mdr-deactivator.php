<?php
if (!defined('ABSPATH')) exit;

class MDR_Deactivator {
    public static function deactivate() {
        // No borramos datos al desactivar; se borra en uninstall.php
    }
}
