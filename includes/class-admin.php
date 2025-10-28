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
    
    public function __construct($main_class) {
        global $wpdb;
        $this->main_class = $main_class;
        //$this->main_class = $main_class;
        //$this->table_name = $main_class->get_table_name();
        $this->database = $main_class->get_database();
        $this->table_name = $wpdb->prefix . 'login_blocker_attempts'; 
        
        // Rejestracja hooków admina
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Ajax dla odblokowywania IP
        add_action('wp_ajax_unblock_ip', array($this, 'ajax_unblock_ip'));
        // Ajax dla testowania email
        add_action('wp_ajax_test_email_config', array($this, 'test_email_config'));
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
            array($this, 'blocked_ips_page')
        );
        
        add_submenu_page(
            'login-blocker',
            'Statystyki & Próby',
            'Statystyki & Próby',
            'manage_options',
            'login-blocker-analytics',
            array($this, 'analytics_page')
        );
        
        add_submenu_page(
            'login-blocker',
            'Debug & Logi',
            'Debug & Logi',
            'manage_options',
            'login-blocker-debug',
            array($this, 'debug_page')
        );

        add_submenu_page(
            'login-blocker',
            __('Eksport Danych', 'login-blocker'),
            __('Eksport Danych', 'login-blocker'),
            'export',
            'login-blocker-export',
            array($this, 'export_page')
        );
        
        add_submenu_page(
            'login-blocker',
            'Ustawienia',
            'Ustawienia',
            'manage_options',
            'login-blocker-settings',
            array($this, 'settings_page')
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
            } elseif ($_GET['action'] === 'delete_all_blocked') {
                $wpdb->delete($this->table_name, array('is_blocked' => 1));
                echo '<div class="notice notice-success is-dismissible"><p>Wszystkie zablokowane rekordy usunięte.</p></div>';
            }
        }
        
        // Pobieranie zablokowanych IP
        $blocked_ips = $wpdb->get_results(
            $wpdb->prepare("
                SELECT * FROM {$this->table_name} 
                WHERE is_blocked = 1 {$search_sql}
                ORDER BY last_attempt DESC 
                LIMIT %d, %d
            ", $offset, $per_page)
        );
        
        // Liczba wszystkich zablokowanych IP
        $total_blocked = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1 {$search_sql}"
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

    // Strona debugowania
    public function debug_page() {
        $log_files = $this->main_class->get_log_files();
        $current_log = isset($_GET['log_file']) ? sanitize_text_field($_GET['log_file']) : '';
        $log_content = '';
        
        if ($current_log && in_array($current_log, $log_files)) {
            $log_content = $this->main_class->get_log_content($current_log);
        }
        
        // Akcje
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'clear_logs' && check_admin_referer('login_blocker_debug')) {
                $this->main_class->clear_logs();
                echo '<div class="notice notice-success is-dismissible"><p>Logi zostały wyczyszczone.</p></div>';
            } elseif ($_POST['action'] === 'test_logging' && check_admin_referer('login_blocker_debug')) {
                $this->main_class->test_logging();
                echo '<div class="notice notice-success is-dismissible"><p>Test logowania wykonany. Sprawdź logi.</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1>Login Blocker - Debug & Logi</h1>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="card">
                    <h3>Status Systemu</h3>
                    <?php $this->main_class->display_system_status(); ?>
                </div>
                
                <div class="card">
                    <h3>Akcje Debugowania</h3>
                    <form method="post" style="margin-bottom: 15px;">
                        <?php wp_nonce_field('login_blocker_debug'); ?>
                        <input type="hidden" name="action" value="test_logging">
                        <button type="submit" class="button button-secondary">Testuj Logowanie</button>
                        <p class="description">Tworzy testowe wpisy we wszystkich poziomach logów</p>
                    </form>
                    
                    <form method="post">
                        <?php wp_nonce_field('login_blocker_debug'); ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="button button-danger" onclick="return confirm('Czy na pewno chcesz wyczyścić wszystkie logi?')">Wyczyść Wszystkie Logi</button>
                        <p class="description">Usuwa wszystkie pliki logów</p>
                    </form>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 300px 1fr; gap: 20px;">
                <div class="card">
                    <h3>Pliki Logów</h3>
                    <?php if ($log_files): ?>
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            <?php foreach ($log_files as $log_file): ?>
                                <li style="margin-bottom: 5px;">
                                    <a href="<?php echo admin_url('admin.php?page=login-blocker-debug&log_file=' . urlencode($log_file)); ?>" 
                                       style="display: block; padding: 8px; background: <?php echo $current_log === $log_file ? '#e0f0ff' : '#f6f7f7'; ?>; border-radius: 4px; text-decoration: none;">
                                        <?php echo esc_html($log_file); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>Brak plików logów.</p>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h3>Zawartość Logu: <?php echo esc_html($current_log ?: 'Wybierz plik'); ?></h3>
                    <?php if ($log_content): ?>
                        <div style="background: #1d2327; color: #f0f0f1; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
                            <?php echo esc_html($log_content); ?>
                        </div>
                    <?php else: ?>
                        <p>Wybierz plik logu z listy po lewej stronie aby wyświetlić jego zawartość.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card">
            <h3>Akcje Debugowania</h3>
        
            <form method="post" style="margin-bottom: 10px;">
                <?php wp_nonce_field('login_blocker_debug'); ?>
                <input type="hidden" name="action" value="create_test_entry">
                <button type="submit" class="button button-primary">Utwórz Testowy Wpis</button>
                <p class="description">Tworzy testowy wpis w logach i odświeża stronę</p>
            </form>
        
            <form method="post" style="margin-bottom: 10px;">
                <?php wp_nonce_field('login_blocker_debug'); ?>
                <input type="hidden" name="action" value="test_logging">
                <button type="submit" class="button button-secondary">Testuj Wszystkie Poziomy Logów</button>
                <p class="description">Tworzy testowe wpisy we wszystkich poziomach logów</p>
            </form>
        
            <form method="post">
                <?php wp_nonce_field('login_blocker_debug'); ?>
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="button button-danger" onclick="return confirm('Czy na pewno chcesz wyczyścić wszystkie logi?')">Wyczyść Wszystkie Logi</button>
                <p class="description">Usuwa wszystkie pliki logów</p>
            </form>
        </div>
        <?php
    }

    public function export_page() {
        if (!current_user_can('export')) {
            wp_die(esc_html__('Brak uprawnień', 'login-blocker'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Eksport Danych Login Blocker', 'login-blocker'); ?></h1>
            
            <div class="card">
                <h2><?php echo esc_html__('Eksport Prób Logowania', 'login-blocker'); ?></h2>
                <form method="post" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="login_blocker_export" value="1">
                    <?php wp_nonce_field('login_blocker_export', 'export_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="export-format"><?php echo esc_html__('Format eksportu', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <select name="format" id="export-format" required>
                                    <option value="csv">CSV</option>
                                    <option value="json">JSON</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="export-period"><?php echo esc_html__('Okres (dni)', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="period" id="export-period" 
                                       min="1" max="365" value="30" required>
                                <p class="description"><?php echo esc_html__('Dane z ostatnich X dni', 'login-blocker'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php echo esc_html__('Eksportuj Dane', 'login-blocker'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2><?php echo esc_html__('Eksport Statystyk', 'login-blocker'); ?></h2>
                <form method="post" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="login_blocker_export" value="1">
                    <input type="hidden" name="type" value="stats">
                    <?php wp_nonce_field('login_blocker_export', 'export_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="stats-period"><?php echo esc_html__('Okres (dni)', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="period" id="stats-period" 
                                       min="1" max="365" value="30" required>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-secondary">
                            <?php echo esc_html__('Eksportuj Statystyki', 'login-blocker'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    // Rejestracja ustawień
    public function register_settings() {
        register_setting('login_blocker_settings', 'login_blocker_max_attempts');
        register_setting('login_blocker_settings', 'login_blocker_block_duration');
        register_setting('login_blocker_settings', 'login_blocker_debug_mode');
        register_setting('login_blocker_settings', 'login_blocker_error_notifications');
        
        // NOWE USTAWIENIA EMAIL
        register_setting('login_blocker_settings', 'login_blocker_smtp_enabled');
        register_setting('login_blocker_settings', 'login_blocker_smtp_host');
        register_setting('login_blocker_settings', 'login_blocker_smtp_port');
        register_setting('login_blocker_settings', 'login_blocker_smtp_username');
        register_setting('login_blocker_settings', 'login_blocker_smtp_password');
        register_setting('login_blocker_settings', 'login_blocker_smtp_encryption');
        register_setting('login_blocker_settings', 'login_blocker_notification_email');
        
        add_settings_section(
            'login_blocker_main',
            'Główne ustawienia',
            array($this, 'settings_section_callback'),
            'login-blocker-settings'
        );
        
        add_settings_field(
            'login_blocker_max_attempts',
            'Maksymalna liczba prób',
            array($this, 'max_attempts_callback'),
            'login-blocker-settings',
            'login_blocker_main'
        );
        
        add_settings_field(
            'login_blocker_block_duration',
            'Czas blokady (w sekundach)',
            array($this, 'block_duration_callback'),
            'login-blocker-settings',
            'login_blocker_main'
        );
        
        add_settings_field(
            'login_blocker_debug_mode',
            'Tryb Debugowania',
            array($this, 'debug_mode_callback'),
            'login-blocker-settings',
            'login_blocker_main'
        );
        
        add_settings_field(
            'login_blocker_error_notifications',
            'Powiadomienia o Błędach',
            array($this, 'error_notifications_callback'),
            'login-blocker-settings',
            'login_blocker_main'
        );
        
        // NOWA SEKCJA DLA USTAWIENIA EMAIL
        add_settings_section(
            'login_blocker_email',
            'Ustawienia Powiadomień Email',
            array($this, 'email_section_callback'),
            'login-blocker-settings'
        );
        
        add_settings_field(
            'login_blocker_notification_email',
            'Email do powiadomień',
            array($this, 'notification_email_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
        
        add_settings_field(
            'login_blocker_smtp_enabled',
            'Własny serwer SMTP',
            array($this, 'smtp_enabled_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
        
        add_settings_field(
            'login_blocker_smtp_host',
            'Serwer SMTP',
            array($this, 'smtp_host_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
        
        add_settings_field(
            'login_blocker_smtp_port',
            'Port SMTP',
            array($this, 'smtp_port_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
        
        add_settings_field(
            'login_blocker_smtp_username',
            'Nazwa użytkownika SMTP',
            array($this, 'smtp_username_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
        
        add_settings_field(
            'login_blocker_smtp_password',
            'Hasło SMTP',
            array($this, 'smtp_password_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
        
        add_settings_field(
            'login_blocker_smtp_encryption',
            'Szyfrowanie',
            array($this, 'smtp_encryption_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
        
        // NOWE POLE DO TESTOWANIA EMAIL
        add_settings_field(
            'login_blocker_test_email',
            'Test powiadomień',
            array($this, 'test_email_callback'),
            'login-blocker-settings',
            'login_blocker_email'
        );
    }

    public function email_section_callback() {
        echo '<p>Konfiguracja powiadomień email o błędach i atakach. Jeśli nie skonfigurujesz własnego SMTP, zostanie użyty domyślny system WordPress.</p>';
    }
    
    public function notification_email_callback() {
        $value = get_option('login_blocker_notification_email', get_option('admin_email'));
        echo '<input type="email" name="login_blocker_notification_email" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Adres email na który będą wysyłane powiadomienia (domyślnie: admin)</p>';
    }
    
    public function smtp_enabled_callback() {
        $value = get_option('login_blocker_smtp_enabled', false);
        echo '<label><input type="checkbox" name="login_blocker_smtp_enabled" value="1" ' . checked(1, $value, false) . ' /> Użyj własnego serwera SMTP</label>';
        echo '<p class="description">Włącz jeśli chcesz używać własnego serwera pocztowego zamiast domyślnego systemu WordPress</p>';
    }

    public function smtp_host_callback() {
        $value = get_option('login_blocker_smtp_host', '');
        echo '<input type="text" name="login_blocker_smtp_host" value="' . esc_attr($value) . '" class="regular-text" placeholder="smtp.example.com" />';
        echo '<p class="description">Adres serwera SMTP</p>';
    }

    public function smtp_port_callback() {
        $value = get_option('login_blocker_smtp_port', '587');
        echo '<input type="number" name="login_blocker_smtp_port" value="' . esc_attr($value) . '" class="small-text" min="1" max="65535" />';
        echo '<p class="description">Port serwera SMTP (zazwyczaj 587, 465 lub 25)</p>';
    }

    public function smtp_username_callback() {
        $value = get_option('login_blocker_smtp_username', '');
        echo '<input type="text" name="login_blocker_smtp_username" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Nazwa użytkownika do serwera SMTP</p>';
    }

    public function smtp_password_callback() {
        $value = get_option('login_blocker_smtp_password', '');
        echo '<input type="password" name="login_blocker_smtp_password" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Hasło do serwera SMTP</p>';
    }

    public function smtp_encryption_callback() {
        $value = get_option('login_blocker_smtp_encryption', 'tls');
        echo '<select name="login_blocker_smtp_encryption">';
        echo '<option value="" ' . selected($value, '', false) . '>Brak</option>';
        echo '<option value="ssl" ' . selected($value, 'ssl', false) . '>SSL</option>';
        echo '<option value="tls" ' . selected($value, 'tls', false) . '>TLS</option>';
        echo '</select>';
        echo '<p class="description">Typ szyfrowania (zazwyczaj TLS dla portu 587, SSL dla portu 465)</p>';
    }

    public function test_email_callback() {
        echo '<button type="button" id="test-email-btn" class="button button-secondary">Wyślij testowego emaila</button>';
        echo '<span id="test-email-result" style="margin-left: 10px;"></span>';
        echo '<p class="description">Wyślij testowego emaila aby sprawdzić konfigurację</p>';
    }
    
    public function debug_mode_callback() {
        $value = get_option('login_blocker_debug_mode', false);
        echo '<label><input type="checkbox" name="login_blocker_debug_mode" value="1" ' . checked(1, $value, false) . ' /> Włącz tryb debugowania</label>';
        echo '<p class="description">';
        echo 'Włącz: loguje WSZYSTKIE zdarzenia (DEBUG, INFO, WARNING, ERROR)<br>';
        echo 'Wyłącz: loguje TYLKO błędy i ostrzeżenia (WARNING, ERROR)';
        echo '</p>';
    }

    public function error_notifications_callback() {
        $value = get_option('login_blocker_error_notifications', true);
        echo '<label><input type="checkbox" name="login_blocker_error_notifications" value="1" ' . checked(1, $value, false) . ' /> Włącz powiadomienia email o błędach</label>';
        echo '<p class="description">Wysyła powiadomienia na adres admina gdy wystąpią krytyczne błędy.</p>';
    }
    
    public function settings_section_callback() {
        echo '<p>Konfiguracja ustawień blokowania logowania</p>';
    }
    
    public function max_attempts_callback() {
        $value = get_option('login_blocker_max_attempts', 5);
        echo '<input type="number" name="login_blocker_max_attempts" value="' . esc_attr($value) . '" min="1" max="20" />';
        echo '<p class="description">Liczba nieudanych prób logowania po której IP zostanie zablokowane</p>';
    }
    
    public function block_duration_callback() {
        $value = get_option('login_blocker_block_duration', 3600);
        echo '<input type="number" name="login_blocker_block_duration" value="' . esc_attr($value) . '" min="60" />';
        echo '<p class="description">Czas blokady IP w sekundach (3600 = 1 godzina, 86400 = 24 godziny)</p>';
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
    
    // Strona ustawień
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Login Blocker - Ustawienia</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('login_blocker_settings');
                do_settings_sections('login-blocker-settings');
                submit_button();
                ?>
            </form>
            <div class="card">
                <h2>Status Aktualizacji</h2>
                <?php $this->main_class->display_update_status(); ?>
            </div>
            <div class="card">
                <h2>Statystyki</h2>
                <?php
                global $wpdb;
                $total_blocked = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1");
                $total_attempts = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
                $unique_ips = $wpdb->get_var("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name}");
                ?>
                <p>Obecnie zablokowanych IP: <strong><?php echo $total_blocked; ?></strong></p>
                <p>Wszystkich zapisanych prób: <strong><?php echo $total_attempts; ?></strong></p>
                <p>Unikalnych adresów IP: <strong><?php echo $unique_ips; ?></strong></p>
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

    // Strona ze statystykami
    public function analytics_page() {
        global $wpdb;
        
        // Okres czasu dla statystyk (domyślnie 30 dni)
        $period = isset($_GET['period']) ? intval($_GET['period']) : 30;
        $start_date = date('Y-m-d', strtotime("-$period days"));
        
        // Pobierz ostatnie 100 prób logowania
        $recent_attempts = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} 
             ORDER BY last_attempt DESC 
             LIMIT 100"
        );
        
        ?>
        <div class="wrap">
            <h1>Login Blocker - Statystyki & Ostatnie Próby</h1>
            
            <!-- Filtry okresu czasu -->
            <div class="card" style="margin-bottom: 20px;">
                <h3>Filtruj statystyki</h3>
                <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="page" value="login-blocker-analytics">
                    <label for="period">Okres czasu:</label>
                    <select name="period" id="period" onchange="this.form.submit()">
                        <option value="7" <?php selected($period, 7); ?>>7 dni</option>
                        <option value="30" <?php selected($period, 30); ?>>30 dni</option>
                        <option value="90" <?php selected($period, 90); ?>>90 dni</option>
                        <option value="365" <?php selected($period, 365); ?>>1 rok</option>
                    </select>
                </form>
            </div>
            
            <!-- KARTY STATYSTYK - PŁYWAJĄCE -->
            <div class="login-blocker-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <?php $this->main_class->display_stats_cards($period); ?>
            </div>
            
            <!-- SEKCJA Z OSTATNIMI PRÓBAMI I WYKRESAMI - PŁYWAJĄCE -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <!-- Lewa kolumna - Ostatnie próby -->
                <div class="card">
                    <h3>Ostatnie próby logowania (100)</h3>
                    <?php if ($recent_attempts): ?>
                        <div style="overflow-x: auto; max-height: 800px;">
                            <table class="wp-list-table widefat fixed striped" style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th>IP</th>
                                        <th>Użytkownik</th>
                                        <th>Próby</th>
                                        <th>Status</th>
                                        <th style="width: 80px;">Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_attempts as $attempt): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 5px;">
                                                    <?php if (!empty($attempt->country_code) && $attempt->country_code !== 'LOCAL'): ?>
                                                        <?php 
                                                            $country_code_lower = strtolower($attempt->country_code);
                                                            $flag_url = "https://flagcdn.com/16x12/{$country_code_lower}.png";
                                                        ?>
                                                        <img src="<?php echo $flag_url; ?>" alt="<?php echo esc_attr($attempt->country_code); ?>" title="<?php echo esc_attr($attempt->country_name); ?>" style="width: 16px; height: 12px;">
                                                    <?php endif; ?>
                                                    <span style="font-family: monospace; font-size: 12px;"><?php echo esc_html($attempt->ip_address); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo esc_html($attempt->username); ?></td>
                                            <td>
                                                <span style="font-weight: bold; color: <?php echo $attempt->attempts > 10 ? '#d63638' : '#0073aa'; ?>">
                                                    <?php echo esc_html($attempt->attempts); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($attempt->is_blocked): ?>
                                                    <span style="color: red; font-weight: bold; font-size: 12px;">BLOKADA</span>
                                                <?php else: ?>
                                                    <span style="color: green; font-size: 12px;">aktywny</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 2px; flex-wrap: wrap;">
                                                    <?php if ($attempt->is_blocked): ?>
                                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=unblock&ip=' . $attempt->ip_address), 'login_blocker_action'); ?>" class="button" style="padding: 2px 5px; font-size: 11px; height: auto;">Odblokuj</a>
                                                    <?php endif; ?>
                                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=delete&ip=' . $attempt->ip_address), 'login_blocker_action'); ?>" class="button button-danger" style="padding: 2px 5px; font-size: 11px; height: auto;" onclick="return confirm('Usunąć?')">Usuń</a>
                                                </div>
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
                
                <!-- Prawa kolumna - Wykresy -->
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <div class="card">
                        <h3>Próby logowania w czasie</h3>
                        <?php $this->main_class->display_attempts_chart($period); ?>
                    </div>
                    
                    <div class="card">
                        <h3>Rozkład geograficzny</h3>
                        <?php $this->main_class->display_country_stats($period); ?>
                    </div>
                </div>
            </div>
            
            <!-- DOLNA SEKCJA - PŁYWAJĄCE -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="card">
                    <h3>Najczęściej atakowani użytkownicy</h3>
                    <?php $this->main_class->display_top_users($period); ?>
                </div>
                
                <div class="card">
                    <h3>Najaktywniejsze adresy IP</h3>
                    <?php $this->main_class->display_top_ips($period); ?>
                </div>
            </div>
            
            <!-- MAPA - PEŁNA SZEROKOŚĆ -->
            <div class="card">
                <h3>Mapa ataków</h3>
                <?php $this->main_class->display_attack_map($period); ?>
            </div>
        </div>
        <?php
    }
}
