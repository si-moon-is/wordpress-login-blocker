<?php
/**
 * Login Blocker - Logger Class
 * Handles logging functionality with file and email notifications
 */

// DODANE: Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Logger {
    
    private $log_path;
    private $debug_mode;
    
    public function __construct($log_path, $debug_mode = false) {
        $this->log_path = $log_path;
        $this->debug_mode = $debug_mode;
        $this->init_log_system();
    }
    
    private function init_log_system() {
        $this->create_log_directory();
        $this->create_initial_log_entry();
    }
    
    public function log($level, $message, $context = array()) {
    $should_log = $this->debug_mode || in_array($level, ['ERROR', 'WARNING', 'CRITICAL']);
    
    if (!$should_log) {
        return;
    }
    
    $timestamp = current_time('mysql');
    
    // ESCAPING WIADOMOŚCI I KONTEKSTU
    $safe_message = esc_html($message);
    $safe_context = !empty($context) ? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) : '';
    
    $log_entry = sprintf(
        "[%s] %s: %s %s\n",
        $timestamp,
        strtoupper($level),
        $safe_message,
        $safe_context
    );
    
    $file_logged = $this->write_to_file($level, $log_entry);
    
    // Log do WordPress debug.log - TEŻ ESCAPING
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log("Login Blocker {$level}: " . esc_html($message));
    }
    
    // Powiadomienia dla krytycznych błędów
    if (($level === 'ERROR' || $level === 'CRITICAL') && $file_logged) {
        $this->notify_admin($level, $safe_message, $context);
    }
}
    
    public function get_log_files() {
    $log_files = array();
    
    if (file_exists($this->log_path)) {
        $files = scandir($this->log_path);
        foreach ($files as $file) {
            // DODAJ WALIDACJĘ NAZWY PLIKU
            if ($file !== '.' && $file !== '..' && 
                pathinfo($file, PATHINFO_EXTENSION) === 'log' &&
                preg_match('/^[a-zA-Z0-9_\-\.]+\.log$/', $file)) {
                $log_files[] = $file;
            }
        }
    }
    
    rsort($log_files);
    return $log_files;
}
    
    public function get_log_content($filename, $max_size = 5242880) { // 5MB domyślnie
    // 1. Walidacja podstawowa
    if (empty($filename) || !is_string($filename)) {
        return 'Nieprawidłowa nazwa pliku.';
    }
    
    // 2. Walidacja formatu nazwy pliku
    if (!preg_match('/^login\-blocker\-[0-9]{4}\-[0-9]{2}\-[0-9]{2}\.log$/', $filename)) {
        return 'Nieprawidłowy format nazwy pliku logów.';
    }
    
    // 3. Budowanie ścieżki
    $file_path = $this->log_path . $filename;
    
    // 4. Sprawdzenie czy plik jest w katalogu logów
    $real_log_path = realpath($this->log_path);
    $real_file_path = realpath($file_path);
    
    if ($real_file_path === false || 
        $real_log_path === false || 
        strpos($real_file_path, $real_log_path) !== 0) {
        return 'Błąd bezpieczeństwa: Nieprawidłowa ścieżka dostępu.';
    }
    
    // 5. Sprawdzenie istnienia pliku
    if (!file_exists($file_path)) {
        return 'Plik nie istnieje.';
    }
    
    // 6. Sprawdzenie czy to plik (nie katalog)
    if (!is_file($file_path)) {
        return 'To nie jest plik.';
    }
    
    // 7. Sprawdź rozmiar pliku przed odczytem
    if (filesize($file_path) > $max_size) {
        return "Plik jest zbyt duży do wyświetlenia. Rozmiar: " . $this->format_bytes(filesize($file_path));
    }
    
    // 8. Odczyt zawartości z obsługą błędów
    $content = file_get_contents($file_path);
    if ($content === false) {
        return 'Błąd odczytu pliku.';
    }
    
    return $content ?: 'Plik jest pusty.';
}
    
    public function clear_logs() {
        if (!file_exists($this->log_path)) {
            return false;
        }
        
        $files = glob($this->log_path . '*.log');
        $deleted_count = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $deleted_count++;
            }
        }
        
        return $deleted_count;
    }
    
    public function get_logs_size() {
        $log_files = $this->get_log_files();
        $total_size = 0;
        
        foreach ($log_files as $log_file) {
            $file_path = $this->log_path . $log_file;
            if (file_exists($file_path)) {
                $total_size += filesize($file_path);
            }
        }
        
        return $this->format_bytes($total_size);
    }
    
    private function create_log_directory() {
        if (!file_exists($this->log_path)) {
            if (!wp_mkdir_p($this->log_path)) {
                error_log('Login Blocker: Nie udało się utworzyć katalogu logów: ' . $this->log_path);
                return false;
            }
            
            // Zabezpieczenie katalogu
            file_put_contents($this->log_path . '.htaccess', 'Deny from all');
            file_put_contents($this->log_path . 'index.php', "<?php\n// Silence is golden\n");
        }
        
        return true;
    }
    
    private function create_initial_log_entry() {
        $log_files = $this->get_log_files();
        
        if (empty($log_files)) {
            $this->log('INFO', 'System logów zainicjalizowany', array(
                'log_directory' => $this->log_path,
                'debug_mode' => $this->debug_mode
            ));
        }
    }
    
    private function write_to_file($level, $log_entry) {
    $log_file = $this->log_path . 'login-blocker-' . date('Y-m-d') . '.log';

    // Sprawdź czy możemy pisać do pliku
    if (file_exists($log_file) && !is_writable($log_file)) {
        $this->log_fallback("Brak uprawnień do zapisu pliku: " . $log_file);
        return false;
    }
    
    // SPRAWDŹ ROZMIAR PLIKU PRZED ZAPISEM
    if (file_exists($log_file) && filesize($log_file) > (10 * 1024 * 1024)) { // 10MB limit
        $this->log('WARNING', "Plik logów przekracza rozmiar 10MB", array(
            'file' => $log_file,
            'size' => filesize($log_file)
        ));
        return false;
    }
    
    if (!file_exists($this->log_path) || !is_writable($this->log_path)) {
        $this->log_fallback("Problem z katalogiem logów: " . $this->log_path);
        return false;
    }
    
    try {
        // POPRAWKA: Użyj file_put_contents zamiast file_get_contents
        $result = file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        return $result !== false;
    } catch (Exception $e) {
        $this->log_fallback("Wyjątek podczas zapisu do logów: " . $e->getMessage());
        return false;
    }
}
    
    private function log_fallback($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Login Blocker LOG ERROR: " . $message);
        }
    }
    
    /**
     * UZUPEŁNIONA METODA: Powiadomienia admina o błędach
     */
    private function notify_admin($level, $message, $context) {
    $notifications_enabled = get_option('login_blocker_error_notifications', true);
    
    if (!$notifications_enabled) {
        return;
    }
    
    $to = get_option('login_blocker_notification_email', get_option('admin_email'));
    
    if (empty($to)) {
        $this->log('WARNING', "Brak adresu email dla powiadomień");
        return;
    }
    
    $subject = "🚨 Login Blocker {$level} - " . esc_html(get_bloginfo('name'));
    
    $email_message = "
Wystąpił błąd w pluginie Login Blocker:

Poziom: " . esc_html($level) . "
Wiadomość: " . esc_html($message) . "
Czas: " . esc_html(current_time('mysql')) . "
Strona: " . esc_url(get_bloginfo('url')) . "

Kontekst:
" . wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

---
To jest automatyczna wiadomość z systemu Login Blocker.
";    
    
    // BEZPIECZNE UŻYCIE KLASY EMAIL
    if (class_exists('LoginBlocker_Email')) {
        $email_class = new LoginBlocker_Email();
        $result = $email_class->send_email($to, $subject, $email_message);
        
        if (!$result) {
            $this->log('ERROR', "Nie udało się wysłać powiadomienia email", array(
                'to' => $to,
                'subject' => $subject
            ));
        }
    } else {
        // FALLBACK: użyj WordPress wp_mail()
        $result = wp_mail($to, $subject, $email_message);
        
        if (!$result) {
            $this->log('ERROR', "Nie udało się wysłać powiadomienia email (fallback)", array(
                'to' => $to,
                'subject' => $subject
            ));
        }
    }
}
    
    private function format_bytes($bytes) {
        if ($bytes === 0) return '0 B';
        
        $units = array('B', 'KB', 'MB', 'GB');
        $base = 1024;
        $class = min((int)log($bytes, $base), count($units) - 1);
        
        return sprintf('%s %s', number_format($bytes / pow($base, $class), 2), $units[$class]);
    }
    
    // Helper methods
    public function error($message, $context = array()) {
        $this->log('ERROR', $message, $context);
    }
    
    public function warning($message, $context = array()) {
        $this->log('WARNING', $message, $context);
    }
    
    public function info($message, $context = array()) {
        $this->log('INFO', $message, $context);
    }
    
    public function debug($message, $context = array()) {
        $this->log('DEBUG', $message, $context);
    }
}
