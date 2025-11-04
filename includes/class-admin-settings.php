<?php
/**
 * Login Blocker - Admin Settings Class
 * Handles settings functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Admin_Settings {
    
    private $admin;
    
    public function __construct($admin) {
        $this->admin = $admin;
    }
    
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
                <?php $this->admin->get_main_class()->display_update_status(); ?>
            </div>
            <div class="card">
                <h2>Statystyki</h2>
                <?php
                global $wpdb;
                $total_blocked = $wpdb->get_var("SELECT COUNT(*) FROM {$this->admin->get_table_name()} WHERE is_blocked = 1");
                $total_attempts = $wpdb->get_var("SELECT COUNT(*) FROM {$this->admin->get_table_name()}");
                $unique_ips = $wpdb->get_var("SELECT COUNT(DISTINCT ip_address) FROM {$this->admin->get_table_name()}");
                ?>
                <p>Obecnie zablokowanych IP: <strong><?php echo esc_html( $total_blocked ); ?></strong></p>
                <p>Wszystkich zapisanych prób: <strong><?php echo esc_html( $total_attempts ); ?></strong></p>
                <p>Unikalnych adresów IP: <strong><?php echo esc_html( $unique_ips ); ?></strong></p>
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
}
