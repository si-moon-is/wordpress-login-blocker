<?php
/**
 * Login Blocker - Admin Debug Class
 * Handles debug and logging functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Admin_Debug {
    
    private $admin;
    
    public function __construct($admin) {
        $this->admin = $admin;
    }
    
    public function debug_page() {
        $log_files = $this->admin->get_main_class()->get_log_files();
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'status';
        $current_log = isset($_GET['log_file']) ? sanitize_text_field($_GET['log_file']) : '';
        $log_content = '';
        
        if ($current_log && in_array($current_log, $log_files)) {
            $log_content = $this->admin->get_main_class()->get_log_content($current_log);
        }
        
        // Obsługa akcji
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'clear_logs' && check_admin_referer('login_blocker_debug')) {
                $this->admin->get_main_class()->clear_logs();
                echo '<div class="notice notice-success is-dismissible"><p>Logi zostały wyczyszczone.</p></div>';
            } elseif ($_POST['action'] === 'test_logging' && check_admin_referer('login_blocker_debug')) {
                $this->admin->get_main_class()->test_logging();
                echo '<div class="notice notice-success is-dismissible"><p>Test logowania wykonany. Sprawdź logi.</p></div>';
            } elseif ($_POST['action'] === 'create_test_entry' && check_admin_referer('login_blocker_debug')) {
                $this->admin->get_main_class()->log_info("Testowy wpis utworzony ręcznie", array(
                    'timestamp' => current_time('mysql'),
                    'user' => wp_get_current_user()->user_login
                ));
                echo '<div class="notice notice-success is-dismissible"><p>Testowy wpis utworzony. Odśwież stronę aby zobaczyć.</p></div>';
            }
        }
        
        $tabs = array(
            'status' => 'Status Systemu',
            'logs' => 'Przegląd Logów',
            'files' => 'Pliki Logów',
            'actions' => 'Akcje Debugowania',
            'email' => 'Test Email'
        );
        
        ?>
        <div class="wrap">
            <h1>Login Blocker - Debug & Logi</h1>
            
            <!-- Tabulatory -->
            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_name): ?>
                    <a href="<?php echo admin_url('admin.php?page=login-blocker-debug&tab=' . $tab_key); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                       <?php echo esc_html($tab_name); ?>
                    </a>
                <?php endforeach; ?>
            </h2>
            
            <!-- Zawartość zakładek -->
            <div class="login-blocker-tab-content">
                <?php 
                switch ($current_tab) {
                    case 'status':
                        $this->display_debug_status_tab();
                        break;
                        
                    case 'logs':
                        $this->display_logs_viewer_tab($log_files, $current_log, $log_content);
                        break;
                        
                    case 'files':
                        $this->display_log_files_tab($log_files);
                        break;
                        
                    case 'actions':
                        $this->display_debug_actions_tab();
                        break;
                        
                    case 'email':
                        $this->display_email_test_tab();
                        break;
                        
                    default:
                        $this->display_debug_status_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function display_debug_status_tab() {
        ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div class="card">
                <h3>Status Systemu</h3>
                <?php $this->admin->get_main_class()->display_system_status(); ?>
            </div>
            
            <div class="card">
                <h3>Informacje o Wtyczce</h3>
                <?php $this->display_plugin_info(); ?>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="card">
                <h3>Status Aktualizacji</h3>
                <?php $this->admin->get_main_class()->display_update_status(); ?>
            </div>
            
            <div class="card">
                <h3>Statystyki Logów</h3>
                <?php $this->display_log_stats(); ?>
            </div>
        </div>
        <?php
    }

    public function display_logs_viewer_tab($log_files, $current_log, $log_content) {
        ?>
        <div style="display: grid; grid-template-columns: 300px 1fr; gap: 20px;">
            <div class="card">
                <h3>Wybierz Plik Logów</h3>
                <?php if ($log_files): ?>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            <?php foreach ($log_files as $log_file): ?>
                                <li style="margin-bottom: 5px;">
                                    <a href="<?php echo admin_url('admin.php?page=login-blocker-debug&tab=logs&log_file=' . urlencode($log_file)); ?>" 
                                       style="display: block; padding: 8px; background: <?php echo $current_log === $log_file ? '#e0f0ff' : '#f6f7f7'; ?>; border-radius: 4px; text-decoration: none; font-size: 12px;"
                                       title="<?php echo esc_attr($this->get_log_file_info($log_file)); ?>">
                                        <strong><?php echo esc_html($log_file); ?></strong>
                                        <br>
                                        <small style="color: #666;"><?php echo $this->get_log_file_info($log_file); ?></small>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <p>Brak plików logów.</p>
                    <form method="post">
                        <?php wp_nonce_field('login_blocker_debug'); ?>
                        <input type="hidden" name="action" value="create_test_entry">
                        <button type="submit" class="button button-primary">Utwórz pierwszy wpis testowy</button>
                    </form>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h3>Zawartość Logu: <?php echo esc_html($current_log ?: 'Wybierz plik z listy'); ?></h3>
                <?php if ($current_log && $log_content): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div>
                            <strong>Rozmiar:</strong> <?php echo $this->get_log_file_size($current_log); ?> |
                            <strong>Ostatnia modyfikacja:</strong> <?php echo $this->get_log_file_mtime($current_log); ?>
                        </div>
                        <div>
                            <button type="button" id="refresh-log" class="button button-secondary">Odśwież</button>
                            <button type="button" id="copy-log" class="button button-secondary" data-clipboard-target="#log-content">
                                Kopiuj
                            </button>
                        </div>
                    </div>
                    <div id="log-content" style="background: #1d2327; color: #f0f0f1; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap; line-height: 1.4;">
                        <?php echo esc_html($log_content); ?>
                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        $('#refresh-log').on('click', function() {
                            window.location.reload();
                        });
                        
                        $('#copy-log').on('click', function() {
                            var logContent = document.getElementById('log-content');
                            var range = document.createRange();
                            range.selectNode(logContent);
                            window.getSelection().removeAllRanges();
                            window.getSelection().addRange(range);
                            document.execCommand('copy');
                            window.getSelection().removeAllRanges();
                            
                            var $button = $(this);
                            var originalText = $button.text();
                            $button.text('Skopiowano!');
                            setTimeout(function() {
                                $button.text(originalText);
                            }, 2000);
                        });
                    });
                    </script>
                <?php elseif ($current_log): ?>
                    <p>Plik jest pusty lub nie można go odczytać.</p>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <span class="dashicons dashicons-media-document" style="font-size: 48px; margin-bottom: 20px; display: block;"></span>
                        <p>Wybierz plik logów z listy po lewej stronie aby wyświetlić jego zawartość.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function display_log_files_tab($log_files) {
        ?>
        <div class="card">
            <h3>Zarządzanie Plikami Logów</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <h4>Statystyki Plików</h4>
                    <?php $this->display_log_files_stats($log_files); ?>
                </div>
                
                <div>
                    <h4>Akcje</h4>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <form method="post" style="margin-bottom: 0;">
                            <?php wp_nonce_field('login_blocker_debug'); ?>
                            <input type="hidden" name="action" value="create_test_entry">
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-plus" style="vertical-align: middle;"></span>
                                Utwórz Testowy Wpis
                            </button>
                            <p class="description">Dodaje testowy wpis do aktualnego pliku logów</p>
                        </form>
                        
                        <form method="post" style="margin-bottom: 0;">
                            <?php wp_nonce_field('login_blocker_debug'); ?>
                            <input type="hidden" name="action" value="test_logging">
                            <button type="submit" class="button button-secondary">
                                <span class="dashicons dashicons-admin-tools" style="vertical-align: middle;"></span>
                                Testuj Wszystkie Poziomy
                            </button>
                            <p class="description">Tworzy wpisy we wszystkich poziomach logów (DEBUG, INFO, WARNING, ERROR)</p>
                        </form>
                        
                        <form method="post" style="margin-bottom: 0;">
                            <?php wp_nonce_field('login_blocker_debug'); ?>
                            <input type="hidden" name="action" value="clear_logs">
                            <button type="submit" class="button button-danger" onclick="return confirm('Czy na pewno chcesz usunąć WSZYSTKIE pliki logów? Tej akcji nie można cofnąć.')">
                                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                Wyczyść Wszystkie Logi
                            </button>
                            <p class="description">Usuwa wszystkie pliki z katalogu logów</p>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php if ($log_files): ?>
                <h4>Lista Plików Logów</h4>
                <div style="overflow-x: auto;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Nazwa pliku</th>
                                <th>Rozmiar</th>
                                <th>Ostatnia modyfikacja</th>
                                <th>Liczba wierszy (szac.)</th>
                                <th>Akcjes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log_files as $log_file): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($log_file); ?></strong>
                                    </td>
                                    <td><?php echo $this->get_log_file_size($log_file); ?></td>
                                    <td><?php echo $this->get_log_file_mtime($log_file); ?></td>
                                    <td><?php echo $this->estimate_log_lines($log_file); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=login-blocker-debug&tab=logs&log_file=' . urlencode($log_file)); ?>" 
                                           class="button button-small">
                                           <span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
                                           Przeglądaj
                                        </a>
                                        <a href="<?php echo $this->get_log_download_url($log_file); ?>" 
                                           class="button button-small button-secondary"
                                           target="_blank">
                                           <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                           Pobierz
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <span class="dashicons dashicons-warning" style="font-size: 48px; margin-bottom: 20px; display: block;"></span>
                    <h3>Brak plików logów</h3>
                    <p>System logów nie utworzył jeszcze żadnych plików. Możesz utworzyć testowy wpis używając przycisków powyżej.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function display_debug_actions_tab() {
        ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="card">
                <h3>Testy Systemu Logów</h3>
                
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <div>
                        <h4>Podstawowy Test</h4>
                        <form method="post">
                            <?php wp_nonce_field('login_blocker_debug'); ?>
                            <input type="hidden" name="action" value="create_test_entry">
                            <button type="submit" class="button button-primary">
                                Utwórz Pojedynczy Wpis
                            </button>
                            <p class="description">Tworzy jeden wpis INFO w logach</p>
                        </form>
                    </div>
                    
                    <div>
                        <h4>Kompleksowy Test</h4>
                        <form method="post">
                            <?php wp_nonce_field('login_blocker_debug'); ?>
                            <input type="hidden" name="action" value="test_logging">
                            <button type="submit" class="button button-secondary">
                                Testuj Wszystkie Poziomy Logów
                            </button>
                            <p class="description">Tworzy wpisy we wszystkich poziomach: DEBUG, INFO, WARNING, ERROR</p>
                        </form>
                    </div>
                    
                    <div>
                        <h4>Test Bazy Danych</h4>
                        <button type="button" id="test-database" class="button button-secondary">
                            Test Zapisu do Bazy
                        </button>
                        <div id="db-test-result" style="margin-top: 10px;"></div>
                        <p class="description">Testuje zapis rekordu do tabeli login_blocker_attempts</p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h3>Zaawansowane Akcje</h3>
                
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <div>
                        <h4>Czyszczenie</h4>
                        <form method="post">
                            <?php wp_nonce_field('login_blocker_debug'); ?>
                            <input type="hidden" name="action" value="clear_logs">
                            <button type="submit" class="button button-danger" onclick="return confirm('Czy na pewno chcesz USUNĄĆ WSZYSTKIE pliki logów? Tej operacji nie można cofnąć!')">
                                Wyczyść Wszystkie Logi
                            </button>
                            <p class="description">Usuwa wszystkie pliki z katalogu logów</p>
                        </form>
                    </div>
                    
                    <div>
                        <h4>Debug Geolokalizacji</h4>
                        <button type="button" id="test-geolocation" class="button button-secondary">
                            Test Geolokalizacji
                        </button>
                        <div id="geo-test-result" style="margin-top: 10px;"></div>
                        <p class="description">Testuje pobieranie geolokalizacji dla przykładowego IP</p>
                    </div>
                    
                    <div>
                        <h4>Informacje o Systemie</h4>
                        <button type="button" id="show-system-info" class="button button-secondary">
                            Pokaż Informacje Systemowe
                        </button>
                        <div id="system-info" style="margin-top: 10px; display: none;"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-database').on('click', function() {
                var $button = $(this);
                var $result = $('#db-test-result');
                
                $button.prop('disabled', true).text('Testowanie...');
                $result.html('<p><span class="spinner is-active"></span> Testowanie zapisu do bazy...</p>');
                
                $.post(ajaxurl, {
                    action: 'login_blocker_test_db',
                    nonce: '<?php echo wp_create_nonce('login_blocker_debug'); ?>'
                }, function(response) {
                    $button.prop('disabled', false).text('Test Zapisu do Bazy');
                    
                    if (response.success) {
                        $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                });
            });
            
            $('#show-system-info').on('click', function() {
            var $systemInfo = $('#system-info');
            var isVisible = $systemInfo.is(':visible');
            
            $systemInfo.toggle();
            
            if ($systemInfo.is(':visible') && $systemInfo.html().trim() === '') {
                $systemInfo.html('<p><span class="spinner is-active"></span> Ładowanie informacji systemowych...</p>');
                
                // Pobierz informacje systemowe via AJAX lub bezpośrednio
                $.post(ajaxurl, {
                    action: 'login_blocker_get_system_info',
                    nonce: '<?php echo wp_create_nonce('login_blocker_debug'); ?>'
                }, function(response) {
                    if (response.success) {
                        $systemInfo.html('<pre style="background: #f6f7f7; padding: 15px; border-radius: 4px; overflow: auto; max-height: 400px; white-space: pre-wrap;">' + response.data + '</pre>');
                    } else {
                        $systemInfo.html('<div class="notice notice-error"><p>Błąd ładowania informacji systemowych.</p></div>');
                    }
                }).fail(function() {
                    $systemInfo.html('<div class="notice notice-error"><p>Błąd połączenia z serwerem.</p></div>');
                });
            }
        });
    });
    </script>
    <?php
}

    public function ajax_get_system_info() {
    if (!current_user_can('manage_options')) {
        wp_die('Brak uprawnień');
    }
    
    if (!wp_verify_nonce($_POST['nonce'], 'login_blocker_debug')) {
        wp_die('Błąd bezpieczeństwa');
    }
    
    wp_send_json_success($this->get_system_info());
}
    public function display_email_test_tab() {
        ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="card">
                <h3>Test Powiadomień Email</h3>
                
                <div style="margin-bottom: 20px;">
                    <h4>Konfiguracja Email</h4>
                    <?php
                    $smtp_enabled = get_option('login_blocker_smtp_enabled', false);
                    $notification_email = get_option('login_blocker_notification_email', get_option('admin_email'));
                    ?>
                    <table class="widefat" style="width: 100%;">
                        <tr>
                            <td><strong>Adres powiadomień:</strong></td>
                            <td><?php echo esc_html($notification_email); ?></td>
                        </tr>
                        <tr>
                            <td><strong>SMTP:</strong></td>
                            <td><?php echo $smtp_enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY (używany system WordPress)'; ?></td>
                        </tr>
                        <?php if ($smtp_enabled): ?>
                        <tr>
                            <td><strong>Serwer SMTP:</strong></td>
                            <td><?php echo esc_html(get_option('login_blocker_smtp_host', '')); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Port:</strong></td>
                            <td><?php echo esc_html(get_option('login_blocker_smtp_port', '587')); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                
                <div>
                    <h4>Wyślij Testowego Emaila</h4>
                    <button type="button" id="test-email-send" class="button button-primary">
                        Wyślij Testowego Emaila
                    </button>
                    <div id="email-test-result" style="margin-top: 10px;"></div>
                    <p class="description">Wyśle testową wiadomość na skonfigurowany adres email</p>
                </div>
            </div>
            
            <div class="card">
                <h3>Historia Powiadomień</h3>
                <div id="email-history">
                    <p>Funkcja historii powiadomień będzie dostępna w przyszłych wersjach.</p>
                </div>
                
                <div style="margin-top: 20px;">
                    <h4>Ustawienia Powiadomień</h4>
                    <p>
                        <strong>Powiadomienia o błędach:</strong> 
                        <?php echo get_option('login_blocker_error_notifications', true) ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?>
                    </p>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=login-blocker-settings'); ?>" class="button button-secondary">
                            Przejdź do Ustawień Email
                        </a>
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-email-send').on('click', function() {
                var $button = $(this);
                var $result = $('#email-test-result');
                
                $button.prop('disabled', true).text('Wysyłanie...');
                $result.html('<p><span class="spinner is-active"></span> Wysyłanie testowego emaila...</p>');
                
                $.post(ajaxurl, {
                    action: 'test_email_config',
                    nonce: '<?php echo wp_create_nonce('login_blocker_test_email'); ?>'
                }, function(response) {
                    $button.prop('disabled', false).text('Wyślij Testowego Emaila');
                    
                    if (response.success) {
                        $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                }).fail(function() {
                    $button.prop('disabled', false).text('Wyślij Testowego Emaila');
                    $result.html('<div class="notice notice-error"><p>Błąd połączenia z serwerem.</p></div>');
                });
            });
        });
        </script>
        <?php
    }

    private function display_plugin_info() {
        $plugin_data = get_plugin_data(LOGIN_BLOCKER_PLUGIN_PATH . 'login-blocker.php');
        ?>
        <table class="widefat" style="width: 100%;">
            <tr>
                <td><strong>Wersja:</strong></td>
                <td><?php echo esc_html($plugin_data['Version']); ?></td>
            </tr>
            <tr>
                <td><strong>Autor:</strong></td>
                <td><?php echo esc_html($plugin_data['Author']); ?></td>
            </tr>
            <tr>
                <td><strong>Opis:</strong></td>
                <td><?php echo esc_html($plugin_data['Description']); ?></td>
            </tr>
            <tr>
                <td><strong>Ścieżka:</strong></td>
                <td><code><?php echo esc_html(LOGIN_BLOCKER_PLUGIN_PATH); ?></code></td>
            </tr>
            <tr>
                <td><strong>URL:</strong></td>
                <td><code><?php echo esc_html(LOGIN_BLOCKER_PLUGIN_URL); ?></code></td>
            </tr>
        </table>
        <?php
    }

    private function display_log_stats() {
        $log_files = $this->admin->get_main_class()->get_log_files();
        $total_size = 0;
        $total_files = count($log_files);
        
        foreach ($log_files as $log_file) {
            $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
            if (file_exists($file_path)) {
                $total_size += filesize($file_path);
            }
        }
        ?>
        <table class="widefat" style="width: 100%;">
            <tr>
                <td><strong>Liczba plików:</strong></td>
                <td><?php echo number_format($total_files); ?></td>
            </tr>
            <tr>
                <td><strong>Łączny rozmiar:</strong></td>
                <td><?php echo $this->format_bytes($total_size); ?></td>
            </tr>
            <tr>
                <td><strong>Katalog logów:</strong></td>
                <td><code><?php echo esc_html(LOGIN_BLOCKER_LOG_PATH); ?></code></td>
            </tr>
            <tr>
                <td><strong>Dostęp do zapisu:</strong></td>
                <td>
                    <?php 
                    if (is_writable(LOGIN_BLOCKER_LOG_PATH)) {
                        echo '<span style="color: green;">✓ Dostępny</span>';
                    } else {
                        echo '<span style="color: red;">✗ Brak dostępu</span>';
                    }
                    ?>
                </td>
            </tr>
        </table>
        <?php
    }

    private function display_log_files_stats($log_files) {
        $total_size = 0;
        $newest_file = '';
        $newest_time = 0;
        
        foreach ($log_files as $log_file) {
            $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
            if (file_exists($file_path)) {
                $size = filesize($file_path);
                $total_size += $size;
                $mtime = filemtime($file_path);
                
                if ($mtime > $newest_time) {
                    $newest_time = $mtime;
                    $newest_file = $log_file;
                }
            }
        }
        ?>
        <table class="widefat" style="width: 100%;">
            <tr>
                <td><strong>Łączna liczba plików:</strong></td>
                <td><?php echo number_format(count($log_files)); ?></td>
            </tr>
            <tr>
                <td><strong>Łączny rozmiar:</strong></td>
                <td><?php echo $this->format_bytes($total_size); ?></td>
            </tr>
            <tr>
                <td><strong>Najnowszy plik:</strong></td>
                <td><?php echo $newest_file ? esc_html($newest_file) : 'Brak'; ?></td>
            </tr>
            <tr>
                <td><strong>Ostatnia modyfikacja:</strong></td>
                <td><?php echo $newest_time ? date('Y-m-d H:i:s', $newest_time) : '—'; ?></td>
            </tr>
        </table>
        <?php
    }

    private function get_log_file_info($log_file) {
        $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
        if (!file_exists($file_path)) {
            return 'Plik nie istnieje';
        }
        
        $size = filesize($file_path);
        $mtime = filemtime($file_path);
        
        return $this->format_bytes($size) . ' • ' . date('Y-m-d H:i:s', $mtime);
    }

    private function get_log_file_size($log_file) {
        $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
        return file_exists($file_path) ? $this->format_bytes(filesize($file_path)) : '0 B';
    }

    private function get_log_file_mtime($log_file) {
        $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
        return file_exists($file_path) ? date('Y-m-d H:i:s', filemtime($file_path)) : '—';
    }

    private function estimate_log_lines($log_file) {
        $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
        if (!file_exists($file_path)) {
            return '0';
        }
        
        $content = file_get_contents($file_path);
        $lines = substr_count($content, "\n");
        return number_format($lines);
    }

    private function get_log_download_url($log_file) {
        return wp_nonce_url(
            admin_url('admin.php?page=login-blocker-debug&download_log=' . urlencode($log_file)),
            'download_log_' . $log_file
        );
    }

    private function format_bytes($bytes) {
        if ($bytes === 0) return '0 B';
        
        $units = array('B', 'KB', 'MB', 'GB');
        $base = 1024;
        $class = min((int)log($bytes, $base), count($units) - 1);
        
        return sprintf('%s %s', number_format($bytes / pow($base, $class), 2), $units[$class]);
    }

    private function get_system_info() {
        global $wpdb;
        
        $info = "=== SYSTEM INFORMATION ===\n";
        $info .= "WordPress Version: " . get_bloginfo('version') . "\n";
        $info .= "PHP Version: " . phpversion() . "\n";
        $info .= "MySQL Version: " . $wpdb->db_version() . "\n";
        $info .= "WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? 'YES' : 'NO') . "\n";
        $info .= "WP_DEBUG_LOG: " . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'YES' : 'NO') . "\n";
        $info .= "Memory Limit: " . WP_MEMORY_LIMIT . "\n";
        $info .= "Login Blocker Version: " . LOGIN_BLOCKER_VERSION . "\n";
        $info .= "Table Exists: " . ($wpdb->get_var("SHOW TABLES LIKE '{$this->admin->get_table_name()}'") ? 'YES' : 'NO') . "\n";
        
        return $info;
    }
}
