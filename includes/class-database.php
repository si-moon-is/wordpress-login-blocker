<?php
/**
 * Login Blocker - Database Class
 * Handles all database operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Database {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'login_blocker_attempts';
    }
    
    /**
     * Tworzenie tabeli w bazie danych
     */
    public function create_table() {
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
    
    /**
     * Sprawdza czy IP jest zablokowane
     */
    public function is_ip_blocked($ip) {
        global $wpdb;
        
        $blocked = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE ip_address = %s AND is_blocked = 1 AND block_until > %s",
            $ip,
            current_time('mysql')
        ));
        
        return !empty($blocked);
    }
    
    /**
     * Pobiera istniejący rekord IP
     */
    public function get_ip_record($ip) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE ip_address = %s",
            $ip
        ));
    }
    
    /**
     * Aktualizuje istniejący rekord IP
     */
    public function update_ip_record($id, $data) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            $data,
            array('id' => $id)
        );
    }
    
    /**
     * Tworzy nowy rekord IP
     */
    public function insert_ip_record($data) {
        global $wpdb;
        
        return $wpdb->insert(
            $this->table_name,
            $data,
            array('%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f')
        );
    }
    
    /**
     * Pobiera liczbę prób dla IP
     */
    public function get_ip_attempts($ip) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT attempts FROM {$this->table_name} WHERE ip_address = %s",
            $ip
        ));
    }
    
    /**
     * Blokuje IP
     */
    public function block_ip($ip, $block_until) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array(
                'is_blocked' => 1,
                'block_until' => $block_until
            ),
            array('ip_address' => $ip)
        );
    }
    
    /**
     * Odblokowuje IP
     */
    public function unblock_ip($ip) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array('is_blocked' => 0, 'attempts' => 0, 'block_until' => null),
            array('ip_address' => $ip)
        );
    }
    
    /**
     * Usuwa rekord IP
     */
    public function delete_ip_record($ip) {
        global $wpdb;
        
        return $wpdb->delete($this->table_name, array('ip_address' => $ip));
    }
    
    /**
     * Pobiera zablokowane IP z paginacją
     */
    public function get_blocked_ips($per_page = 50, $offset = 0, $search = '') {
        global $wpdb;
        
        $search_sql = '';
        if (!empty($search)) {
            $search_sql = $wpdb->prepare(" AND ip_address LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        }
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$this->table_name} 
            WHERE is_blocked = 1 {$search_sql}
            ORDER BY last_attempt DESC 
            LIMIT %d, %d
        ", $offset, $per_page));
    }
    
    /**
     * Liczba zablokowanych IP
     */
    public function count_blocked_ips($search = '') {
        global $wpdb;
        
        $search_sql = '';
        if (!empty($search)) {
            $search_sql = $wpdb->prepare(" AND ip_address LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        }
        
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1 {$search_sql}"
        );
    }
    
    // =========================================================================
    // NOWE METODY DO PAGINACJI - DODANE PONIŻEJ
    // =========================================================================
    
    /**
     * Pobiera próby logowania z paginacją
     */
    public function get_attempts($limit = 50, $offset = 0, $search = '') {
        global $wpdb;
        
        $search_sql = '';
        if (!empty($search)) {
            $search_sql = $wpdb->prepare(" AND (ip_address LIKE %s OR username LIKE %s)", 
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$this->table_name} 
            WHERE 1=1 {$search_sql}
            ORDER BY last_attempt DESC 
            LIMIT %d OFFSET %d
        ", $limit, $offset));
    }
    
    /**
     * Liczba wszystkich prób (dla paginacji)
     */
    public function count_attempts($search = '') {
        global $wpdb;
        
        $search_sql = '';
        if (!empty($search)) {
            $search_sql = $wpdb->prepare(" AND (ip_address LIKE %s OR username LIKE %s)", 
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        
        return $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->table_name} 
            WHERE 1=1 {$search_sql}
        ");
    }
    
    // =========================================================================
    // ISTNIEJĄCE METODY - POZOSTAJĄ BEZ ZMIAN
    // =========================================================================
    
    /**
     * Pobiera ostatnie próby logowania
     */
    public function get_recent_attempts($limit = 100) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$this->table_name} 
            ORDER BY last_attempt DESC 
            LIMIT %d
        ", $limit));
    }
    
    /**
     * Pobiera wszystkie próby
     */
    public function get_all_attempts() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT * FROM {$this->table_name} ORDER BY last_attempt DESC
        ");
    }
    
    /**
     * Pobiera zablokowane IP
     */
    public function get_blocked_ips_list() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT * FROM {$this->table_name} WHERE is_blocked = 1 ORDER BY last_attempt DESC
        ");
    }
    
    /**
     * Czyszczenie starych rekordów
     */
    public function cleanup_old_records($days = 30) {
        global $wpdb;
        
        $delete_before = date('Y-m-d H:i:s', current_time('timestamp') - ($days * 24 * 60 * 60));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < %s",
            $delete_before
        ));
    }
    
    /**
     * Usuwa wszystkie rekordy
     */
    public function delete_all_records() {
        global $wpdb;
        
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    /**
     * Usuwa wszystkie zablokowane rekordy
     */
    public function delete_all_blocked() {
        global $wpdb;
        
        return $wpdb->delete($this->table_name, array('is_blocked' => 1));
    }
    
    /**
     * Pobiera statystyki
     */
    public function get_stats($period_days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-$period_days days"));
        
        return array(
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
    }
    
    /**
     * Pobiera statystyki dzienne
     */
    public function get_daily_stats($period_days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-$period_days days"));
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT DATE(last_attempt) as date, 
                   COUNT(*) as attempts,
                   COUNT(DISTINCT ip_address) as unique_ips,
                   SUM(is_blocked) as blocked
            FROM {$this->table_name} 
            WHERE last_attempt >= %s
            GROUP BY DATE(last_attempt)
            ORDER BY date ASC
        ", $start_date));
    }
    
    /**
     * Pobiera statystyki krajów
     */
    public function get_country_stats($period_days = 30, $limit = 8) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-$period_days days"));
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT country_code, country_name, 
                   COUNT(*) as attempts,
                   COUNT(DISTINCT ip_address) as unique_ips
            FROM {$this->table_name} 
            WHERE country_code != '' AND last_attempt >= %s
            GROUP BY country_code, country_name
            ORDER BY attempts DESC
            LIMIT %d
        ", $start_date, $limit));
    }
    
    /**
     * Pobiera najczęściej atakowanych użytkownicy
     */
    public function get_top_users($period_days = 30, $limit = 8) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-$period_days days"));
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT username, 
                   COUNT(*) as attempts,
                   COUNT(DISTINCT ip_address) as unique_attackers
            FROM {$this->table_name} 
            WHERE username IS NOT NULL AND last_attempt >= %s
            GROUP BY username 
            ORDER BY attempts DESC 
            LIMIT %d
        ", $start_date, $limit));
    }
    
    /**
     * Pobiera najaktywniejsze IP
     */
    public function get_top_ips($period_days = 30, $limit = 8) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-$period_days days"));
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT ip_address, country_code, city, 
                   COUNT(*) as attempts,
                   MAX(is_blocked) as is_blocked
            FROM {$this->table_name} 
            WHERE last_attempt >= %s
            GROUP BY ip_address, country_code, city
            ORDER BY attempts DESC 
            LIMIT %d
        ", $start_date, $limit));
    }
    
    /**
     * Pobiera lokalizacje ataków dla mapy
     */
    public function get_attack_locations($period_days = 30, $limit = 50) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-$period_days days"));
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT country_code, country_name, city, latitude, longitude,
                   COUNT(*) as attempts,
                   COUNT(DISTINCT ip_address) as unique_ips
            FROM {$this->table_name} 
            WHERE country_code != '' AND latitude IS NOT NULL AND longitude IS NOT NULL AND last_attempt >= %s
            GROUP BY country_code, country_name, city, latitude, longitude
            ORDER BY attempts DESC
            LIMIT %d
        ", $start_date, $limit));
    }
    
    /**
     * Test zapisu do bazy
     */
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
        
        return array(
            'result' => $result,
            'insert_id' => $wpdb->insert_id,
            'error' => $wpdb->last_error
        );
    }
    
    /**
     * Debug bazy danych
     */
    public function debug_database() {
        global $wpdb;
        
        return array(
            'table_name' => $this->table_name,
            'table_exists' => $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") ? 'TAK' : 'NIE',
            'row_count' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'wpdb_error' => $wpdb->last_error,
            'wpdb_last_query' => $wpdb->last_query
        );
    }
}
