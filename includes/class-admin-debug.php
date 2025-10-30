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

    // Nowe metody dla zakładek debug:
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
                        <p class="description">Testuje zapis rekordu do tabeli login_blocker_attempts</p
