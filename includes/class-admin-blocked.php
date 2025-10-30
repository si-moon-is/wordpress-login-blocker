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
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Wyszukaj adres IP..." style="width: 300px;">
                        <button type="submit" class="button button-primary">Szukaj</button>
                        <?php if (!empty($search)): ?>
                            <a href="<?php echo admin_url('admin.php?page=login-blocker-blocked'); ?>" class="button">Wyczyść</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Statystyki -->
            <div class="card" style="margin-bottom: 20px;">
                <h3>Statystyki</h3>
                <p>Znaleziono: <strong><?php echo $total_blocked; ?></strong> zablokowanych adresów IP</p>
                <?php if (!empty($search)): ?>
                    <p>Wyniki wyszukiwania dla: <code><?php echo esc_html($search); ?></code></p>
                <?php endif; ?>
            </div>
            
            <!-- Akcje masowe -->
            <div class="card" style="margin-bottom: 20px;">
                <h3>Akcje</h3>
                <div style="display: flex; gap: 10px;">
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=unblock_all'), 'login_blocker_action'); ?>" 
                       class="button" 
                       onclick="return confirm('Czy na pewno chcesz odblokować WSZYSTKIE adresy IP?')">
                       Odblokuj wszystkie
                    </a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=delete_all_blocked'), 'login_blocker_action'); ?>" 
                       class="button button-danger" 
                       onclick="return confirm('Czy na pewno chcesz usunąć WSZYSTKIE zablokowane rekordy?')">
                       Usuń wszystkie zablokowane
                    </a>
                </div>
            </div>
            
            <!-- Tabela zablokowanych IP -->
            <div class="card">
                <h2>Obecnie zablokowane adresy IP</h2>
                <?php if ($blocked_ips): ?>
                    <div style="overflow-x: auto; width: 100%;">
                        <table class="wp-list-table widefat fixed striped" style="width: 100%; min-width: 1000px;">
                            <thead>
                                <tr>
                                    <th style="width: 15%;">Adres IP</th>
                                    <th style="width: 20%;">Użytkownik</th>
                                    <th style="width: 10%;">Próby</th>
                                    <th style="width: 15%;">Ostatnia próba</th>
                                    <th style="width: 15%;">Zablokowany do</th>
                                    <th style="width: 10%;">Kraj</th>
                                    <th style="width: 15%;">Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocked_ips as $ip): ?>
                                    <tr>
                                        <td><?php echo esc_html($ip->ip_address); ?></td>
                                        <td><?php echo esc_html($ip->username); ?></td>
                                        <td><?php echo esc_html($ip->attempts); ?></td>
                                        <td><?php echo esc_html($ip->last_attempt); ?></td>
                                        <td><?php echo esc_html($ip->block_until); ?></td>
                                        <td>
                                            <?php if (!empty($ip->country_code) && $ip->country_code !== 'LOCAL'): ?>
                                                <?php 
                                                    $country_code_lower = strtolower($ip->country_code);
                                                    $flag_url = "https://flagcdn.com/24x18/{$country_code_lower}.png";
                                                ?>
                                                <img src="<?php echo esc_url($flag_url); ?>" alt="<?php echo esc_attr($ip->country_code); ?>" title="<?php echo esc_attr($ip->country_name); ?>" style="width: 24px; height: 18px;">
                                            <?php else: ?>
                                                <span style="color: #999;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=unblock&ip=' . $ip->ip_address), 'login_blocker_action'); ?>" class="button">Odblokuj</a>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=delete&ip=' . $ip->ip_address), 'login_blocker_action'); ?>" class="button button-danger" onclick="return confirm('Czy na pewno chcesz usunąć?')">Usuń</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginacja -->
                    <?php if ($total_pages > 1): ?>
                        <div class="tablenav">
                            <div class="tablenav-pages">
                                <?php
                                echo paginate_links(array(
                                    'base' => add_query_arg('paged', '%#%'),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total' => $total_pages,
                                    'current' => $current_page
                                ));
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <p><?php echo empty($search) ? 'Brak zablokowanych adresów IP.' : 'Brak wyników wyszukiwania.'; ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
