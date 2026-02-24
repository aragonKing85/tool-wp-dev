<?php
if (!defined('ABSPATH')) exit;

class MDR_DB {
    public static function all($args = []) {
        global $wpdb;
        $table = mdr_table_name();
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($args['enabled'])) {
            $where .= ' AND enabled = %d';
            $params[] = (int) $args['enabled'];
        }
        $order = 'ORDER BY priority ASC, id ASC';
        $sql = "SELECT * FROM {$table} {$where} {$order}";
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function paged($page = 1, $per_page = 20) {
        global $wpdb;
        $table = mdr_table_name();
        $offset = max(0, ($page - 1) * $per_page);
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT SQL_CALC_FOUND_ROWS * FROM {$table} ORDER BY priority ASC, id DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        $total = (int) $wpdb->get_var("SELECT FOUND_ROWS()");
        return ['items' => $items, 'total' => $total];
    }

    public static function get($id) {
        global $wpdb;
        $table = mdr_table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
    }

public static function create($data) {
    global $wpdb;
    $table = mdr_table_name();
    $now = mdr_now();
    $wpdb->insert($table, [
        'source_path' => $data['source_path'],
        'target_url'  => $data['target_url'] ?? null,
        'status_code' => (int)($data['status_code'] ?? 301),
        'is_regex'    => !empty($data['is_regex']) ? 1 : 0,
        'enabled'     => !empty($data['enabled']) ? 1 : 0,
        'priority'    => (int)($data['priority'] ?? 0),
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
    mdr_invalidate_rules_cache();
    return (int) $wpdb->insert_id;
}

public static function update($id, $data) {
    global $wpdb;
    $table = mdr_table_name();
    $data['updated_at'] = mdr_now();
    $res = $wpdb->update($table, $data, ['id' => (int)$id]);
    mdr_invalidate_rules_cache();
    return $res;
}

public static function delete($id) {
    global $wpdb;
    $table = mdr_table_name();
    $res = $wpdb->delete($table, ['id' => (int)$id]);
    mdr_invalidate_rules_cache();
    return $res;
}


    public static function register_hit($id) {
        global $wpdb;
        $table = mdr_table_name();
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d",
            mdr_now(), (int)$id
        ));
    }

public static function paged_filtered($args) {
    global $wpdb;
    $table = mdr_table_name();

    $page     = max(1, (int)($args['page'] ?? 1));
    $per_page = max(1, (int)($args['per_page'] ?? 20));
    $offset   = ($page - 1) * $per_page;

    $where  = 'WHERE 1=1';
    $params = [];

    if (isset($args['enabled']) && $args['enabled'] !== null) {
        $where .= ' AND enabled = %d';
        $params[] = (int)$args['enabled'];
    }

    if (isset($args['code']) && $args['code'] !== null) {
        $where .= ' AND (status_code = %d OR final_code = %d)';
        $params[] = (int)$args['code'];
        $params[] = (int)$args['code'];
    }

    if (isset($args['regex']) && $args['regex'] !== null) {
        $where .= ' AND is_regex = %d';
        $params[] = (int)$args['regex'];
    }

    if (!empty($args['search'])) {
        $q = '%' . $wpdb->esc_like($args['search']) . '%';
        $where .= ' AND (source_path LIKE %s OR target_url LIKE %s)';
        $params[] = $q;
        $params[] = $q;
    }

    $allowed = ['id','status_code','hits','priority','last_hit'];
    $orderby = in_array($args['orderby'] ?? '', $allowed, true) ? $args['orderby'] : 'priority';
    $order   = (strtoupper($args['order'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

    $params[] = $per_page;
    $params[] = $offset;

    $sql = call_user_func_array(
        [$wpdb, 'prepare'],
        array_merge(
            ["SELECT SQL_CALC_FOUND_ROWS * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"],
            $params
        )
    );

    $items = $wpdb->get_results($sql);
    $total = (int) $wpdb->get_var("SELECT FOUND_ROWS()");

    return ['items' => $items, 'total' => $total];
}

public static function delete_all() {
    global $wpdb;
    $table = mdr_table_name();
    $wpdb->query("TRUNCATE TABLE {$table}");
    delete_transient(mdr_rules_cache_key()); // limpiar cache
}


}
