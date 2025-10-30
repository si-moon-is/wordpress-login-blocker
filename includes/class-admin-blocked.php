<?php
/**
 * Login Blocker - Admin Blocked IPs Class
 * Handles blocked IPs functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Admin_Blocked {
    
    private $admin;
    
    public function __construct($admin) {
        $this->admin = $admin;
    }
    
    // Strona z zablokowanymi IP z paginacją i wyszukiwaniem
    public function blocked_ips_page() {
        global $wpdb;
        
        // Paginacja
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Wyszukiwanie
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $search_sql = '';
        if (!empty($search)) {
            $search_sql = $wpdb->prepare(" AND ip_address LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        }
        
        // Obsługa akcji
        if (isset($_GET['action']) && check_admin_referer('login_blocker_action')) {
            if ($_GET['action'] === 'unblock' && isset($_GET['ip'])) {
                $ip = sanitize_text_field($_GET['ip']);
                $wpdb->update(
                    $this->admin->get_table_name(),
                    array('is_blocked' => 0, 'attempts' => 0, 'block_until' => null),
                    array('ip_address' => $ip)
                );
                
                $this->admin->get_main_class()->log_info("IP odblokowane ręcznie", array(
                    'ip' => $ip,
                    'admin_user' => wp_get_current_user()->user_login
                ));
                
                echo '<div class="notice notice-success is-dismissible"><p>IP odblokowane.</p></div>';
            } elseif ($_GET['action'] === 'unblock_all') {
                $wpdb->update(
                    $this->admin->get_table_name(),
                    array('is_blocked' => 0, 'attempts' => 0, 'block_until' => null),
                    array('is_blocked' => 1)
                );
                echo '<div class="notice notice-success is-dismissible"><p>Wszystkie IP odblokowane.</p></div>';
            } elseif ($_GET['action'] === 'delete' && isset($_GET['ip'])) {
                $ip = sanitize_text_field($_GET['ip']);
                $wpdb->delete($this->admin->get_table_name(), array('ip_address' => $ip));
                echo '<div class="notice notice-success is-dismissible"><p>Rekord usunięty.</p></div>';
            } elseif ($_GET['action'] === 'delete_all_blocked') {
                $wpdb->delete($this->admin->get_table_name(), array('is_blocked' => 1));
                echo '<div class="notice notice-success is-dismissible"><p>Wszystkie zablokowane rekordy usunięte.</p></div>';
            }
        }
        
        // Pobieranie zablokowanych IP
        $blocked_ips = $wpdb->get_results(
            $wpdb->prepare("
                SELECT * FROM {$this->admin->get_table_name()} 
                WHERE is_blocked = 1 {$search_sql}
                ORDER BY last_attempt DESC 
                LIMIT %d, %d
            ", $offset, $per_page)
        );
        
        // Liczba wszystkich zablokowanych IP
        $total_blocked = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->admin->get_table_name()} WHERE is_blocked = 1 {$search_sql}"
        );
        
        $total_pages = ceil($total_blocked / $per_page);
        
        ?>
        <div class="wrap">
            <h1>Login Blocker - Zablokowane adresy IP</h1>
            
            <!-- Formularz wyszukiwania -->
            <div class="card" style="margin-bottom: 20px;">
                <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="page" value="login-blocker-blocked">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="
