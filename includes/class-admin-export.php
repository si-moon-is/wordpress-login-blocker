<?php
/**
 * Login Blocker - Admin Export Class
 * Handles data export functionality with tabs
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Admin_Export {
    
    private $admin;
    
    public function __construct($admin) {
        $this->admin = $admin;
    }
    
    public function export_page() {
        if (!current_user_can('export')) {
            wp_die(esc_html__('Brak uprawnień', 'login-blocker'));
        }

        // Pobierz aktualną zakładkę
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'data';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Eksport Danych Login Blocker', 'login-blocker'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=login-blocker-export&tab=data')); ?>" 
                   class="nav-tab <?php echo $current_tab === 'data' ? 'nav-tab-active' : ''; ?>">
                   <?php echo esc_html__('Eksport Prób Logowania', 'login-blocker'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=login-blocker-export&tab=stats')); ?>" 
                   class="nav-tab <?php echo $current_tab === 'stats' ? 'nav-tab-active' : ''; ?>">
                   <?php echo esc_html__('Eksport Statystyk', 'login-blocker'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=login-blocker-export&tab=logs')); ?>" 
                   class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                   <?php echo esc_html__('Eksport Logów', 'login-blocker'); ?>
                </a>
            </h2>
            
            <div class="login-blocker-tab-content">
                <?php
                switch ($current_tab) {
                    case 'stats':
                        $this->render_stats_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    case 'data':
                    default:
                        $this->render_data_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Zakładka: Eksport danych logowania
     */
    private function render_data_tab() {
        ?>
        <div class="login-blocker-tab-section">
            <div class="card">
                <h3><?php echo esc_html__('Eksport Prób Logowania', 'login-blocker'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="login_blocker_export">
                    <input type="hidden" name="type" value="data">
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
                        <tr>
                            <th scope="row">
                                <label for="export-status"><?php echo esc_html__('Status IP', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <select name="status" id="export-status">
                                    <option value="all"><?php echo esc_html__('Wszystkie', 'login-blocker'); ?></option>
                                    <option value="blocked"><?php echo esc_html__('Tylko zablokowane', 'login-blocker'); ?></option>
                                    <option value="active"><?php echo esc_html__('Tylko aktywne', 'login-blocker'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-download"></span>
                            <?php echo esc_html__('Eksportuj Dane', 'login-blocker'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h3><?php echo esc_html__('Informacje o Eksporcie', 'login-blocker'); ?></h3>
                <div class="export-info">
                    <p><strong><?php echo esc_html__('Dane uwzględnione w eksporcie:', 'login-blocker'); ?></strong></p>
                    <ul>
                        <li><?php echo esc_html__('Adres IP', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Nazwa użytkownika', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Liczba prób', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Status blokady', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Data blokady', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Dane geolokalizacji', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Ostatnia próba', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Data utworzenia', 'login-blocker'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Zakładka: Eksport statystyk
     */
    private function render_stats_tab() {
        ?>
        <div class="login-blocker-tab-section">
            <div class="card">
                <h3><?php echo esc_html__('Eksport Statystyk', 'login-blocker'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="login_blocker_export">
                    <input type="hidden" name="type" value="stats">
                    <?php wp_nonce_field('login_blocker_export', 'export_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="stats-period"><?php echo esc_html__('Okres (dni)', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="period" id="stats-period" 
                                       min="1" max="365" value="30" required>
                                <p class="description"><?php echo esc_html__('Statystyki z ostatnich X dni', 'login-blocker'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="stats-format"><?php echo esc_html__('Format', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <select name="format" id="stats-format" required>
                                    <option value="json">JSON</option>
                                    <option value="csv">CSV</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-secondary">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <?php echo esc_html__('Eksportuj Statystyki', 'login-blocker'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h3><?php echo esc_html__('Podgląd Statystyk', 'login-blocker'); ?></h3>
                <div id="stats-preview">
                    <p><?php echo esc_html__('Kliknij przycisk poniżej, aby wygenerować podgląd statystyk:', 'login-blocker'); ?></p>
                    <button type="button" id="preview-stats" class="button">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php echo esc_html__('Podgląd Statystyk', 'login-blocker'); ?>
                    </button>
                    <div id="stats-preview-content" style="margin-top: 15px; display: none;"></div>
                </div>
            </div>

            <div class="card">
                <h3><?php echo esc_html__('Zawartość Eksportu Statystyk', 'login-blocker'); ?></h3>
                <div class="export-stats-info">
                    <p><strong><?php echo esc_html__('Statystyki uwzględnione w eksporcie:', 'login-blocker'); ?></strong></p>
                    <ul>
                        <li><?php echo esc_html__('Łączna liczba prób logowania', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Liczba zablokowanych prób', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Unikalne adresy IP', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Unikalne kraje', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Obecnie zablokowane IP', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Top 10 krajów ataków', 'login-blocker'); ?></li>
                        <li><?php echo esc_html__('Top 10 aktywnych IP', 'login-blocker'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#preview-stats').on('click', function() {
                var $button = $(this);
                var $content = $('#stats-preview-content');
                var period = $('#stats-period').val();
                
                $button.prop('disabled', true).text('Ładowanie...');
                $content.html('<p>Ładowanie statystyk...</p>').show();
                
                $.post(ajaxurl, {
                    action: 'login_blocker_get_stats_preview',
                    period: period,
                    nonce: '<?php echo wp_create_nonce('login_blocker_export'); ?>'
                }, function(response) {
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-visibility"></span> <?php echo esc_js(__('Podgląd Statystyk', 'login-blocker')); ?>');
                    
                    if (response.success) {
                        var stats = response.data;
                        var html = '<div class="export-stats-cards">';
                        
                        // Karty statystyk
                        html += '<div class="export-stat-card">';
                        html += '<div class="export-stat-number">' + stats.total_attempts + '</div>';
                        html += '<div class="export-stat-label">Łączne próby</div>';
                        html += '</div>';
                        
                        html += '<div class="export-stat-card">';
                        html += '<div class="export-stat-number">' + stats.blocked_attempts + '</div>';
                        html += '<div class="export-stat-label">Zablokowane</div>';
                        html += '</div>';
                        
                        html += '<div class="export-stat-card">';
                        html += '<div class="export-stat-number">' + stats.unique_ips + '</div>';
                        html += '<div class="export-stat-label">Unikalne IP</div>';
                        html += '</div>';
                        
                        html += '<div class="export-stat-card">';
                        html += '<div class="export-stat-number">' + stats.unique_countries + '</div>';
                        html += '<div class="export-stat-label">Kraje</div>';
                        html += '</div>';
                        
                        html += '</div>';
                        
                        $content.html(html);
                    } else {
                        $content.html('<p class="error">Błąd ładowania statystyk.</p>');
                    }
                }).fail(function() {
                    $button.prop('disabled', false);
                    $content.html('<p class="error">Błąd połączenia z serwerem.</p>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Zakładka: Eksport logów
     */
    private function render_logs_tab() {
        $main_class = $this->admin->get_main_class();
        $log_files = $main_class->get_log_files();
        ?>
        <div class="login-blocker-tab-section">
            <div class="card">
                <h3><?php echo esc_html__('Eksport Logów Systemowych', 'login-blocker'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="login_blocker_export">
                    <input type="hidden" name="type" value="logs">
                    <?php wp_nonce_field('login_blocker_export', 'export_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="log-date"><?php echo esc_html__('Data logów', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <input type="date" name="log_date" id="log-date" 
                                       value="<?php echo esc_attr(date('Y-m-d')); ?>">
                                <p class="description"><?php echo esc_html__('Wybierz konkretny dzień lub pozostaw puste dla wszystkich logów', 'login-blocker'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="log-level"><?php echo esc_html__('Poziom logów', 'login-blocker'); ?></label>
                            </th>
                            <td>
                                <select name="log_level" id="log-level">
                                    <option value="all"><?php echo esc_html__('Wszystkie', 'login-blocker'); ?></option>
                                    <option value="error"><?php echo esc_html__('Tylko błędy', 'login-blocker'); ?></option>
                                    <option value="warning"><?php echo esc_html__('Tylko ostrzeżenia', 'login-blocker'); ?></option>
                                    <option value="info"><?php echo esc_html__('Tylko informacje', 'login-blocker'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button">
                            <span class="dashicons dashicons-media-text"></span>
                            <?php echo esc_html__('Eksportuj Logi', 'login-blocker'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h3><?php echo esc_html__('Dostępne Pliki Logów', 'login-blocker'); ?></h3>
                <?php if (!empty($log_files)): ?>
                    <div class="log-files-export">
                        <p><?php echo esc_html__('Kliknij na nazwę pliku, aby pobrać:', 'login-blocker'); ?></p>
                        <ul class="log-files-list">
                            <?php foreach ($log_files as $log_file): 
                                $file_path = LOGIN_BLOCKER_LOG_PATH . $log_file;
                                $file_size = file_exists($file_path) ? size_format(filesize($file_path), 2) : '0 B';
                            ?>
                                <li>
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=login_blocker_export&type=logs&log_file=' . $log_file), 
                                        'login_blocker_export', 
                                        'export_nonce'
                                    )); ?>" class="log-file-link">
                                        <span class="dashicons dashicons-media-text"></span>
                                        <?php echo esc_html($log_file); ?> 
                                        <span class="file-size">(<?php echo $file_size; ?>)</span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <p><?php echo esc_html__('Brak plików logów.', 'login-blocker'); ?></p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3><?php echo esc_html__('Informacje o Logach', 'login-blocker'); ?></h3>
                <div class="logs-info">
                    <p><strong><?php echo esc_html__('Poziomy logów:', 'login-blocker'); ?></strong></p>
                    <ul>
                        <li><strong>ERROR:</strong> <?php echo esc_html__('Błędy krytyczne wymagające interwencji', 'login-blocker'); ?></li>
                        <li><strong>WARNING:</strong> <?php echo esc_html__('Ostrzeżenia i nieprawidłowości', 'login-blocker'); ?></li>
                        <li><strong>INFO:</strong> <?php echo esc_html__('Informacje o działaniach systemu', 'login-blocker'); ?></li>
                        <li><strong>DEBUG:</strong> <?php echo esc_html__('Szczegółowe informacje diagnostyczne', 'login-blocker'); ?></li>
                    </ul>
                    <p class="description"><?php echo esc_html__('Logi są automatycznie czyszczone po 30 dniach.', 'login-blocker'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }
}
