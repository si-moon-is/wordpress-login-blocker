<?php
/**
 * Login Blocker - AJAX Handlers
 * Handles all AJAX requests for the plugin
 */

// DODANE: Zabezpieczenie przed bezpośśrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Ajax {
    
    private $main_class;
    
    public function __construct($main_class) {
        $this->main_class = $main_class;
        $this->init_ajax_handlers();
    }
    
    private function init_ajax_handlers() {
        add_action('wp_ajax_unblock_ip', array($this, 'ajax_unblock_ip'));
        add_action('wp_ajax_test_email_config', array($this, 'ajax_test_email'));
        add_action('wp_ajax_get_live_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_get_chart_data', array($this, 'ajax_get_chart_data'));
        add_action('wp_ajax_export_data', array($this, 'ajax_export_data'));
        add_action('wp_ajax_login_blocker_export', 'login_blocker_ajax_export');
    }
    
    public function ajax_unblock_ip() {
    $this->check_permissions();
    
    $ip = sanitize_text_field($_POST['ip']);
    
    // DODAJ WALIDACJĘ IP
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        wp_send_json_error(esc_html__('Nieprawidłowy adres IP', 'login-blocker'));
    }
    
    $result = $this->main_class->unblock_ip($ip);
    
    if ($result) {
        wp_send_json_success(esc_html__('IP odblokowane', 'login-blocker'));
    } else {
        wp_send_json_error(esc_html__('Błąd odblokowywania', 'login-blocker'));
    }
}
    
    public function ajax_test_email() {
        $this->check_permissions();
        
        $email_class = new LoginBlocker_Email();
        $result = $email_class->send_test_email();
        
        if ($result) {
            wp_send_json_success(esc_html__('Testowy email został wysłany pomyślnie! Sprawdź skrzynkę odbiorczą.', 'login-blocker'));
        } else {
            wp_send_json_error(esc_html__('Nie udało się wysłać testowego emaila. Sprawdź konfigurację.', 'login-blocker'));
        }
    }
    
    public function ajax_get_stats() {
    $this->check_permissions();
    
    global $wpdb;
    $stats = array(
        'blocked' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}login_attempts WHERE is_blocked = 1")),
        'attempts' => intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}login_attempts WHERE last_attempt > %s",
            date('Y-m-d H:i:s', time() - 3600)
        )))
    );
    
    wp_send_json_success($stats);
}
    
    /**
     * NOWA METODA: Pobieranie danych dla wykresów
     */
    public function ajax_get_chart_data() {
    $this->check_permissions();
    
    $period = isset($_GET['period']) ? intval($_GET['period']) : 30;
    
    // Walidacja okresu
    if ($period < 1 || $period > 365) {
        wp_send_json_error(esc_html__('Nieprawidłowy okres', 'login-blocker'));
    }
    
    $data = $this->get_chart_data($period);
    
    // Dodatkowe zabezpieczenie danych
    $data['dates'] = array_map('sanitize_text_field', $data['dates']);
    $data['attempts'] = array_map('intval', $data['attempts']);
    $data['blocked'] = array_map('intval', $data['blocked']);
    $data['unique_ips'] = array_map('intval', $data['unique_ips']);
    
    wp_send_json_success($data);
}
    
    /**
     * NOWA METODA: Generowanie danych dla wykresów
     */
    private function get_chart_data($period) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-$period days"));
        
        // Pobierz dane dzienne
        $daily_stats = $wpdb->get_results($wpdb->prepare("
        SELECT DATE(last_attempt) as date, 
            COUNT(*) as attempts,
            COUNT(DISTINCT ip_address) as unique_ips,
            SUM(is_blocked) as blocked
        FROM {$wpdb->prefix}login_attempts 
        WHERE last_attempt >= %s
        GROUP BY DATE(last_attempt)
        ORDER BY date ASC
    ", $start_date));
        
        $dates = array();
        $attempts = array();
        $blocked = array();
        $unique_ips = array();
        
        // Wypełnij wszystkie daty w okresie (nawet jeśli nie ma danych)
        for ($i = $period; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $formatted_date = date('d.m', strtotime($date));
            $dates[] = $formatted_date;
            
            // Znajdź dane dla tej daty
            $found = false;
            foreach ($daily_stats as $stat) {
                if ($stat->date == $date) {
                    $attempts[] = intval($stat->attempts);
                    $blocked[] = intval($stat->blocked);
                    $unique_ips[] = intval($stat->unique_ips);
                    $found = true;
                    break;
                }
            }
            
            // Jeśli nie ma danych dla tej daty, użyj zer
            if (!$found) {
                $attempts[] = 0;
                $blocked[] = 0;
                $unique_ips[] = 0;
            }
        }
        
        return array(
            'dates' => $dates,
            'attempts' => $attempts,
            'blocked' => $blocked,
            'unique_ips' => $unique_ips
        );
    }
    
    /**
     * ZAKOMENTOWANA METODA - brak klasy LoginBlocker_Exporter
     * 
    public function ajax_export_data() {
        $this->check_permissions();
        
        $format = sanitize_text_field($_GET['format']);
        $period = intval($_GET['period']);
        
        // BRAKUJĄCA KLASA - ZAKOMENTOWANE
        // $exporter = new LoginBlocker_Exporter();
        // $result = $exporter->export($format, $period);
        
        // TYMCZASOWA IMPLEMENTACJA
        wp_send_json_error('Funkcja eksportu jest tymczasowo niedostępna');
    }
    */
    
    private function check_permissions() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Brak uprawnień', 'login-blocker'));
    }
    
    if (!check_ajax_referer('login_blocker_ajax', 'nonce', false)) {
        wp_die(esc_html__('Błąd bezpieczeństwa', 'login-blocker'));
    }
}
    
    /**
     * NOWA METODA: Odblokowywanie IP (dla AJAX)
     */
    public function unblock_ip($ip) {
    global $wpdb;

    // DODAJ TYLKO TĘ LINIĘ - reszta pozostaje BEZ ZMIAN
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    // RESZTA FUNKCJI POZOSTAJE DOKŁADNIE TAKA SAMA
    $result = $wpdb->update(
        $this->main_class->table_name,
        array('is_blocked' => 0, 'attempts' => 0, 'block_until' => null),
        array('ip_address' => $ip)
    );
    
    if ($result !== false) {
        // Logowanie akcji
        if (method_exists($this->main_class, 'log_info')) {
            $this->main_class->log_info("IP odblokowane przez AJAX", array(
                'ip' => $ip,
                'admin_user' => wp_get_current_user()->user_login
            ));
        }
        return true;
    }
    
    return false;
}

    /**
 * Rate limiting - opcjonalne ulepszenie
 */
