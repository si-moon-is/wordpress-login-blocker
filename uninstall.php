<?php
// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Usuwanie opcji wtyczki
$options = array(
    'login_blocker_max_attempts',
    'login_blocker_retention_days',
    'login_blocker_enable_email_notifications',
    'login_blocker_block_duration'
);

foreach ($options as $option) {
    delete_option($option);
}

// Usuwanie tabel bazy danych (opcjonalnie - zakomentuj jeśli chcesz zachować dane)
global $wpdb;
$tables = array(
    $wpdb->prefix . 'login_attempts',
    $wpdb->prefix . 'blocked_ips',
    $wpdb->prefix . 'login_blocker_logs'
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

// Czyszczenie scheduled hooks
wp_clear_scheduled_hook('login_blocker_cleanup_old_records');

// Dodatkowe czyszczenie, które można rozważyć:
function additional_cleanup() {
    global $wpdb;
    
    // Usuwanie transjentów
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lb_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lb_%'");
    
    // Usuwanie zaplanowanych zadań
    wp_clear_scheduled_hook('lb_daily_cleanup');
    
    // Usuwanie custom user meta jeśli istnieje
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'lb_%'");
}

// Wywołanie dodatkowego czyszczenia
additional_cleanup();
