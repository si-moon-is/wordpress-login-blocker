<?php
/**
 * Login Blocker - Admin Class
 * Handles all admin-related functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Admin {
    
    private $main_class;
    private $table_name;
    private $database;
    private $analytics;
    private $debug;
    private $settings;
    private $export;
    private $blocked;
    
    public function __construct($main_class) {
        global $wpdb;
        $this->main_class = $main_class;
        $this->database = $main_class->get_database();
        $this->table_name = $wpdb->prefix . 'login_blocker_attempts'; 
        
        // Inicjalizacja podklas
        require_once LOGIN_BLOCKER_PLUGIN_PATH . 'includes/class-admin-analytics.php';
        require_once LOGIN_BLOCKER_PLUGIN_PATH . 'includes/class-admin-debug.php';
        require_once LOGIN_BLOCKER_PLUGIN_PATH . 'includes/class-admin-settings.php';
        require_once LOGIN_BLOCKER_PLUGIN_PATH . 'includes/class-admin-export.php';
        require_once LOGIN_BLOCKER_PLUGIN_PATH . 'includes/class-admin-blocked.php';
        
        $this->analytics = new LoginBlocker_Admin_Analytics($this);
        $this->debug = new LoginBlocker_Admin_Debug($this);
        $this->settings = new LoginBlocker_Admin_Settings($this);
        $this->export = new LoginBlocker_Admin_Export($this);
        $this->blocked = new LoginBlocker_Admin_Blocked($this);
        
        // Rejestracja hooków admina
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_login_blocker_get_system_info', array($this->debug, 'ajax_get_system_info'));
        
        // Ajax dla odblokowywania IP
        add_action('wp_ajax_unblock_ip', array($this, 'ajax_unblock_ip'));
        // Ajax dla testowania email
        add_action('wp_ajax_test_email_config', array($this, 'test_email_config'));
    }
    
    // Gettery dla podklas
    public function get_main_class() {
        return $this->main_class;
    }
    
    public function get_table_name() {
        return $this->table_name;
    }
    
    public function get_database() {
        return $this->database;
    }
    
    // Dodawanie menu admina
    public function add_admin_menu() {
        add_menu_page(
            'Login Blocker',
            'Login Blocker',
            'manage_options',
            'login-blocker',
            array($this, 'admin_page'),
            'dashicons-shield',
            80
        );
        
        add_submenu_page(
            'login-blocker',
            'Zablokowane IP',
            'Zablokowane IP',
            'manage_options',
            'login-blocker-blocked',
            array($this->blocked, 'blocked_ips_page')
        );
        
        add_submenu_page(
            'login-blocker',
            'Statystyki & Próby',
            'Statystyki & Próby',
            'manage_options',
            'login-blocker-analytics',
            array($this->analytics, 'analytics_page')
        );
        
        add_submenu_page(
            'login-blocker',
            'Debug & Logi',
            'Debug & Logi',
            'manage_options',
            'login-blocker-debug',
            array($this->debug, 'debug_page')
        );

        add_submenu_page(
            'login-blocker',
            __('Eksport Danych', 'login-blocker'),
            __('Eksport Danych', 'login-blocker'),
            'export',
            'login-blocker-export',
            array($this->export, 'export_page')
        );
        
        add_submenu_page(
            'login-blocker',
            'Ustawienia',
            'Ustawienia',
            'manage_options',
            'login-blocker-settings',
            array($this->settings, 'settings_page')
        );
    }
    
    // Dodawanie menu do górnego paska admina
    public function add_admin_bar_menu($admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        
        // Pobieranie statystyk
        $blocked_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1");
        $recent_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE last_attempt > %s",
            date('Y-m-d H:i:s', time() - 3600)
        ));
        $total_attempts = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        // Ikona i licznik
        $title = 'Login Blocker';
        if ($blocked_count > 0) {
            $title .= ' <span class="awaiting-mod" style="background: #d63638; margin-left: 5px;">' . $blocked_count . '</span>';
        }
        
        // Główny element menu
        $admin_bar->add_menu(array(
            'id'    => 'login-blocker',
            'title' => $title,
            'href'  => admin_url('admin.php?page=login-blocker'),
            'meta'  => array(
                'title' => 'Statystyki: ' . $blocked_count . ' zablokowanych IP, ' . $recent_attempts . ' prób w ostatniej godzinie'
            )
        ));
        
        // Statystyki
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-stats',
            'parent' => 'login-blocker',
            'title'  => 'Statystyki:',
            'href'   => false,
            'meta'   => array(
                'title' => 'Aktualne statystyki',
                'class' => 'login-blocker-stats'
            )
        ));
        
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-stats-blocked',
            'parent' => 'login-blocker',
            'title'  => '• Zablokowane IP: ' . $blocked_count,
            'href'   => admin_url('admin.php?page=login-blocker'),
            'meta'   => array()
        ));
        
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-stats-recent',
            'parent' => 'login-blocker',
            'title'  => '• Próby (1h): ' . $recent_attempts,
            'href'   => admin_url('admin.php?page=login-blocker#recent'),
            'meta'   => array()
        ));
        
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-stats-total',
            'parent' => 'login-blocker',
            'title'  => '• Wszystkie próby: ' . $total_attempts,
            'href'   => admin_url('admin.php?page=login-blocker'),
            'meta'   => array()
        ));
        
        // Separator
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-separator-1',
            'parent' => 'login-blocker',
            'title'  => '———',
            'href'   => false,
            'meta'   => array()
        ));
        
        // Szybkie akcje
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-quick-actions',
            'parent' => 'login-blocker',
            'title'  => 'Szybkie akcje:',
            'href'   => false,
            'meta'   => array()
        ));
        
        // Odblokuj wszystkie (jeśli są zablokowane)
        if ($blocked_count > 0) {
            $admin_bar->add_menu(array(
                'id'     => 'login-blocker-unblock-all',
                'parent' => 'login-blocker',
                'title'  => '• Odblokuj wszystkie IP',
                'href'   => wp_nonce_url(admin_url('admin.php?page=login-blocker&action=unblock_all'), 'login_blocker_action'),
                'meta'   => array(
                    'onclick' => 'return confirm("Czy na pewno chcesz odblokować WSZYSTKIE adresy IP?")'
                )
            ));
        }
        
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-clear-all',
            'parent' => 'login-blocker',
            'title'  => '• Wyczyść wszystkie rekordy',
            'href'   => wp_nonce_url(admin_url('admin.php?page=login-blocker&action=delete_all'), 'login_blocker_action'),
            'meta'   => array(
                'onclick' => 'return confirm("Czy na pewno chcesz usunąć WSZYSTKIE rekordy?")'
            )
        ));
        
        // Separator
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-separator-2',
            'parent' => 'login-blocker',
            'title'  => '———',
            'href'   => false,
            'meta'   => array()
        ));
        
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-analytics',
            'parent' => 'login-blocker',
            'title'  => 'Statystyki',
            'href'   => admin_url('admin.php?page=login-blocker-analytics'),
            'meta'   => array(
                'title' => 'Zaawansowane statystyki i raporty'
            )
        ));
        
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-settings',
            'parent' => 'login-blocker',
            'title'  => 'Ustawienia',
            'href'   => admin_url('admin.php?page=login-blocker-settings'),
            'meta'   => array()
        ));
    }
    
    // Główna strona admina
    public function admin_page() {
        global $wpdb;
        
        // Obsługa akcji
        if (isset($_GET['action']) && check_admin_referer('login_blocker_action')) {
            if ($_GET['action'] === 'unblock' && isset($_GET['ip'])) {
                $ip = sanitize_text_field($_GET['ip']);
                $wpdb->update(
                    $this->table_name,
                    array('is_blocked' => 0, 'attempts' => 0, 'block_until' => null),
                    array('ip_address' => $ip)
                );
                
                $this->main_class->log_info("IP odblokowane ręcznie", array(
                    'ip' => $ip,
                    'admin_user' => wp_get_current_user()->user_login
                ));
                
                echo '<div class="notice notice-success is-dismissible"><p>IP odblokowane.</p></div>';
            } elseif ($_GET['action'] === 'unblock_all') {
                $wpdb->update(
                    $this->table_name,
                    array('is_blocked' => 0, 'attempts' => 0, 'block_until' => null),
                    array('is_blocked' => 1)
                );
                echo '<div class="notice notice-success is-dismissible"><p>Wszystkie IP odblokowane.</p></div>';
            } elseif ($_GET['action'] === 'delete' && isset($_GET['ip'])) {
                $ip = sanitize_text_field($_GET['ip']);
                $wpdb->delete($this->table_name, array('ip_address' => $ip));
                echo '<div class="notice notice-success is-dismissible"><p>Rekord usunięty.</p></div>';
            } elseif ($_GET['action'] === 'delete_all') {
                $wpdb->query("TRUNCATE TABLE {$this->table_name}");
                echo '<div class="notice notice-success is-dismissible"><p>Wszystkie rekordy usunięte.</p></div>';
            }
            
            // Przekieruj do zablokowanych IP lub pokaż dashboard
            wp_redirect(admin_url('admin.php?page=login-blocker-blocked'));
            exit;
        }
        
        // Pobieranie zablokowanych IP
        $blocked_ips = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE is_blocked = 1 ORDER BY last_attempt DESC"
        );
        
        // Pobieranie wszystkich prób
        $all_attempts = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY last_attempt DESC LIMIT 100"
        );
        
        ?>
        <div class="wrap">
            <h1>Login Blocker - Zablokowane IP</h1>
            
            <div class="card" style="width: 100%; max-width: 100%; box-sizing: border-box;">
                <h2>Obecnie zablokowane adresy IP</h2>
                <?php if ($blocked_ips): ?>
                    <div style="overflow-x: auto; width: 100%;">
                        <table class="wp-list-table widefat fixed striped" style="width: 100%; min-width: 800px;">
                            <thead>
                                <tr>
                                    <th style="width: 15%;">Adres IP</th>
                                    <th style="width: 20%;">Użytkownik</th>
                                    <th style="width: 10%;">Próby</th>
                                    <th style="width: 20%;">Ostatnia próba</th>
                                    <th style="width: 20%;">Zablokowany do</th>
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
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker&action=unblock&ip=' . $ip->ip_address), 'login_blocker_action'); ?>" class="button">Odblokuj</a>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker&action=delete&ip=' . $ip->ip_address), 'login_blocker_action'); ?>" class="button button-danger" onclick="return confirm('Czy na pewno chcesz usunąć?')">Usuń</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>Brak zablokowanych adresów IP.</p>
                <?php endif; ?>
            </div>
            
            <div class="card" style="width: 100%; max-width: 100%; box-sizing: border-box; margin-top: 20px;">
                <h2>Ostatnie próby logowania (ostatnie 100)</h2>
                <?php if ($all_attempts): ?>
                    <div style="margin-bottom: 15px;">
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker&action=delete_all'), 'login_blocker_action'); ?>" class="button button-danger" onclick="return confirm('Czy na pewno chcesz usunąć WSZYSTKIE rekordy?')">Wyczyść wszystkie rekordy</a>
                    </div>
                    <div style="overflow-x: auto; width: auto;">
                        <table class="wp-list-table widefat fixed striped" style="width: 100%; min-width: 900px;">
                            <thead>
                                <tr>
                                    <th style="width: 15%;">Adres IP</th>
                                    <th style="width: 20%;">Użytkownik</th>
                                    <th style="width: 10%;">Próby</th>
                                    <th style="width: 20%;">Ostatnia próba</th>
                                    <th style="width: 15%;">Status</th>
                                    <th style="width: 20%;">Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_attempts as $attempt): ?>
                                    <tr>
                                        <td><?php echo esc_html($attempt->ip_address); ?></td>
                                        <td><?php echo esc_html($attempt->username); ?></td>
                                        <td><?php echo esc_html($attempt->attempts); ?></td>
                                        <td><?php echo esc_html($attempt->last_attempt); ?></td>
                                        <td>
                                            <?php if ($attempt->is_blocked): ?>
                                                <span style="color: red; font-weight: bold;">ZABLOKOWANY</span>
                                            <?php else: ?>
                                                <span style="color: orange;">AKTYWNY</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($attempt->is_blocked): ?>
                                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker&action=unblock&ip=' . $attempt->ip_address), 'login_blocker_action'); ?>" class="button">Odblokuj</a>
                                            <?php endif; ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker&action=delete&ip=' . $attempt->ip_address), 'login_blocker_action'); ?>" class="button button-danger" onclick="return confirm('Czy na pewno chcesz usunąć?')">Usuń</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>Brak zapisanych prób logowania.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    // Widget dashboard
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'login_blocker_widget',
            'Login Blocker - Statystyki',
            array($this, 'dashboard_widget_content')
        );
    }
    
    public function dashboard_widget_content() {
        global $wpdb;
        
        $total_blocked = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1");
        $recent_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE last_attempt > %s",
            date('Y-m-d H:i:s', time() - 3600)
        ));
        
        $top_attempted_users = $wpdb->get_results(
            "SELECT username, COUNT(*) as count 
             FROM {$this->table_name} 
             WHERE username IS NOT NULL 
             GROUP BY username 
             ORDER BY count DESC 
             LIMIT 5"
        );
        
        echo '<div style="text-align: center;">';
        echo '<div style="font-size: 2em; font-weight: bold; color: #d63638;">' . $total_blocked . '</div>';
        echo '<p>Obecnie zablokowanych IP</p>';
        echo '<hr>';
        echo '<div style="font-size: 1.5em; font-weight: bold; color: #2271b1;">' . $recent_attempts . '</div>';
        echo '<p>Prób logowania (ostatnia godzina)</p>';
        echo '</div>';
        
        if ($top_attempted_users) {
            echo '<hr>';
            echo '<h4>Najczęściej atakowani użytkownicy:</h4>';
            echo '<ul>';
            foreach ($top_attempted_users as $user) {
                echo '<li>' . esc_html($user->username) . ' (' . $user->count . ' prób)</li>';
            }
            echo '</ul>';
        }
        
        echo '<p style="text-align: center; margin-top: 15px;">';
        echo '<a href="' . admin_url('admin.php?page=login-blocker') . '" class="button button-primary">Zarządzaj blokadami</a>';
        echo '</p>';
    }
    
    // Ajax do odblokowywania IP
    public function ajax_unblock_ip() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'login_blocker_ajax')) {
            wp_die('Błąd bezpieczeństwa');
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        
        global $wpdb;
        $result = $wpdb->update(
            $this->table_name,
            array('is_blocked' => 0, 'attempts' => 0, 'block_until' => null),
            array('ip_address' => $ip)
        );
        
        if ($result !== false) {
            wp_send_json_success('IP odblokowane');
        } else {
            wp_send_json_error('Błąd odblokowywania');
        }
    }

    // Funkcja AJAX do testowania konfiguracji email
    public function test_email_config() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'login_blocker_test_email')) {
            wp_die('Błąd bezpieczeństwa');
        }
        
        $to = get_option('login_blocker_notification_email', get_option('admin_email'));
        
        if (empty($to)) {
            wp_send_json_error('Brak skonfigurowanego adresu email');
            return;
        }
        
        $subject = 'Test powiadomień Login Blocker - ' . get_bloginfo('name');
        $message = "
To jest testowy email z wtyczki Login Blocker.

Czas wysłania: " . current_time('mysql') . "
Strona: " . get_bloginfo('url') . "
Konfiguracja SMTP: " . (get_option('login_blocker_smtp_enabled') ? 'WŁĄCZONA' : 'WYŁĄCZONA') . "

Jeśli otrzymałeś tę wiadomość, konfiguracja powiadomień działa poprawnie.

---
To jest automatyczna wiadomość testowa.
";
        
        $result = $this->main_class->send_email($to, $subject, $message);
        
        if ($result) {
            wp_send_json_success('Testowy email został wysłany pomyślnie! Sprawdź skrzynkę odbiorczą.');
        } else {
            wp_send_json_error('Nie udało się wysłać testowego emaila. Sprawdź konfigurację.');
        }
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'login-blocker') === false) return;
        
        // Chart.js dla wykresów
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.7.0');
        
        // Leaflet dla mapy (tylko na stronie statystyk)
        if ($hook === 'toplevel_page_login-blocker-analytics') {
            wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.7.1/dist/leaflet.css');
            wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.7.1/dist/leaflet.js', array(), '1.7.1');
        }
        
        // Własny CSS
        wp_enqueue_style('login-blocker-admin', LOGIN_BLOCKER_PLUGIN_URL . 'assets/css/admin.css');
        
        // Własny JS z danymi dla wykresów
        wp_enqueue_script('login-blocker-admin-js', LOGIN_BLOCKER_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'chart-js'), '1.0.0', true);
        
        // Localize script dla AJAX
        wp_localize_script('login-blocker-admin-js', 'loginBlocker', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('login_blocker_ajax'),
            'texts' => array(
                'sending' => __('Wysyłanie...', 'login-blocker'),
                'error' => __('Błąd!', 'login-blocker'),
                'success' => __('Sukces!', 'login-blocker'),
                'confirm_unblock' => __('Czy na pewno chcesz odblokować', 'login-blocker'),
                'confirm_delete' => __('Czy na pewno chcesz usunąć ten rekord?', 'login-blocker')
            )
        ));
    }
    
    // Rejestracja ustawień - delegowane do klasy settings
    public function register_settings() {
        $this->settings->register_settings();
    }
}
