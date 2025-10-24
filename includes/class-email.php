<?php
/**
 * Login Blocker - Email Class
 * Handles email notifications with SMTP support
 */

// DODANE: Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Email {
    
    private $smtp_enabled;
    private $smtp_settings;
    
    public function __construct() {
        $this->smtp_enabled = get_option('login_blocker_smtp_enabled', false);
        $this->smtp_settings = array(
            'host' => get_option('login_blocker_smtp_host'),
            'port' => get_option('login_blocker_smtp_port', 587),
            'username' => get_option('login_blocker_smtp_username'),
            'password' => $this->get_encrypted_password(), // Zaszyfrowane hasło
            'encryption' => get_option('login_blocker_smtp_encryption', 'tls')
        );
    }

    private function get_encrypted_password() {
        $encrypted = get_option('login_blocker_smtp_password_encrypted');
        $key = $this->get_encryption_key();
        
        if ($encrypted && function_exists('openssl_decrypt')) {
            // POPRAWNE TWORZENIE IV - zawsze 16 bajtów
            $iv = $this->get_encryption_iv($key);
            return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        }
        
        // Fallback do plain text (dla kompatybilności)
        return get_option('login_blocker_smtp_password');
    }

    private function get_encryption_iv($key) {
        // Utwórz IV z klucza - zawsze 16 bajtów
        return substr(hash('sha256', $key), 0, 16);
    }

    private function get_encryption_key() {
        $key = get_option('login_blocker_encryption_key');
        if (!$key) {
            $key = wp_generate_password(64, true, true);
            update_option('login_blocker_encryption_key', $key);
        }
        return $key;
    }

    /**
     * Metoda do szyfrowania hasła SMTP (do użycia przy zapisie)
     */
    public function encrypt_password($password) {
        if (empty($password) || !function_exists('openssl_encrypt')) {
            return $password;
        }
        
        $key = $this->get_encryption_key();
        $iv = $this->get_encryption_iv($key);
        
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        
        if ($encrypted === false) {
            error_log('Login Blocker: Błąd szyfrowania hasła SMTP');
            return $password;
        }
        
        return $encrypted;
    }
    
    public function send_notification($type, $data) {
        if (!$this->notifications_enabled()) {
            return false;
        }
        
        $to = $this->get_recipient();
        $subject = $this->get_subject($type, $data);
        $message = $this->get_message($type, $data);
        
        return $this->send_email($to, $subject, $message);
    }
    
    public function send_test_email() {
        $to = $this->get_recipient();
        $subject = __('Login Blocker - Test powiadomień', 'login-blocker');
        $message = $this->get_test_message();
        
        return $this->send_email($to, $subject, $message);
    }
    
    /**
     * UZUPEŁNIONA METODA: Wysyłanie powiadomienia o zablokowaniu IP
     */
    public function send_block_notification($ip, $username, $attempts, $block_until, $geolocation) {
        $data = array(
            'ip' => $ip,
            'username' => $username,
            'attempts' => $attempts,
            'block_until' => $block_until,
            'geolocation' => $geolocation
        );
        
        return $this->send_notification('ip_blocked', $data);
    }
    
    /**
     * UZUPEŁNIONA METODA: Wysyłanie powiadomienia o błędzie
     */
    public function send_error_notification($level, $message, $context = array()) {
        $data = array(
            'level' => $level,
            'message' => $message,
            'context' => $context
        );
        
        return $this->send_notification('error', $data);
    }

    private function check_email_rate_limit() {
        $transient_name = 'lb_email_rate_limit_' . get_current_user_id();
        $attempts = get_transient($transient_name) ?: 0;
        
        if ($attempts >= 5) { // max 5 emaili na godzinę
            error_log('Login Blocker: Limit wysyłania emaili przekroczony');
            return false;
        }
        
        set_transient($transient_name, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }
    
    public function send_email($to, $subject, $message) {
        if (!$this->check_email_rate_limit()) {
            return false;
        }
        // WALIDACJA ADRESU EMAIL
        if (!is_email($to)) {
            error_log("Login Blocker: Nieprawidłowy adres email: {$to}");
            return false;
        }
        
        // WALIDACJA TEMATU I WIADOMOŚCI
        if (empty($subject) || empty($message)) {
            error_log("Login Blocker: Brak tematu lub treści wiadomości");
            return false;
        }
        
        if ($this->smtp_enabled && $this->is_smtp_configured()) {
            return $this->send_via_smtp($to, $subject, $message);
        } else {
            return $this->send_via_wp($to, $subject, $message);
        }
    }
    
    // Wysyłanie przez domyślny system WordPress
    private function send_via_wp($to, $subject, $message) {
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $this->get_from_header()
        );
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    // Wysyłanie przez SMTP
    private function send_via_smtp($to, $subject, $message) {
        // WALIDACJA DANYCH SMTP
        $smtp_host = sanitize_text_field($this->smtp_settings['host']);
        $smtp_port = intval($this->smtp_settings['port']);
        $smtp_username = sanitize_email($this->smtp_settings['username']);
        $smtp_password = $this->smtp_settings['password']; // Hasło już zaszyfrowane
        $smtp_encryption = in_array($this->smtp_settings['encryption'], ['tls', 'ssl']) ? $this->smtp_settings['encryption'] : 'tls';
        
        // DODATKOWA WALIDACJA
        if (empty($smtp_host) || empty($smtp_username) || !is_email($smtp_username)) {
            error_log("Login Blocker: Niekompletna lub nieprawidłowa konfiguracja SMTP");
            return false;
        }
        
        // WALIDACJA PORTU
        if ($smtp_port < 1 || $smtp_port > 65535) {
            error_log("Login Blocker: Nieprawidłowy port SMTP: {$smtp_port}");
            return false;
        }
        
        // Użyj PHPMailer jeśli dostępny
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            if (!file_exists(ABSPATH . WPINC . '/PHPMailer/PHPMailer.php')) {
                error_log("Login Blocker: PHPMailer nie jest dostępny");
                return $this->send_via_wp($to, $subject, $message);
            }
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // KONFIGURACJA SMTP Z BEZPIECZEŃSTWEM
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->Port = $smtp_port;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_username;
            $mail->Password = $smtp_password;
            
            // BEZPIECZNE OPCJE
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                )
            );
            
            // TIMEOUTY
            $mail->Timeout = 15;
            $mail->SMTPKeepAlive = false;
            
            // Szyfrowanie
            if ($smtp_encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtp_encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Opcje
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtp_username, sanitize_text_field(get_bloginfo('name')));
            $mail->addAddress($to);
            $mail->Subject = sanitize_text_field($subject);
            $mail->Body = $message;
            $mail->isHTML(false);
            
            $result = $mail->send();
            
            if ($result) {
                error_log("Login Blocker: Email wysłany pomyślnie przez SMTP do: {$to}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log('Login Blocker SMTP Error: ' . $e->getMessage());
            // FALLBACK do WordPress mail
            return $this->send_via_wp($to, $subject, $message);
        }
    }
    
    /**
     * UZUPEŁNIONA METODA: Szablony wiadomości email
     */
    private function get_message($type, $data) {
        $site_name = esc_html(get_bloginfo('name'));
        $site_url = esc_url(get_bloginfo('url'));
        
        switch ($type) {
            case 'ip_blocked':
                $message = "Wykryto podejrzaną aktywność na stronie: {$site_name}\n\n";
                $message .= "Adres IP: " . esc_html($data['ip']) . "\n";
                $message .= "Próbowana nazwa użytkownika: " . esc_html($data['username']) . "\n";
                $message .= "Liczba nieudanych prób: " . intval($data['attempts']) . "\n";
                $message .= "Zablokowany do: " . esc_html($data['block_until']) . "\n\n";
                
                if (!empty($data['geolocation'])) {
                    $message .= "Informacje geolokalizacyjne:\n";
                    $message .= "Kraj: " . esc_html($data['geolocation']['country_name'] ?? '') . " (" . esc_html($data['geolocation']['country_code'] ?? '') . ")\n";
                    $message .= "Miasto: " . esc_html($data['geolocation']['city'] ?? '') . "\n";
                    $message .= "Region: " . esc_html($data['geolocation']['region'] ?? '') . "\n";
                    $message .= "ISP: " . esc_html($data['geolocation']['isp'] ?? '') . "\n\n";
                }
                
                $message .= "Możesz zarządzać zablokowanymi adresami IP w panelu administracyjnym WordPress.\n";
                $message .= "Adres strony: {$site_url}\n";
                break;
                
            case 'error':
                $message = "Wystąpił błąd w wtyczce Login Blocker:\n\n";
                $message .= "Poziom: " . esc_html($data['level']) . "\n";
                $message .= "Wiadomość: " . esc_html($data['message']) . "\n";
                $message .= "Czas: " . esc_html(current_time('mysql')) . "\n";
                $message .= "Strona: {$site_url}\n\n";
                
                if (!empty($data['context'])) {
                    $message .= "Kontekst:\n" . wp_json_encode($data['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                }
                break;
                
            default:
                $message = "Nieznany typ wiadomości: " . esc_html($type) . "\n";
                break;
        }
        
        return $message;
    }
    
    private function get_subject($type, $data) {
        $site_name = esc_html(get_bloginfo('name'));
        
        $subjects = array(
            'ip_blocked' => '🚨 Alert bezpieczeństwa: Adres IP zablokowany - ' . $site_name,
            'error' => '🚨 Login Blocker ERROR - ' . $site_name,
            'test' => __('Login Blocker - Test powiadomień', 'login-blocker')
        );
        
        return $subjects[$type] ?? 'Login Blocker Notification';
    }
    
    private function get_test_message() {
        return __("To jest testowy email z wtyczki Login Blocker.\n\n", 'login-blocker') .
               __("Czas wysłania: ", 'login-blocker') . esc_html(current_time('mysql')) . "\n" .
               __("Strona: ", 'login-blocker') . esc_url(get_bloginfo('url')) . "\n" .
               __("Konfiguracja SMTP: ", 'login-blocker') . ($this->smtp_enabled ? 'WŁĄCZONA' : 'WYŁĄCZONA') . "\n\n" .
               __("Jeśli otrzymałeś tę wiadomość, konfiguracja powiadomień działa poprawnie.\n\n", 'login-blocker') .
               __("---\nTo jest automatyczna wiadomość testowa.", 'login-blocker');
    }
    
    private function notifications_enabled() {
        return get_option('login_blocker_error_notifications', true);
    }
    
    private function get_recipient() {
        return get_option('login_blocker_notification_email', get_option('admin_email'));
    }
    
    private function get_from_header() {
        $site_name = esc_html(get_bloginfo('name'));
        $admin_email = sanitize_email(get_option('admin_email'));
        
        return $site_name . ' <' . $admin_email . '>';
    }
    
    private function is_smtp_configured() {
        return !empty($this->smtp_settings['host']) && !empty($this->smtp_settings['username']);
    }
}