private function check_rate_limit($action = 'default', $max_attempts = 10, $timeframe = 60) {
    $user_id = get_current_user_id();
    $transient_name = "lb_rate_limit_{$action}_{$user_id}";
    
    $attempts = get_transient($transient_name) ?: 0;
    
    if ($attempts >= $max_attempts) {
        wp_send_json_error(esc_html__('Zbyt wiele żądań. Spróbuj ponownie za chwilę.', 'login-blocker'));
    }
    
    set_transient($transient_name, $attempts + 1, $timeframe);
}


// Dodaj metodę AJAX:
public function ajax_export_data() {
    $this->check_permissions();
    
    $format = sanitize_text_field($_POST['format']);
    $period = intval($_POST['period']);
    
    if (class_exists('LoginBlocker_Exporter')) {
        $exporter = new LoginBlocker_Exporter();
        $result = $exporter->export($format, $period);
        
        if ($result) {
            wp_send_json_success(esc_html__('Eksport zakończony pomyślnie', 'login-blocker'));
        } else {
            wp_send_json_error(esc_html__('Błąd eksportu danych', 'login-blocker'));
        }
    } else {
        wp_send_json_error(esc_html__('Klasa eksportera nie jest dostępna', 'login-blocker'));
    }
}

 function login_blocker_ajax_export() {
    // Sprawdź uprawnienia i nonce
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'login_blocker_export')) {
        wp_send_json_error('Brak uprawnień lub błąd bezpieczeństwa');
    }
    
    $format = sanitize_text_field($_POST['format'] ?? 'csv');
    $period = intval($_POST['period'] ?? 30);
    $type = sanitize_text_field($_POST['type'] ?? 'data');
    
    // Generuj URL do eksportu
    $export_url = add_query_arg(array(
        'login_blocker_export' => '1',
        'format' => $format,
        'period' => $period,
        'type' => $type,
        '_wpnonce' => wp_create_nonce('login_blocker_export')
    ), admin_url('admin.php'));
    
    wp_send_json_success(array(
        'redirect_url' => $export_url
    ));
}   
}
