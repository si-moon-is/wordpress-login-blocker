<?php
/**
 * Login Blocker - Data Exporter Class
 * Handles data export functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Exporter {
    
    private $allowed_formats = ['csv', 'json'];
    private $max_period = 365; // Maksymalny okres w dniach
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'login_attempts';
        
        // Wyłącz buffering i kompresję dla czystego output
        $this->clean_output_buffers();
    }
    
    /**
     * Główna metoda eksportu
     */
    public function export($format, $period) {
        try {
            // Walidacja parametrów
            if (!$this->validate_parameters($format, $period)) {
                throw new Exception('Invalid export parameters');
            }
            
            // Sprawdź uprawnienia
            if (!$this->check_capabilities()) {
                throw new Exception('Insufficient permissions for export');
            }
            
            // Sprawdź czy nagłówki nie zostały już wysłane
            if (headers_sent($filename, $linenum)) {
                throw new Exception("Headers already sent in {$filename} on line {$linenum}");
            }
            
            // Sprawdź czy tabela istnieje
            if (!$this->table_exists()) {
                throw new Exception('Login attempts table does not exist');
            }
            
            // Wykonaj eksport w zależności od formatu
            switch ($format) {
                case 'csv':
                    $this->export_csv($period);
                    break;
                case 'json':
                    $this->export_json($period);
                    break;
                default:
                    throw new Exception('Unsupported export format');
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log_error('Export failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Eksport danych do CSV
     */
    private function export_csv($period) {
        $data = $this->get_export_data($period);
        
        if (empty($data)) {
            throw new Exception('No data available for export');
        }
        
        $filename = $this->generate_filename('csv');
        
        // Ustaw nagłówki
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        // Utwórz output
        $output = fopen('php://output', 'w');
        
        // Dodaj BOM dla UTF-8 (dla Excel)
        fwrite($output, "\xEF\xBB\xBF");
        
        // Nagłówki kolumn
        $headers = ['IP Address', 'Username', 'Attempts', 'Blocked', 'Last Attempt', 'Country', 'City', 'ISP'];
        fputcsv($output, $headers);
        
        // Dane
        foreach ($data as $row) {
            fputcsv($output, [
                $row['ip_address'],
                $row['username'],
                $row['attempts'],
                $row['is_blocked'],
                $row['last_attempt'],
                $row['country'],
                $row['city'],
                $row['isp']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Eksport danych do JSON
     */
    private function export_json($period) {
        $data = $this->get_export_data($period);
        
        if (empty($data)) {
            throw new Exception('No data available for export');
        }
        
        $filename = $this->generate_filename('json');
        
        // Ustaw nagłówki
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output JSON
        echo wp_json_encode([
            'export_info' => [
                'generated_at' => current_time('mysql'),
                'period_days' => $period,
                'total_records' => count($data)
            ],
            'data' => $data
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        exit;
    }
    
    /**
     * Pobiera dane do eksportu
     */
    private function get_export_data($period) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-$period days"));
        
        $query = $wpdb->prepare("
            SELECT 
                ip_address,
                username,
                attempts,
                is_blocked,
                last_attempt,
                country_code,
                city,
                isp
            FROM {$this->table_name} 
            WHERE last_attempt >= %s
            ORDER BY last_attempt DESC
        ", $start_date);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if ($wpdb->last_error) {
            throw new Exception('Database error: ' . $wpdb->last_error);
        }
        
        if (empty($results)) {
            return [];
        }
        
        // Formatuj dane do eksportu
        $export_data = [];
        foreach ($results as $row) {
            $export_data[] = [
                'ip_address'  => sanitize_text_field($row['ip_address'] ?? ''),
                'username'    => sanitize_text_field($row['username'] ?? ''),
                'attempts'    => intval($row['attempts'] ?? 0),
                'is_blocked'  => ($row['is_blocked'] ?? 0) ? 'Yes' : 'No',
                'last_attempt' => $this->format_date($row['last_attempt'] ?? ''),
                'country'     => sanitize_text_field($row['country_code'] ?? 'XX'),
                'city'        => sanitize_text_field($row['city'] ?? ''),
                'isp'         => sanitize_text_field($row['isp'] ?? '')
            ];
        }
        
        return $export_data;
    }
    
    /**
     * Eksport statystyk
     */
    public function export_stats($period) {
        try {
            if (!$this->check_capabilities()) {
                throw new Exception('Insufficient permissions for stats export');
            }
            
            if (headers_sent()) {
                throw new Exception('Headers already sent');
            }
            
            $period = intval($period);
            if ($period < 1 || $period > $this->max_period) {
                throw new Exception('Invalid period specified');
            }
            
            $stats = $this->get_stats_data($period);
            
            $filename = $this->generate_filename('stats', 'json');
            
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo wp_json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
            
        } catch (Exception $e) {
            $this->log_error('Stats export failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Pobiera dane statystyk
     */
    private function get_stats_data($period) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-$period days"));
        
        return [
            'export_info' => [
                'period_days' => $period,
                'generated_at' => current_time('mysql'),
                'date_range' => [
                    'from' => $start_date,
                    'to' => current_time('mysql')
                ]
            ],
            'statistics' => [
                'total_attempts' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE last_attempt >= %s",
                    $start_date
                )),
                'blocked_attempts' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE is_blocked = 1 AND last_attempt >= %s",
                    $start_date
                )),
                'unique_ips' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE last_attempt >= %s",
                    $start_date
                )),
                'blocked_ips' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE is_blocked = 1 AND last_attempt >= %s",
                    $start_date
                )),
                'top_countries' => $wpdb->get_results($wpdb->prepare("
                    SELECT country_code, COUNT(*) as attempt_count 
                    FROM {$this->table_name} 
                    WHERE last_attempt >= %s 
                    AND country_code NOT IN ('LOCAL', 'XX', '')
                    GROUP BY country_code 
                    ORDER BY attempt_count DESC 
                    LIMIT 10
                ", $start_date), ARRAY_A),
                'most_targeted_users' => $wpdb->get_results($wpdb->prepare("
                    SELECT username, COUNT(*) as attempt_count 
                    FROM {$this->table_name} 
                    WHERE last_attempt >= %s AND username != ''
                    GROUP BY username 
                    ORDER BY attempt_count DESC 
                    LIMIT 10
                ", $start_date), ARRAY_A)
            ]
        ];
    }
    
    /**
     * Walidacja parametrów
     */
    private function validate_parameters($format, $period) {
        // Walidacja formatu
        if (!in_array($format, $this->allowed_formats)) {
            $this->log_error('Invalid export format: ' . $format);
            return false;
        }
        
        // Walidacja okresu
        $period = intval($period);
        if ($period < 1 || $period > $this->max_period) {
            $this->log_error('Invalid export period: ' . $period);
            return false;
        }
        
        return true;
    }
    
    /**
     * Sprawdza uprawnienia użytkownika
     */
    private function check_capabilities() {
        return current_user_can('export') && current_user_can('manage_options');
    }
    
    /**
     * Sprawdza czy tabela istnieje
     */
    private function table_exists() {
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
    }
    
    /**
     * Generuje nazwę pliku
     */
    private function generate_filename($type, $extension = null) {
        $extension = $extension ?: $type;
        $site_name = sanitize_file_name(get_bloginfo('name'));
        $timestamp = date('Y-m-d-H-i-s');
        return "login-blocker-{$type}-{$site_name}-{$timestamp}.{$extension}";
    }
    
    /**
     * Formatuje datę
     */
    private function format_date($date_string) {
        if (empty($date_string)) {
            return '';
        }
        
        try {
            $date = new DateTime($date_string);
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $date_string;
        }
    }
    
    /**
     * Czyści output buffery
     */
    private function clean_output_buffers() {
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Wyłącz kompresję
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
        }
        
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
    }
    
    /**
     * Loguje błędy
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Login Blocker Exporter: ' . $message);
        }
    }
    
    /**
     * Sprawdza czy eksport jest dostępny
     */
    public function is_available() {
        return $this->check_capabilities() && $this->table_exists();
    }
    
    /**
     * Zwraca obsługiwane formaty
     */
    public function get_supported_formats() {
        return $this->allowed_formats;
    }
    
    /**
     * Zwraca maksymalny okres
     */
    public function get_max_period() {
        return $this->max_period;
    }
}