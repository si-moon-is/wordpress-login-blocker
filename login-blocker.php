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

// Klasa główna wtyczki
class LoginBlocker {
    
    protected $table_name;
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

        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Rejestracja hooków
        add_action('plugins_loaded', array($this, 'init'));
        add_action('wp_login_failed', array($this, 'handle_failed_login'));
        add_filter('authenticate', array($this, 'check_ip_blocked'), 30, 3);
        
        // Inicjalizacja admina
        require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';
        new LoginBlocker_Admin($this);
        
        // Cron do czyszczenia starych rekordów
        add_action('login_blocker_cleanup', array($this, 'cleanup_old_records'));
        add_action('init', array($this, 'schedule_cleanup'));
        
        // Debug i logi
        add_action('init', array($this, 'init_debug_system'));

        add_action('admin_init', 'login_blocker_handle_export_requests');
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
        $delete_before = date('Y-m-d H:i:s', current_time('timestamp') - (30 * 24 * 60 * 60));
        
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
                $current_time = current_time('timestamp');
                
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
            'unknown',
            $attempts, 
            $block_until, 
            $geolocation
        );
    }

    // Nowa funkcja do wysyłania emaili z obsługą SMTP
    public function send_email($to, $subject, $message) {
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
    private function send_email_via_smtp($to, $subject, $message) {
        // WALIDACJA DANYCH WEJŚCIOWYCH
        $to = sanitize_email($to);
        $subject = sanitize_text_field($subject);
        $message = wp_kses_post($message);

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

    // Helper functions dla różnych poziomów logów
    public function log_error($message, $context = array()) {
        $this->log_message('ERROR', $message, $context);
    }

    public function log_warning($message, $context = array()) {
        $this->log_message('WARNING', $message, $context);
    }

    public function log_info($message, $context = array()) {
        $this->log_message('INFO', $message, $context);
    }

    public function log_debug($message, $context = array()) {
        $this->log_message('DEBUG', $message, $context);
    }

    // Pobieranie listy plików logów
    public function get_log_files() {
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
    public function get_log_content($log_file) {
        $log_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
        
        if (file_exists($log_path)) {
            $content = file_get_contents($log_path);
            return $content ?: 'Plik jest pusty.';
        }
        
        return 'Plik nie istnieje.';
    }

    // Czyszczenie logów
    public function clear_logs() {
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
    public function test_logging() {
        $this->log_debug("Testowanie poziomu DEBUG", array('test_data' => array('foo' => 'bar')));
        $this->log_info("Testowanie poziomu INFO", array('test_data' => array('foo' => 'bar')));
        $this->log_warning("Testowanie poziomu WARNING", array('test_data' => array('foo' => 'bar')));
        $this->log_error("Testowanie poziomu ERROR", array('test_data' => array('foo' => 'bar')));
    }

    // Wyświetlanie statusu systemu
    public function display_system_status() {
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
    
    // Karty ze statystykami
    public function display_stats_cards($period) {
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
    public function display_attempts_chart($period) {
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
    public function display_country_stats($period) {
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
    public function display_top_users($period) {
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
    public function display_top_ips($period) {
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

    // Mapa ataków
    public function display_attack_map($period) {
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
}

function login_blocker_handle_export_requests() {
    // Sprawdź czy to żądanie eksportu z formularza
    if (!isset($_POST['login_blocker_export']) && !isset($_GET['login_blocker_export'])) {
        return;
    }
    
    // Sprawdź nonce - obsłuż zarówno POST jak i GET
    $nonce = $_POST['export_nonce'] ?? ($_GET['export_nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'login_blocker_export')) {
        wp_die('Błąd bezpieczeństwa: Nieprawidłowy nonce');
    }
    
    // Sprawdź uprawnienia
    if (!current_user_can('export')) {
        wp_die('Brak uprawnień do eksportu');
    }
    
    // Pobierz parametry
    $format = sanitize_text_field($_POST['format'] ?? ($_GET['format'] ?? 'csv'));
    $period = intval($_POST['period'] ?? ($_GET['period'] ?? 30));
    $type = sanitize_text_field($_POST['type'] ?? ($_GET['type'] ?? 'data'));
    
    // Załaduj klasę eksportera
    require_once plugin_dir_path(__FILE__) . 'includes/class-exporter.php';
    $exporter = new LoginBlocker_Exporter();
    
    // Wykonaj eksport
    if ($type === 'stats') {
        $result = $exporter->export_stats($period);
    } else {
        $result = $exporter->export($format, $period);
    }
    
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
