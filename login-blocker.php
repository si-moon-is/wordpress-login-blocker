<?php
/**
 * Plugin Name: Login Blocker
 * Description: Blokuje IP po nieudanych próbach logowania z własnym panelem administracyjnym
 * Version: 1.0.0
 * Author: Szymon Kościkiewicz
 */

// Zabezpieczenie przed bezpośśrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'lb_activation_check');
function lb_activation_check() {
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(basename(__FILE__));
        wp_die('This plugin requires PHP 7.4 or higher.');
    }
}

function lb_secure_ajax_handler() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_ajax_referer('lb_ajax_nonce', 'nonce');
}

// Definiowanie stałych
define('LOGIN_BLOCKER_VERSION', '1.0.0');
define('LOGIN_BLOCKER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LOGIN_BLOCKER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('LOGIN_BLOCKER_DEBUG', get_option('login_blocker_debug_mode', false));
define('LOGIN_BLOCKER_LOG_PATH', WP_CONTENT_DIR . '/logs/login-blocker/');

// Inicjalizacja tłumaczeń
add_action('plugins_loaded', 'login_blocker_load_textdomain');
function login_blocker_load_textdomain() {
    load_plugin_textdomain(
        'login-blocker',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}

// Klasa główna wtyczki
class LoginBlocker {
    
    private $table_name;
    private $max_attempts;
    private $block_duration;
    private $debug_mode;
    
    public function __construct() {
	global $wpdb;
        $this->table_name = $wpdb->prefix . 'login_blocker_attempts';

	// UPDATE
	require_once plugin_dir_path(__FILE__) . 'includes/class-updater.php';
        
        // Pobieranie ustawień
        $this->max_attempts = get_option('login_blocker_max_attempts', 5);
        $this->block_duration = get_option('login_blocker_block_duration', 3600);
        $this->debug_mode = LOGIN_BLOCKER_DEBUG;
	$this->init_updater();
        
        // Rejestracja hooków
        add_action('plugins_loaded', array($this, 'init'));
        add_action('wp_login_failed', array($this, 'handle_failed_login'));
        add_filter('authenticate', array($this, 'check_ip_blocked'), 30, 3);
        
        // Rejestracja menu admina
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Górny pasek admina
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        
        // Ajax dla odblokowywania IP
        add_action('wp_ajax_unblock_ip', array($this, 'ajax_unblock_ip'));
        
        // Rejestracja widgeta dashboard
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        
        // Cron do czyszczenia starych rekordów
        add_action('login_blocker_cleanup', array($this, 'cleanup_old_records'));
        add_action('init', array($this, 'schedule_cleanup'));
        
        // Wykresy
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Debug i logi
        add_action('init', array($this, 'init_debug_system'));
        
        // Ajax dla testowania email
        add_action('wp_ajax_test_email_config', array($this, 'test_email_config'));

    add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    public function init() {
        $this->create_table();
        
        // Debug bazy danych przy pierwszym uruchomieniu
        if (get_option('login_blocker_first_run', true)) {
            $this->debug_database();
            update_option('login_blocker_first_run', false);
        }
    require_once plugin_dir_path(__FILE__) . 'includes/class-geolocation.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-email.php';
    require_once plugin_dir_path(__FILE__) . 'includes/ajax-handlers.php';

    new LoginBlocker_Ajax($this);
    }

    public function load_textdomain() {
        load_plugin_textdomain('login-blocker', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    // Planowanie cron job
    public function schedule_cleanup() {
        if (!wp_next_scheduled('login_blocker_cleanup')) {
            wp_schedule_event(time(), 'daily', 'login_blocker_cleanup');
        }
        $this->debug_settings();
    }
    
    // Czyszczenie starych rekordów (starszych niż 30 dni)
    public function cleanup_old_records() {
        global $wpdb;
        $delete_before = date('Y-m-d H:i:s', current_time('timestamp') - (30 * 24 * 60 * 60)); // UŻYJ TIMESTAMP WORDPRESSA
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < %s",
            $delete_before
        ));
    }
    
    // Inicjalizacja systemu debugowania
    public function init_debug_system() {
        // Zawsze twórz katalog logów
        $this->create_log_directory();
    
        // Automatycznie utwórz pierwszy wpis przy inicjalizacji
        $this->create_initial_log_entry();
    }
    
    private function create_initial_log_entry() {
        $log_files = $this->get_log_files();
    
        // Jeśli nie ma plików logów, utwórz pierwszy wpis
        if (empty($log_files)) {
            $this->log_info("System logów zainicjalizowany", array(
                'plugin_version' => LOGIN_BLOCKER_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'debug_mode' => $this->debug_mode,
                'log_directory' => LOGIN_BLOCKER_LOG_PATH
            ));
        }
    }
    
    public function test_log_system_on_activation() {
        $this->log_info("Plugin Login Blocker aktywowany", array(
            'version' => LOGIN_BLOCKER_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => phpversion()
        ));
        
        // Test zapisu do logów
        $test_result = $this->log_to_file('INFO', "[TEST] Test zapisu do logów przy aktywacji\n");
        
        if (!$test_result) {
            // Jeśli nie udało się zapisać do logów, spróbuj wysłać email do admina
            $this->notify_admin_on_error(
                'WARNING', 
                'Problem z systemem logów przy aktywacji wtyczki',
                array(
                    'log_directory' => LOGIN_BLOCKER_LOG_PATH,
                    'directory_exists' => file_exists(LOGIN_BLOCKER_LOG_PATH),
                    'directory_writable' => file_exists(LOGIN_BLOCKER_LOG_PATH) ? is_writable(LOGIN_BLOCKER_LOG_PATH) : false
                )
            );
        }
    }
    
    // Funkcja do debugowania bazy danych
    public function debug_settings() {
        $this->log_info("DEBUG USTAWIENIA", array(
            'max_attempts' => $this->max_attempts,
            'block_duration' => $this->block_duration,
            'option_max_attempts' => get_option('login_blocker_max_attempts'),
            'option_block_duration' => get_option('login_blocker_block_duration')
        ));
    }

    
    public function debug_database() {
        global $wpdb;
        
        $this->log_info("Debug bazy danych", array(
            'table_name' => $this->table_name,
            'table_exists' => $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") ? 'TAK' : 'NIE',
            'row_count' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'wpdb_error' => $wpdb->last_error,
            'wpdb_last_query' => $wpdb->last_query
        ));
    }
    
    public function debug_timezone() {
        $this->log_info("Debug strefy czasowej", array(
            'server_time' => date('Y-m-d H:i:s'),
            'wordpress_time' => current_time('mysql'),
            'server_timestamp' => time(),
            'wordpress_timestamp' => current_time('timestamp'),
            'timezone_string' => get_option('timezone_string'),
            'gmt_offset' => get_option('gmt_offset')
        ));
    }
    
    // Tworzenie tabeli w bazie danych
    private function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            username varchar(255) NULL,
            attempts int NOT NULL DEFAULT 0,
            last_attempt datetime NOT NULL,
            is_blocked tinyint(1) DEFAULT 0,
            block_until datetime NULL,
            country_code varchar(2) NULL,
            country_name varchar(100) NULL,
            city varchar(100) NULL,
            region varchar(100) NULL,
            isp varchar(255) NULL,
            latitude decimal(10,8) NULL,
            longitude decimal(11,8) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX ip_index (ip_address),
            INDEX blocked_index (is_blocked),
            INDEX username_index (username),
            INDEX country_index (country_code),
            INDEX date_index (last_attempt)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    // Pobieranie adresu IP klienta
    private function get_client_ip() {
    $ip_keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
            // DODAJ TĘ LINIĘ - lepsza walidacja IP:
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

    private function is_ip_blocked($ip) {
        global $wpdb;
        
        $blocked = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE ip_address = %s AND is_blocked = 1 AND block_until > %s",
            $ip,
            current_time('mysql')
        ));
        
        return !empty($blocked);
    }
    
    // Geolokalizacja IP
        // Geolokalizacja IP
    private function get_ip_geolocation($ip) {
        try {
            // Pomijanie prywatnych IP
            if ($ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
                $this->log_debug("Pominięto geolokalizację dla prywatnego IP", array('ip' => $ip));
                return array(
                    'country_code' => 'LOCAL',
                    'country_name' => 'Local Network',
                    'city' => 'Local',
                    'region' => 'Local',
                    'isp' => 'Local',
                    'latitude' => null,
                    'longitude' => null
                );
            }
            
            $transient_key = 'login_blocker_geo_' . md5($ip);
            $geolocation = get_transient($transient_key);
            
            if ($geolocation === false) {
                $this->log_debug("Pobieranie geolokalizacji z API", array('ip' => $ip));
                
                // Try ip-api.com (free)
                $response = wp_remote_get("http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,city,region,isp,lat,lon,query", array(
                    'timeout' => 5,
                    'redirection' => 2,
                    'httpversion' => '1.1',
                    'user-agent' => 'WordPress Login Blocker Plugin/' . LOGIN_BLOCKER_VERSION
                ));
                
                if (is_wp_error($response)) {
                    $this->log_warning("Błąd pobierania geolokalizacji z ip-api.com", array(
                        'ip' => $ip,
                        'error' => $response->get_error_message()
                    ));
                } else {
                    $data = json_decode(wp_remote_retrieve_body($response), true);
                    
                    if (isset($data['status']) && $data['status'] === 'success') {
                        $geolocation = array(
                            'country_code' => sanitize_text_field($data['countryCode'] ?? ''),
                            'country_name' => sanitize_text_field($data['country'] ?? ''),
                            'city' => sanitize_text_field($data['city'] ?? ''),
                            'region' => sanitize_text_field($data['regionName'] ?? ''),
                            'isp' => sanitize_text_field($data['isp'] ?? ''),
                            'latitude' => floatval($data['lat'] ?? null),
                            'longitude' => floatval($data['lon'] ?? null)
                        );
                        
                        set_transient($transient_key, $geolocation, WEEK_IN_SECONDS);
                        $this->log_debug("Pobrano geolokalizację z ip-api.com", array('ip' => $ip, 'data' => $geolocation));
                    } else {
                        $this->log_warning("Nieprawidłowa odpowiedź z ip-api.com", array(
                            'ip' => $ip,
                            'response' => $data
                        ));
                    }
                }
                
                // Fallback - jeśli pierwsza usługa nie działa
                if (empty($geolocation)) {
                    $this->log_debug("Próba fallback geolokalizacji z ipapi.co", array('ip' => $ip));
                    
                    $response = wp_remote_get("https://ipapi.co/{$ip}/json/", array(
                        'timeout' => 5,
                        'headers' => array('User-Agent' => 'WordPress-Login-Blocker-Plugin/1.0'),
                        'redirection' => 2,
                        'httpversion' => '1.1'
                    ));
                    
                    if (is_wp_error($response)) {
                        $this->log_warning("Błąd pobierania geolokalizacji z ipapi.co", array(
                            'ip' => $ip,
                            'error' => $response->get_error_message()
                        ));
                    } else {
                        $data = json_decode(wp_remote_retrieve_body($response), true);
                        
                        if (!isset($data['error'])) {
                            $geolocation = array(
                                'country_code' => sanitize_text_field($data['country_code'] ?? ''),
                                'country_name' => sanitize_text_field($data['country_name'] ?? ''),
                                'city' => sanitize_text_field($data['city'] ?? ''),
                                'region' => sanitize_text_field($data['region'] ?? ''),
                                'isp' => sanitize_text_field($data['org'] ?? ''),
                                'latitude' => floatval($data['latitude'] ?? null),
                                'longitude' => floatval($data['longitude'] ?? null)
                            );
                            
                            set_transient($transient_key, $geolocation, WEEK_IN_SECONDS);
                            $this->log_debug("Pobrano geolokalizację z ipapi.co", array('ip' => $ip, 'data' => $geolocation));
                        } else {
                            $this->log_warning("Nieprawidłowa odpowiedź z ipapi.co", array(
                                'ip' => $ip,
                                'response' => $data
                            ));
                        }
                    }
                }
            } else {
                $this->log_debug("Użyto geolokalizacji z cache", array('ip' => $ip));
            }
            
            if (!empty($geolocation)) {
                // Upewnij się, że wartości numeryczne są poprawnie sformatowane
                if (isset($geolocation['latitude']) && $geolocation['latitude'] !== null) {
                    $geolocation['latitude'] = floatval($geolocation['latitude']);
                }
                if (isset($geolocation['longitude']) && $geolocation['longitude'] !== null) {
                    $geolocation['longitude'] = floatval($geolocation['longitude']);
                }
                
                // Upewnij się, że stringi nie są zbyt długie
                $geolocation['country_code'] = substr($geolocation['country_code'] ?? '', 0, 2);
                $geolocation['country_name'] = substr($geolocation['country_name'] ?? '', 0, 100);
                $geolocation['city'] = substr($geolocation['city'] ?? '', 0, 100);
                $geolocation['region'] = substr($geolocation['region'] ?? '', 0, 100);
                $geolocation['isp'] = substr($geolocation['isp'] ?? '', 0, 255);
            }
            
            return $geolocation ?: array();
            
        } catch (Exception $e) {
            $this->log_error("Krytyczny błąd podczas geolokalizacji", array(
                'ip' => $ip,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            return array();
        }
    }
    
    // Zaktualizowana funkcja obsługi nieudanego logowania
    public function handle_failed_login($username) {
        try {
            $ip = $this->get_client_ip();
            
            // SPRAWDŹ CZY IP JUŻ JEST ZABLOKOWANE - DODAJ TEN WARUNEK
            if ($this->is_ip_blocked($ip)) {
                $this->log_debug("IP już zablokowane, pomijanie zwiększania licznika", array('ip' => $ip));
                return;
            }
            
            $this->log_info("Nieudana próba logowania", array(
                'ip' => $ip,
                'username' => $username,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'timestamp' => current_time('mysql')
            ));
            
            global $wpdb;
            
            // Pobieranie geolokalizacji
            $geolocation_start = microtime(true);
            $geolocation = $this->get_ip_geolocation($ip);
            $geolocation_time = round((microtime(true) - $geolocation_start) * 1000, 2);
            
            $this->log_debug("Geolokalizacja wykonana", array(
                'ip' => $ip,
                'time_ms' => $geolocation_time,
                'geolocation' => $geolocation
            ));
            
            // Sprawdzenie czy IP już istnieje w bazie
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE ip_address = %s",
                $ip
            ));
            
            $current_time = current_time('mysql');
            
            if ($existing) {
                // Aktualizacja istniejącego rekordu
                $new_attempts = $existing->attempts + 1;
                $is_blocked = ($new_attempts >= $this->max_attempts) ? 1 : $existing->is_blocked;
                $block_until = $is_blocked ? date('Y-m-d H:i:s', time() + $this->block_duration) : null;
                
                $update_data = array(
                    'attempts' => $new_attempts,
                    'last_attempt' => $current_time,
                    'is_blocked' => $is_blocked,
                    'username' => $username
                );
                
                if ($block_until) {
                    $update_data['block_until'] = $block_until;
                }
                
                // Aktualizuj geolokalizację tylko jeśli jej brak
                if (empty($existing->country_code) && !empty($geolocation)) {
                    $update_data = array_merge($update_data, $geolocation);
                }
                
                $result = $wpdb->update(
                    $this->table_name,
                    $update_data,
                    array('id' => $existing->id)
                );
                
                $this->log_info("Zaktualizowano istniejący rekord IP", array(
                    'ip' => $ip,
                    'previous_attempts' => $existing->attempts,
                    'new_attempts' => $new_attempts,
                    'is_blocked' => $is_blocked,
                    'update_result' => $result
                ));
                
            } else {
                // Tworzenie nowego rekordu
                $insert_data = array(
                    'ip_address' => $ip,
                    'username' => $username,
                    'attempts' => 1,
                    'last_attempt' => $current_time,
                    'is_blocked' => 0
                );
                
                // Dodaj geolokalizację jeśli dostępna
                if (!empty($geolocation)) {
                    $insert_data = array_merge($insert_data, $geolocation);
                }
                
                $result = $wpdb->insert(
                    $this->table_name,
                    $insert_data,
                    array('%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f')
                );
                
                $this->log_info("Utworzono nowy rekord IP", array(
                    'ip' => $ip,
                    'username' => $username,
                    'insert_result' => $result,
                    'insert_id' => $wpdb->insert_id
                ));
            }
            
            // Sprawdzanie czy należy zablokować IP
            $this->check_and_block_ip($ip);
            
        } catch (Exception $e) {
            $this->log_error("Krytyczny błąd podczas obsługi nieudanego logowania", array(
                'error' => $e->getMessage(),
                'ip' => $ip ?? 'unknown',
                'username' => $username,
                'trace' => $e->getTraceAsString()
            ));
        }
    }

    public function test_database_write() {
        global $wpdb;
        
        $test_data = array(
            'ip_address' => '192.168.1.100',
            'username' => 'test_user',
            'attempts' => 1,
            'last_attempt' => current_time('mysql'),
            'is_blocked' => 0
        );
        
        $result = $wpdb->insert($this->table_name, $test_data);
        
        $this->log_info("Test zapisu do bazy", array(
            'result' => $result,
            'insert_id' => $wpdb->insert_id,
            'error' => $wpdb->last_error
        ));
        
        return $result;
    }

    // Zaktualizowana funkcja sprawdzania blokady IP
    public function check_ip_blocked($user, $username, $password) {
        try {
            if (is_wp_error($user)) {
                return $user;
            }
            
            $ip = $this->get_client_ip();
            
            $this->log_debug("Sprawdzanie blokady IP", array(
                'ip' => $ip,
                'username' => $username
            ));
            
            global $wpdb;
            $blocked = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE ip_address = %s AND is_blocked = 1",
                $ip
            ));
            
            if ($blocked) {
                $block_until = strtotime($blocked->block_until);
                $current_time = current_time('timestamp'); // UŻYJ TIMESTAMP WORDPRESSA
                
                if ($current_time < $block_until) {
                    $this->log_info("Zablokowane IP próbuje się zalogować", array(
                        'ip' => $ip,
                        'username' => $username,
                        'blocked_until' => $blocked->block_until,
                        'remaining_minutes' => ceil(($block_until - $current_time) / 60)
                    ));
                    
                    // Przekierowanie na stronę główną
                    if (!defined('DOING_AJAX') && !defined('DOING_CRON') && $GLOBALS['pagenow'] === 'wp-login.php') {
                        $this->log_info("Przekierowanie zablokowanego IP na stronę główną", array('ip' => $ip));
                        wp_redirect(home_url());
                        exit;
                    }
                    
                    $remaining = $block_until - $current_time;
                    $minutes = ceil($remaining / 60);
                    
                    return new WP_Error(
                        'ip_blocked',
                        sprintf(__('Twoje IP zostało zablokowane. Spróbuj ponownie za %d minut.'), $minutes)
                    );
                } else {
                    // Odblokowanie IP po upływie czasu
                    $this->log_info("Automatyczne odblokowanie IP po upływie czasu", array('ip' => $ip));
                    $wpdb->update(
                        $this->table_name,
                        array('is_blocked' => 0, 'block_until' => null),
                        array('ip_address' => $ip)
                    );
                }
            }
            
            return $user;
            
        } catch (Exception $e) {
            $this->log_error("Błąd podczas sprawdzania blokady IP", array(
                'error' => $e->getMessage(),
                'ip' => $ip ?? 'unknown',
                'username' => $username,
                'trace' => $e->getTraceAsString()
            ));
            
            return $user; // W przypadku błędu pozwól na logowanie
        }
    }
    
    // Sprawdzanie i blokowanie IP
    private function check_and_block_ip($ip) {
        global $wpdb;
        
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT attempts FROM {$this->table_name} WHERE ip_address = %s",
            $ip
        ));
        
        $this->log_debug("Sprawdzanie blokady IP", array(
            'ip' => $ip,
            'attempts' => $attempts,
            'max_attempts' => $this->max_attempts,
            'should_block' => ($attempts >= $this->max_attempts)
        ));
        
        if ($attempts >= $this->max_attempts) {
            $block_until = date('Y-m-d H:i:s', current_time('timestamp') + $this->block_duration);
            
            $result = $wpdb->update(
                $this->table_name,
                array(
                    'is_blocked' => 1,
                    'block_until' => $block_until
                ),
                array('ip_address' => $ip)
            );
            
            $this->log_info("ZABLOKOWANO IP", array(
                'ip' => $ip,
                'attempts' => $attempts,
                'max_attempts' => $this->max_attempts,
                'block_until' => $block_until,
                'update_result' => $result
            ));
        }
    }

    private function send_block_notification($ip, $attempts, $block_until) {
        $email_class = new LoginBlocker_Email();
        $geolocation = $this->get_ip_geolocation($ip);
        
        $email_class->send_block_notification(
            $ip, 
            'unknown', // username nie jest dostępne w tym kontekście
            $attempts, 
            $block_until, 
            $geolocation
        );
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
        
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-analytics',
            'parent' => 'login-blocker',
            'title'  => 'Statystyki',
            'href'   => admin_url('admin.php?page=login-blocker-analytics'),
            'meta'   => array(
                'title' => 'Zaawansowane statystyki i raporty'
            )
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
        
        // Linki
        $admin_bar->add_menu(array(
            'id'     => 'login-blocker-manage',
            'parent' => 'login-blocker',
            'title'  => 'Zarządzaj blokadami',
            'href'   => admin_url('admin.php?page=login-blocker'),
            'meta'   => array()
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
                
                $this->log_info("IP odblokowane ręcznie", array(
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
        $log_files = $this->get_log_files();
        $current_log = isset($_GET['log_file']) ? sanitize_text_field($_GET['log_file']) : '';
        $log_content = '';
        
        if ($current_log && in_array($current_log, $log_files)) {
            $log_content = $this->get_log_content($current_log);
        }
        
        // Akcje
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'clear_logs' && check_admin_referer('login_blocker_debug')) {
                $this->clear_logs();
                echo '<div class="notice notice-success is-dismissible"><p>Logi zostały wyczyszczone.</p></div>';
            } elseif ($_POST['action'] === 'test_logging' && check_admin_referer('login_blocker_debug')) {
                $this->test_logging();
                echo '<div class="notice notice-success is-dismissible"><p>Test logowania wykonany. Sprawdź logi.</p></div>';
            }
        }
        /**
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'create_test_entry' && check_admin_referer('login_blocker_debug')) {
                $this->log_info("Testowy wpis utworzony ręcznie z panelu admina", array(
                'admin_user' => wp_get_current_user()->user_login,
                'timestamp' => current_time('mysql')
                ));
                echo '<div class="notice notice-success is-dismissible"><p>Testowy wpis został utworzony. Odśwież stronę aby zobaczyć zmiany.</p></div>';
            } elseif ($_POST['action'] === 'clear_logs' && check_admin_referer('login_blocker_debug')) {
                $this->clear_logs();
                echo '<div class="notice notice-success is-dismissible"><p>Logi zostały wyczyszczone.</p></div>';
            } elseif ($_POST['action'] === 'test_logging' && check_admin_referer('login_blocker_debug')) {
                $this->test_logging();
                echo '<div class="notice notice-success is-dismissible"><p>Test logowania wykonany. Sprawdź logi.</p></div>';
            }
        }
        */
        ?>
        <div class="wrap">
            <h1>Login Blocker - Debug & Logi</h1>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="card">
                    <h3>Status Systemu</h3>
                    <?php $this->display_system_status(); ?>
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

    // Pobieranie listy plików logów
    private function get_log_files() {
        $log_files = array();
        $log_dir = LOGIN_BLOCKER_LOG_PATH;
        
        if (file_exists($log_dir)) {
            $files = scandir($log_dir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                    $log_files[] = $file;
                }
            }
        }
        
        rsort($log_files); // Najnowsze na górze
        return $log_files;
    }

    // Pobieranie zawartości logu
    private function get_log_content($log_file) {
        $log_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
        
        if (file_exists($log_path)) {
            $content = file_get_contents($log_path);
            return $content ?: 'Plik jest pusty.';
        }
        
        return 'Plik nie istnieje.';
    }

    // Czyszczenie logów
    private function clear_logs() {
        $log_dir = LOGIN_BLOCKER_LOG_PATH;
        
        if (file_exists($log_dir)) {
            $files = glob($log_dir . '*.log');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        $this->log_info("Wszystkie logi zostały wyczyszczone ręcznie");
    }

    // Testowanie logowania
    private function test_logging() {
        $this->log_debug("Testowanie poziomu DEBUG", array('test_data' => array('foo' => 'bar')));
        $this->log_info("Testowanie poziomu INFO", array('test_data' => array('foo' => 'bar')));
        $this->log_warning("Testowanie poziomu WARNING", array('test_data' => array('foo' => 'bar')));
        $this->log_error("Testowanie poziomu ERROR", array('test_data' => array('foo' => 'bar')));
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
            <form id="export-form">
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
            <form id="export-stats-form">
                <?php wp_nonce_field('login_blocker_export_stats', 'stats_nonce'); ?>
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

    // Wyświetlanie statusu systemu
    private function display_system_status() {
        $log_dir_exists = file_exists(LOGIN_BLOCKER_LOG_PATH);
        $log_dir_writable = $log_dir_exists ? is_writable(LOGIN_BLOCKER_LOG_PATH) : false;
        
        // Sprawdź czy można tworzyć pliki w katalogu logów
        $can_create_files = false;
        if ($log_dir_exists && $log_dir_writable) {
            $test_file = LOGIN_BLOCKER_LOG_PATH . 'test-write.tmp';
            $test = @file_put_contents($test_file, 'test');
            if ($test !== false) {
                $can_create_files = true;
                @unlink($test_file);
            }
        }
        
        $smtp_enabled = get_option('login_blocker_smtp_enabled', false);
        $notification_email = get_option('login_blocker_notification_email', get_option('admin_email'));
        
        $status['Email Notifications'] = array(
            'value' => get_option('login_blocker_error_notifications', true) ? '✅ Włączone' : '❌ Wyłączone',
            'description' => get_option('login_blocker_error_notifications', true) ? 
                'Powiadomienia wysyłane na: ' . $notification_email : 
                'Powiadomienia email są wyłączone'
        );
        
        $status['SMTP Configuration'] = array(
            'value' => $smtp_enabled ? '✅ Własny SMTP' : '🔄 Domyślny WordPress',
            'description' => $smtp_enabled ? 
                'Używany serwer SMTP: ' . get_option('login_blocker_smtp_host') : 
                'Używany domyślny system WordPress'
        );
        
        $status = array(
            'Debug Mode' => array(
                'value' => $this->debug_mode ? '✅ Włączony' : '❌ Wyłączony',
                'description' => $this->debug_mode ? 
                    'Logowanie szczegółowych informacji' : 
                    'Tylko błędy i ostrzeżenia są logowane'
            ),
            'Log Files Size' => array(
                'value' => $this->get_log_files_size(),
                'description' => 'Łączny rozmiar wszystkich plików logów'
            ),
            'Log Directory' => array(
                'value' => $log_dir_exists ? 
                    ($log_dir_writable ? '✅ Dostępny i zapisywalny' : '⚠️ Dostępny, ale nie zapisywalny') : 
                    '❌ Niedostępny',
                'description' => $log_dir_exists ? 
                    'Katalog: ' . LOGIN_BLOCKER_LOG_PATH : 
                    'Nie udało się utworzyć katalogu logów'
            ),
            'File Creation' => array(
                'value' => $can_create_files ? '✅ Możliwe' : '❌ Niemożliwe',
                'description' => $can_create_files ? 
                    'Można tworzyć pliki w katalogu logów' : 
                    'Brak uprawnień do tworzenia plików'
            ),
            'WP_DEBUG' => array(
                'value' => defined('WP_DEBUG') && WP_DEBUG ? '✅ Włączony' : '❌ Wyłączony',
                'description' => 'Globalny tryb debugowania WordPress'
            ),
            'WP_DEBUG_LOG' => array(
                'value' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '✅ Włączony' : '❌ Wyłączony',
                'description' => 'Logowanie błędów WordPress do wp-content/debug.log'
            ),
            'Error Notifications' => array(
                'value' => get_option('login_blocker_error_notifications', true) ? '✅ Włączone' : '❌ Wyłączone',
                'description' => get_option('login_blocker_error_notifications', true) ? 
                    'Powiadomienia email będą wysyłane przy krytycznych błędach' : 
                    'Powiadomienia email są wyłączone'
            ),
            'Last Log Entry' => array(
                'value' => $this->get_last_log_entry_info(),
                'description' => 'Ostatni wpis w logach'
            )
        );
        
        echo '<table class="widefat striped">';
        foreach ($status as $key => $data) {
            echo '<tr>';
            echo '<td style="width: 200px;"><strong>' . $key . '</strong></td>';
            echo '<td>' . $data['value'] . '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td colspan="2" style="font-size: 12px; color: #666; padding-top: 0;">' . $data['description'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        // Dodaj sugestie jeśli są problemy
        if (!$log_dir_exists || !$log_dir_writable || !$can_create_files) {
            echo '<div class="notice notice-warning" style="margin-top: 15px;">';
            echo '<h4>⚠️ Problemy z systemem logów</h4>';
            echo '<p>Występują problemy z zapisywaniem logów. Sprawdź uprawnienia do katalogu:</p>';
            echo '<code>' . LOGIN_BLOCKER_LOG_PATH . '</code>';
            echo '<p>Uprawnienia powinny być ustawione na 755 lub 775.</p>';
            echo '</div>';
        }
    }

    private function get_log_files_size() {
        $log_files = $this->get_log_files();
        $total_size = 0;
        
        foreach ($log_files as $log_file) {
            $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
            if (file_exists($file_path)) {
                $total_size += filesize($file_path);
            }
        }
        
        if ($total_size === 0) {
            return '0 KB (brak plików)';
        }
        
        if ($total_size < 1024) {
            return $total_size . ' B';
        } elseif ($total_size < 1048576) {
            return round($total_size / 1024, 2) . ' KB';
        } else {
            return round($total_size / 1048576, 2) . ' MB';
        }
    }

    // Pobieranie informacji o ostatnim wpisie w logu
    private function get_last_log_entry_info() {
        $log_files = $this->get_log_files();
        
        if (empty($log_files)) {
            // Spróbuj utworzyć testowy wpis
            $this->log_info("Testowy wpis - sprawdzanie systemu logów");
            
            // Ponownie sprawdź pliki
            $log_files = $this->get_log_files();
            
            if (empty($log_files)) {
                return '❌ Brak plików logów (system nie zapisuje)';
            }
        }
        
        $latest_file = LOGIN_BLOCKER_LOG_PATH . $log_files[0];
        
        if (!file_exists($latest_file)) {
            return '❌ Plik logów nie istnieje';
        }
        
        $file_size = filesize($latest_file);
        
        if ($file_size === 0) {
            return '📝 Plik pusty (0 bajtów)';
        }
        
        // Pobierz ostatnią linię - bardziej niezawodna wersja
        $content = file_get_contents($latest_file);
        if ($content === false) {
            return '❌ Nie można odczytać pliku';
        }
        
        $lines = explode("\n", $content);
        $lines = array_filter($lines); // Usuń puste linie
        
        if (empty($lines)) {
            return '📝 Brak wpisów w pliku';
        }
        
        $last_line = end($lines);
        
        // Wyciągnij timestamp z ostatniej linii
        if (preg_match('/\[([^\]]+)\]/', $last_line, $matches)) {
            $timestamp = $matches[1];
            $file_size_kb = round($file_size / 1024, 2);
            return "✅ Ostatni wpis: {$timestamp} (" . $file_size_kb . " KB)";
        }
        
        return "✅ Zapisano (" . count($lines) . " wpisów, " . round($file_size / 1024, 2) . " KB)";
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
        
        // Skrypt AJAX do testowania email
        echo '';
    }
    
    // Nowa funkcja do wysyłania emaili z obsługą SMTP
    private function send_email($to, $subject, $message) {
        $smtp_enabled = get_option('login_blocker_smtp_enabled', false);
        
        if ($smtp_enabled) {
            return $this->send_email_via_smtp($to, $subject, $message);
        } else {
            return $this->send_email_via_wp($to, $subject, $message);
        }
    }

    // Wysyłanie przez domyślny system WordPress
    private function send_email_via_wp($to, $subject, $message) {
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        return wp_mail($to, $subject, $message, $headers);
    }

    // Wysyłanie przez SMTP
        // Wysyłanie przez SMTP
    private function send_email_via_smtp($to, $subject, $message) {
        // WALIDACJA DANYCH WEJŚCIOWYCH
        $to = sanitize_email($to);
        $subject = sanitize_text_field($subject);
        $message = wp_kses_post($message); // Bezpieczne czyszczenie HTML

        if (!is_email($to)) {
            $this->log_error("Nieprawidłowy adres email w SMTP", array('to' => $to));
            return false;
        }

        $smtp_host = get_option('login_blocker_smtp_host');
        $smtp_port = get_option('login_blocker_smtp_port', 587);
        $smtp_username = get_option('login_blocker_smtp_username');
        $smtp_password = get_option('login_blocker_smtp_password');
        $smtp_encryption = get_option('login_blocker_smtp_encryption', 'tls');
        
        if (empty($smtp_host) || empty($smtp_username)) {
            $this->log_error("Niekompletna konfiguracja SMTP", array(
                'host' => $smtp_host,
                'username' => $smtp_username
            ));
            return false;
        }
        
        // Użyj PHPMailer jeśli dostępny
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Konfiguracja SMTP
            $mail->isSMTP();
            $mail->Host = sanitize_text_field($smtp_host);
            $mail->Port = intval($smtp_port);
            $mail->SMTPAuth = true;
            $mail->Username = sanitize_text_field($smtp_username);
            $mail->Password = $smtp_password;
            
            // Szyfrowanie
            if ($smtp_encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtp_encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Opcje
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(sanitize_email($smtp_username), sanitize_text_field(get_bloginfo('name')));
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->isHTML(false);
            
            $result = $mail->send();
            
            if ($result) {
                $this->log_debug("Email wysłany pomyślnie przez SMTP", array(
                    'to' => $to,
                    'subject' => $subject
                ));
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log_error("Błąd wysyłania email przez SMTP", array(
                'error' => $e->getMessage(),
                'to' => $to,
                'subject' => $subject
            ));
            return false;
        }
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
                
                $this->log_info("IP odblokowane ręcznie", array(
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
                <h2>Ostatnie próby logowania (ostatnie 100) - 123</h2>
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
        	<?php $this->display_update_status(); ?>
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

    private function display_update_status() {
    if (class_exists('LoginBlocker_Updater')) {
        $updater = new LoginBlocker_Updater(__FILE__);
        $status = $updater->get_update_status();
        
        echo '<p><strong>Obecna wersja:</strong> ' . LOGIN_BLOCKER_VERSION . '</p>';
        
        if ($status['status'] === 'update_available') {
            echo '<p style="color: #d63638;"><strong>⚠️ Dostępna aktualizacja:</strong> ' . $status['latest'] . '</p>';
            echo '<p><a href="' . admin_url('update-core.php') . '" class="button button-primary">Aktualizuj</a></p>';
        } elseif ($status['status'] === 'up_to_date') {
            echo '<p style="color: #00a32a;"><strong>✅ Wtyczka jest aktualna</strong></p>';
        } else {
            echo '<p style="color: #d63638;"><strong>❌ Błąd sprawdzania aktualizacji</strong></p>';
        }
    } else {
        echo '<p style="color: #d63638;"><strong>❌ System aktualizacji nie jest dostępny</strong></p>';
    }
    
    echo '<p><a href="' . admin_url('update-core.php?force-check=1') . '" class="button">Sprawdź ręcznie</a></p>';
}
    
    // Funkcja do wyświetlania komunikatu
    public function display_block_message() {
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_SESSION['login_blocker_message'])) {
            echo '<style>.login-blocker-alert { background: #ffeaa7; border: 1px solid #fdcb6e; padding: 15px; margin: 20px 0; border-radius: 5px; color: #2d3436; }</style>';
            echo '<div class="login-blocker-alert">' . esc_html($_SESSION['login_blocker_message']) . '</div>';
            unset($_SESSION['login_blocker_message']);
        }
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
                <?php $this->display_stats_cards($period); ?>
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
                        <?php $this->display_attempts_chart($period); ?>
                    </div>
                    
                    <div class="card">
                        <h3>Rozkład geograficzny</h3>
                        <?php $this->display_country_stats($period); ?>
                    </div>
                </div>
            </div>
            
            <!-- DOLNA SEKCJA - PŁYWAJĄCE -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="card">
                    <h3>Najczęściej atakowani użytkownicy</h3>
                    <?php $this->display_top_users($period); ?>
                </div>
                
                <div class="card">
                    <h3>Najaktywniejsze adresy IP</h3>
                    <?php $this->display_top_ips($period); ?>
                </div>
            </div>
            
            <!-- MAPA - PEŁNA SZEROKOŚĆ -->
            <div class="card">
                <h3>Mapa ataków</h3>
                <?php $this->display_attack_map($period); ?>
            </div>
        </div>

        <?php
    }
    
    // Karty ze statystykami
    private function display_stats_cards($period) {
    global $wpdb;
    
    $start_date = date('Y-m-d', strtotime("-$period days"));
    
    // ZABEZPIECZONE ZAPYTANIA Z PREPARE
    $stats = array(
        'total_attempts' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE last_attempt >= %s",
            $start_date
        )),
        'blocked_attempts' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1 AND last_attempt >= %s",
            $start_date
        )),
        'unique_ips' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE last_attempt >= %s",
            $start_date
        )),
        'unique_countries' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT country_code) FROM {$this->table_name} WHERE country_code != '' AND last_attempt >= %s",
            $start_date
        )),
        'currently_blocked' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1"),
        'avg_attempts_per_ip' => $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(attempts) FROM {$this->table_name} WHERE last_attempt >= %s",
            $start_date
        ))
    );
    
    $cards = array(
        array(
            'title' => 'Wszystkie próby',
            'value' => number_format($stats['total_attempts']),
            'color' => '#0073aa'
        ),
        array(
            'title' => 'Zablokowane próby',
            'value' => number_format($stats['blocked_attempts']),
            'color' => '#d63638'
        ),
        array(
            'title' => 'Unikalne IP',
            'value' => number_format($stats['unique_ips']),
            'color' => '#00a32a'
        ),
        array(
            'title' => 'Kraje',
            'value' => number_format($stats['unique_countries']),
            'color' => '#dba617'
        ),
        array(
            'title' => 'Obecnie zablokowane',
            'value' => number_format($stats['currently_blocked']),
            'color' => '#ca4a1f'
        ),
        array(
            'title' => 'Średnio prób na IP',
            'value' => number_format($stats['avg_attempts_per_ip'], 1),
            'color' => '#8e35c9'
        )
    );
    
    foreach ($cards as $card) {
        echo '
        <div class="stats-card">
            <h4>' . $card['title'] . '</h4>
            <div class="stats-number" style="color: ' . $card['color'] . '">' . $card['value'] . '</div>
        </div>';
    }
}

    // Wykres prób logowania w czasie
    private function display_attempts_chart($period) {
    global $wpdb;
    
    $start_date = date('Y-m-d', strtotime("-$period days"));
    
    // ZABEZPIECZONE ZAPYTANIE
    $daily_stats = $wpdb->get_results($wpdb->prepare("
        SELECT DATE(last_attempt) as date, 
               COUNT(*) as attempts,
               COUNT(DISTINCT ip_address) as unique_ips,
               SUM(is_blocked) as blocked
        FROM {$this->table_name} 
        WHERE last_attempt >= %s
        GROUP BY DATE(last_attempt)
        ORDER BY date ASC
    ", $start_date));
    
    if (empty($daily_stats)) {
        echo '<p>Brak danych dla wybranego okresu.</p>';
        return;
    }
    
    $dates = array();
    $attempts = array();
    $blocked = array();
    $unique_ips = array();
    
    foreach ($daily_stats as $stat) {
        $dates[] = date('d.m', strtotime($stat->date));
        $attempts[] = $stat->attempts;
        $blocked[] = $stat->blocked;
        $unique_ips[] = $stat->unique_ips;
    }
    
    echo '
    <canvas id="attemptsChart" width="400" height="200"></canvas>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var ctx = document.getElementById("attemptsChart").getContext("2d");
        var chart = new Chart(ctx, {
            type: "line",
            data: {
                labels: ' . json_encode($dates) . ',
                datasets: [
                    {
                        label: "Wszystkie próby",
                        data: ' . json_encode($attempts) . ',
                        borderColor: "#0073aa",
                        backgroundColor: "rgba(0, 115, 170, 0.1)",
                        tension: 0.4
                    },
                    {
                        label: "Zablokowane",
                        data: ' . json_encode($blocked) . ',
                        borderColor: "#d63638",
                        backgroundColor: "rgba(214, 54, 56, 0.1)",
                        tension: 0.4
                    },
                    {
                        label: "Unikalne IP",
                        data: ' . json_encode($unique_ips) . ',
                        borderColor: "#00a32a",
                        backgroundColor: "rgba(0, 163, 42, 0.1)",
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: "top"
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    });
    </script>';
}

    // Statystyki krajów - kompaktowa wersja
    private function display_country_stats($period) {
    global $wpdb;
    
    $start_date = date('Y-m-d', strtotime("-$period days"));
    
    // ZABEZPIECZONE ZAPYTANIE
    $country_stats = $wpdb->get_results($wpdb->prepare("
        SELECT country_code, country_name, 
               COUNT(*) as attempts,
               COUNT(DISTINCT ip_address) as unique_ips
        FROM {$this->table_name} 
        WHERE country_code != '' AND last_attempt >= %s
        GROUP BY country_code, country_name
        ORDER BY attempts DESC
        LIMIT 8
    ", $start_date));
    
    if (empty($country_stats)) {
        echo '<p>Brak danych geolokalizacji.</p>';
        return;
    }
    
    echo '<table class="wp-list-table widefat fixed striped" style="font-size: 12px;">';
    echo '<thead><tr><th>Kraj</th><th>Próby</th><th>IP</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($country_stats as $country) {
        $country_code_lower = strtolower($country->country_code);
        $flag_url = "https://flagcdn.com/16x12/{$country_code_lower}.png";
        
        echo '<tr>';
        echo '<td><img src="' . esc_url($flag_url) . '" style="width: 16px; height: 12px; margin-right: 5px;" alt="' . esc_attr($country->country_code) . '"> ' . esc_html($country->country_name) . '</td>';
        echo '<td>' . number_format($country->attempts) . '</td>';
        echo '<td>' . number_format($country->unique_ips) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
}

    // Najczęściej atakowani użytkownicy - kompaktowa
    private function display_top_users($period) {
    global $wpdb;
    
    $start_date = date('Y-m-d', strtotime("-$period days"));
    
    // ZABEZPIECZONE ZAPYTANIE
    $top_users = $wpdb->get_results($wpdb->prepare("
        SELECT username, 
               COUNT(*) as attempts,
               COUNT(DISTINCT ip_address) as unique_attackers
        FROM {$this->table_name} 
        WHERE username IS NOT NULL AND last_attempt >= %s
        GROUP BY username 
        ORDER BY attempts DESC 
        LIMIT 8
    ", $start_date));
    
    if (empty($top_users)) {
        echo '<p>Brak danych.</p>';
        return;
    }
    
    echo '<table class="wp-list-table widefat fixed striped" style="font-size: 12px;">';
    echo '<thead><tr><th>Użytkownik</th><th>Próby</th><th>Atakujący</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($top_users as $user) {
        echo '<tr>';
        echo '<td>' . esc_html($user->username) . '</td>';
        echo '<td>' . number_format($user->attempts) . '</td>';
        echo '<td>' . number_format($user->unique_attackers) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
}

    // Najaktywniejsze IP - kompaktowa
    private function display_top_ips($period) {
    global $wpdb;
    
    $start_date = date('Y-m-d', strtotime("-$period days"));
    
    // ZABEZPIECZONE ZAPYTANIE
    $top_ips = $wpdb->get_results($wpdb->prepare("
        SELECT ip_address, country_code, city, 
               COUNT(*) as attempts,
               MAX(is_blocked) as is_blocked
        FROM {$this->table_name} 
        WHERE last_attempt >= %s
        GROUP BY ip_address, country_code, city
        ORDER BY attempts DESC 
        LIMIT 8
    ", $start_date));
    
    if (empty($top_ips)) {
        echo '<p>Brak danych.</p>';
        return;
    }
    
    echo '<table class="wp-list-table widefat fixed striped" style="font-size: 12px;">';
    echo '<thead><tr><th>IP</th><th>Próby</th><th>Status</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($top_ips as $ip) {
        $status = $ip->is_blocked ? '<span style="color: red; font-size: 11px;">BLOKADA</span>' : '<span style="color: green; font-size: 11px;">aktywny</span>';
        
        echo '<tr>';
        echo '<td style="font-family: monospace; font-size: 11px;">' . esc_html($ip->ip_address) . '</td>';
        echo '<td>' . number_format($ip->attempts) . '</td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
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

    // Mapa ataków
    private function display_attack_map($period) {
    global $wpdb;
    
    $start_date = date('Y-m-d', strtotime("-$period days"));
    
    // ZABEZPIECZONE ZAPYTANIE
    $attack_locations = $wpdb->get_results($wpdb->prepare("
        SELECT country_code, country_name, city, latitude, longitude,
               COUNT(*) as attempts,
               COUNT(DISTINCT ip_address) as unique_ips
        FROM {$this->table_name} 
        WHERE country_code != '' AND latitude IS NOT NULL AND longitude IS NOT NULL AND last_attempt >= %s
        GROUP BY country_code, country_name, city, latitude, longitude
        ORDER BY attempts DESC
        LIMIT 50
    ", $start_date));
    
    if (empty($attack_locations)) {
        echo '<p>Brak danych geolokalizacji do wyświetlenia mapy.</p>';
        return;
    }
    
    $locations_data = array();
    foreach ($attack_locations as $location) {
        $locations_data[] = array(
            'lat' => floatval($location->latitude),
            'lng' => floatval($location->longitude),
            'country' => $location->country_name,
            'city' => $location->city,
            'attempts' => $location->attempts,
            'unique_ips' => $location->unique_ips
        );
    }
    
    echo '
    <div id="attackMap" style="height: 400px; width: 100%;"></div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var map = L.map("attackMap").setView([20, 0], 2);
        
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "© OpenStreetMap contributors"
        }).addTo(map);
        
        var locations = ' . wp_json_encode($locations_data) . ';
        
        locations.forEach(function(location) {
            var popupContent = "<strong>" + location.city + ", " + location.country + "</strong><br>" +
                              "Próby: " + location.attempts + "<br>" +
                              "Unikalne IP: " + location.unique_ips;
            
            var color = location.attempts > 100 ? "red" : 
                       location.attempts > 50 ? "orange" : "green";
            
            L.circleMarker([location.lat, location.lng], {
                color: color,
                fillColor: color,
                fillOpacity: 0.5,
                radius: Math.min(location.attempts / 10, 20)
            }).addTo(map).bindPopup(popupContent);
        });
    });
    </script>
    <link rel="stylesheet" href="' . esc_url('https://unpkg.com/leaflet@1.7.1/dist/leaflet.css') . '" />
    <script src="' . esc_url('https://unpkg.com/leaflet@1.7.1/dist/leaflet.js') . '"></script>';
}

/**
 * Inicjalizacja systemu aktualizacji
 */
public function init_updater() {
    if (file_exists(plugin_dir_path(__FILE__) . 'includes/class-updater.php')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-updater.php';
        
        // Sprawdź czy klasa już nie istnieje (zabezpieczenie przed duplikacją)
        if (!class_exists('LoginBlocker_Updater_Initialized')) {
            LoginBlocker_Updater_Initialized::init(__FILE__);
        }
    }
}
    
    // Tworzenie katalogu logów
    private function create_log_directory() {
        $log_dir = LOGIN_BLOCKER_LOG_PATH;
        
        if (!file_exists($log_dir)) {
            $created = wp_mkdir_p($log_dir);
            
            if (!$created) {
                // Log do WordPress debug.log jeśli tworzenie katalogu się nie uda
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Login Blocker: Nie udało się utworzyć katalogu logów: ' . $log_dir);
                }
                return false;
            }
            
            // Zabezpieczenie katalogu
            $htaccess_file = $log_dir . '.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, "Deny from all\n");
            }
            
            $index_file = $log_dir . 'index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, "<?php\n// Silence is golden\n");
            }
            
            return true;
        }
        
        return true;
    }

    // Główna funkcja logowania
    private function log_message($level, $message, $context = array()) {
        // ZAWSZE loguj błędy i ostrzeżenia, nawet gdy debug mode jest wyłączony
        $should_log = ($this->debug_mode) || in_array($level, ['ERROR', 'WARNING', 'CRITICAL']);
        
        if (!$should_log) {
            return;
        }
        
        $timestamp = current_time('mysql');
        $log_entry = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ''
        );
        
        // Log do pliku (spróbuj, ale nie crashuj jeśli się nie uda)
        $file_logged = $this->log_to_file($level, $log_entry);
        
        // Log do WordPress debug.log jeśli jest włączony
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("Login Blocker {$level}: {$message}");
        }
        
        // Powiadomienia admina dla krytycznych błędów (TYLKO jeśli katalog logów istnieje lub WP_DEBUG_LOG jest włączony)
        if (($level === 'ERROR' || $level === 'CRITICAL') && ($file_logged || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG))) {
            $this->notify_admin_on_error($level, $message, $context);
        }
    }

    // Zmień funkcję log_to_file() żeby zwracała status
    private function log_to_file($level, $log_entry) {
        $log_file = LOGIN_BLOCKER_LOG_PATH . 'login-blocker-' . date('Y-m-d') . '.log';
        
        // Sprawdź czy katalog istnieje, jeśli nie - spróbuj go utworzyć
        if (!file_exists(LOGIN_BLOCKER_LOG_PATH)) {
            $dir_created = $this->create_log_directory();
            if (!$dir_created) {
                $this->log_fallback("Nie udało się utworzyć katalogu logów: " . LOGIN_BLOCKER_LOG_PATH);
                return false;
            }
        }
        
        // Sprawdź uprawnienia do zapisu
        if (!is_writable(LOGIN_BLOCKER_LOG_PATH)) {
            $this->log_fallback("Katalog logów nie jest zapisywalny: " . LOGIN_BLOCKER_LOG_PATH);
            return false;
        }
        
        try {
            $result = file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            
            if ($result === false) {
                $this->log_fallback("Nie udało się zapisać do pliku logów: " . $log_file);
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log_fallback("Wyjątek podczas zapisu do logów: " . $e->getMessage());
            return false;
        }
    }

    // Fallback logowanie gdy główny system nie działa
    private function log_fallback($message) {
        // Spróbuj użyć WP_DEBUG_LOG
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Login Blocker LOG ERROR: " . $message);
        }
        
        // Spróbuj wysłać email do admina (tylko raz dziennie aby nie spamować)
        $last_notification = get_transient('login_blocker_log_error_notification');
        if (!$last_notification) {
            $this->notify_admin_on_error(
                'ERROR',
                'Problem z systemem logów',
                array('message' => $message)
            );
            set_transient('login_blocker_log_error_notification', true, HOUR_IN_SECONDS * 6); // Co 6 godzin
        }
    }

    // Powiadomienia admina o błędach
    private function notify_admin_on_error($level, $message, $context = array()) {
        $notifications_enabled = get_option('login_blocker_error_notifications', true);
        
        if (!$notifications_enabled) {
            return;
        }
        
        $to = get_option('login_blocker_notification_email', get_option('admin_email'));
        
        if (empty($to)) {
            $this->log_warning("Brak adresu email dla powiadomień");
            return;
        }
        
        $subject = "🚨 Login Blocker {$level} - " . get_bloginfo('name');
        
        $email_message = "
Wystąpił błąd w pluginie Login Blocker:

Poziom: {$level}
Wiadomość: {$message}
Czas: " . current_time('mysql') . "
Strona: " . get_bloginfo('url') . "

Kontekst:
" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

---
To jest automatyczna wiadomość z systemu Login Blocker.
";
        
        $result = $this->send_email($to, $subject, $email_message);
        
        if (!$result) {
            $this->log_error("Nie udało się wysłać powiadomienia email", array(
                'to' => $to,
                'subject' => $subject
            ));
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
        
        $result = $this->send_email($to, $subject, $message);
        
        if ($result) {
            wp_send_json_success('Testowy email został wysłany pomyślnie! Sprawdź skrzynkę odbiorczą.');
        } else {
            wp_send_json_error('Nie udało się wysłać testowego emaila. Sprawdź konfigurację.');
        }
    }

    // Helper functions dla różnych poziomów logów
    private function log_error($message, $context = array()) {
        $this->log_message('ERROR', $message, $context);
    }

    private function log_warning($message, $context = array()) {
        $this->log_message('WARNING', $message, $context);
    }

    private function log_info($message, $context = array()) {
        $this->log_message('INFO', $message, $context);
    }

    private function log_debug($message, $context = array()) {
        $this->log_message('DEBUG', $message, $context);
    }
}

add_action('init', 'login_blocker_handle_export_requests');
function login_blocker_handle_export_requests() {
    // Sprawdź czy to żądanie eksportu
    if (!isset($_GET['login_blocker_export']) || !isset($_GET['_wpnonce'])) {
        return;
    }
    
    // Sprawdź uprawnienia
    if (!current_user_can('export')) {
        wp_die('Brak uprawnień do eksportu');
    }
    
    // Sprawdź nonce
    if (!wp_verify_nonce($_GET['_wpnonce'], 'login_blocker_export')) {
        wp_die('Błąd bezpieczeństwa');
    }
    
    // Pobierz parametry
    $format = sanitize_text_field($_GET['format'] ?? 'csv');
    $period = intval($_GET['period'] ?? 30);
    $type = sanitize_text_field($_GET['type'] ?? 'data'); // data lub stats
    
    // Załaduj klasę eksportera
    require_once plugin_dir_path(__FILE__) . 'includes/class-exporter.php';
    $exporter = new LoginBlocker_Exporter();
    
    // Wykonaj eksport
    if ($type === 'stats') {
        $result = $exporter->export_stats($period);
    } else {
        $result = $exporter->export($format, $period);
    }
    
    // Jeśli eksport się nie udał
    if (!$result) {
        wp_die('Eksport nie powiódł się. Sprawdź logi błędów.');
    }
    
    exit;
}

// Inicjalizacja wtyczki
new LoginBlocker();

// Hook aktywacji
register_activation_hook(__FILE__, 'login_blocker_activate');
function login_blocker_activate() {
    // Testowanie systemu logów przy aktywacji
    $login_blocker = new LoginBlocker();
    $login_blocker->test_log_system_on_activation();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Hook deaktywacji
register_deactivation_hook(__FILE__, 'login_blocker_deactivate');
function login_blocker_deactivate() {
    // Usuwanie cron job
    wp_clear_scheduled_hook('login_blocker_cleanup');
    flush_rewrite_rules();
}
