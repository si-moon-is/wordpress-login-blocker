<?php
if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Admin_Analytics {
    
    private $main_class;
    private $admin_main;
    
    public function __construct($main_class, $admin_main) {
        $this->main_class = $main_class;
        $this->admin_main = $admin_main;
    }
    
    // Początek Strona ze statystykami
public function analytics_page() {
    global $wpdb;
    
    $period = isset($_GET['period']) ? intval($_GET['period']) : 30;
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
    
    $tabs = array(
        'overview' => 'Przegląd',
        'attempts' => 'Ostatnie Próby', 
        'charts' => 'Wykresy',
        'countries' => 'Kraje',
        'users' => 'Użytkownicy',
        'ips' => 'Adresy IP',
        'map' => 'Mapa Ataków'
    );
    
    ?>
    <div class="wrap">
        <h1>Login Blocker - Statystyki & Analizy</h1>
        
        <!-- Filtry okresu czasu -->
        <div class="card" style="margin-bottom: 20px;">
            <h3>Filtruj statystyki</h3>
            <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                <input type="hidden" name="page" value="login-blocker-analytics">
                <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">
                <label for="period">Okres czasu:</label>
                <select name="period" id="period" onchange="this.form.submit()">
                    <option value="7" <?php selected($period, 7); ?>>7 dni</option>
                    <option value="30" <?php selected($period, 30); ?>>30 dni</option>
                    <option value="90" <?php selected($period, 90); ?>>90 dni</option>
                    <option value="365" <?php selected($period, 365); ?>>1 rok</option>
                </select>
            </form>
        </div>
        
        <!-- Tabulatory -->
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab_key => $tab_name): ?>
                <a href="<?php echo admin_url('admin.php?page=login-blocker-analytics&tab=' . $tab_key . '&period=' . $period); ?>" 
                   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                   <?php echo esc_html($tab_name); ?>
                </a>
            <?php endforeach; ?>
        </h2>
        
        <!-- Zawartość zakładek -->
        <div class="login-blocker-tab-content">
            <?php 
            switch ($current_tab) {
                case 'overview':
                    $this->display_overview_tab($period);
                    break;
                    
                case 'attempts':
                    $this->display_attempts_tab($period);
                    break;
                    
                case 'charts':
                    $this->display_charts_tab($period);
                    break;
                    
                case 'countries':
                    $this->display_countries_tab($period);
                    break;
                    
                case 'users':
                    $this->display_users_tab($period);
                    break;
                    
                case 'ips':
                    $this->display_ips_tab($period);
                    break;
                    
                case 'map':
                    $this->display_map_tab($period);
                    break;
                    
                default:
                    $this->display_overview_tab($period);
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

// Nowe metody dla każdej zakładki:
public function display_overview_tab($period) {
    ?>
    <div class="login-blocker-stats-grid" style="margin-bottom: 20px;">
        <?php $this->main_class->display_stats_cards($period); ?>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <h3>Próby logowania w czasie</h3>
            <?php $this->main_class->display_attempts_chart($period); ?>
        </div>
        
        <div class="card">
            <h3>Rozkład geograficzny</h3>
            <?php $this->main_class->display_country_stats($period); ?>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
        <div class="card">
            <h3>Najczęściej atakowani użytkownicy</h3>
            <?php $this->main_class->display_top_users($period); ?>
        </div>
        
        <div class="card">
            <h3>Najaktywniejsze adresy IP</h3>
            <?php $this->main_class->display_top_ips($period); ?>
        </div>
    </div>
    <?php
}

public function display_attempts_tab($period) {
    global $wpdb;
    
    $recent_attempts = $wpdb->get_results(
        "SELECT * FROM {$this->table_name} 
         ORDER BY last_attempt DESC 
         LIMIT 200"
    );
    ?>
    <div class="card">
        <h3>Ostatnie próby logowania (200)</h3>
        <?php if ($recent_attempts): ?>
            <div style="overflow-x: auto; max-height: 800px;">
                <table class="wp-list-table widefat fixed striped" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>IP</th>
                            <th>Użytkownik</th>
                            <th>Próby</th>
                            <th>Status</th>
                            <th>Kraj</th>
                            <th style="width: 100px;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_attempts as $attempt): ?>
                            <tr>
                                <td><?php echo esc_html($attempt->last_attempt); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <?php if (!empty($attempt->country_code) && $attempt->country_code !== 'LOCAL'): ?>
                                            <?php 
                                                $country_code_lower = strtolower($attempt->country_code);
                                                $flag_url = "https://flagcdn.com/16x12/{$country_code_lower}.png";
                                            ?>
                                            <img src="<?php echo $flag_url; ?>" alt="<?php echo esc_attr($attempt->country_code); ?>" style="width: 16px; height: 12px;">
                                        <?php endif; ?>
                                        <span style="font-family: monospace; font-size: 12px;"><?php echo esc_html($attempt->ip_address); ?></span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($attempt->username); ?></td>
                                <td>
                                    <span style="font-weight: bold; color: <?php echo $attempt->attempts > 10 ? '#d63638' : '#0073aa'; ?>">
                                        <?php echo esc_html($attempt->attempts); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($attempt->is_blocked): ?>
                                        <span style="color: red; font-weight: bold; font-size: 12px;">BLOKADA</span>
                                    <?php else: ?>
                                        <span style="color: green; font-size: 12px;">aktywny</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo !empty($attempt->country_name) ? esc_html($attempt->country_name) : '—'; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 2px; flex-wrap: wrap;">
                                        <?php if ($attempt->is_blocked): ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=unblock&ip=' . $attempt->ip_address), 'login_blocker_action'); ?>" 
                                               class="button button-small" 
                                               title="Odblokuj IP">
                                               Odblokuj
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=delete&ip=' . $attempt->ip_address), 'login_blocker_action'); ?>" 
                                           class="button button-danger button-small" 
                                           onclick="return confirm('Usunąć?')"
                                           title="Usuń rekord">
                                           Usuń
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Brak zapisanych prób logowania.</p>
        <?php endif; ?>
    </div>
    <?php
}

public function display_charts_tab($period) {
    ?>
    <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
        <div class="card">
            <h3>Próby logowania w czasie</h3>
            <?php $this->main_class->display_attempts_chart($period); ?>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="card">
                <h3>Rozkład geograficzny</h3>
                <?php $this->main_class->display_country_stats($period); ?>
            </div>
            
            <div class="card">
                <h3>Najczęściej atakowani użytkownicy</h3>
                <?php $this->main_class->display_top_users($period); ?>
            </div>
        </div>
    </div>
    <?php
}

public function display_countries_tab($period) {
    global $wpdb;
    
    $country_stats = $wpdb->get_results($wpdb->prepare("
        SELECT country_code, country_name, 
               COUNT(*) as attempts,
               COUNT(DISTINCT ip_address) as unique_ips
        FROM {$this->table_name} 
        WHERE country_code != '' AND last_attempt >= %s
        GROUP BY country_code, country_name
        ORDER BY attempts DESC
    ", date('Y-m-d', strtotime("-$period days"))));
    ?>
    <div class="card">
        <h3>Statystyki krajów (<?php echo $period; ?> dni)</h3>
        <?php if ($country_stats): ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Kraj</th>
                            <th>Próby</th>
                            <th>Unikalne IP</th>
                            <th>Średnio na IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($country_stats as $country): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php if (!empty($country->country_code) && $country->country_code !== 'LOCAL'): ?>
                                            <?php 
                                                $country_code_lower = strtolower($country->country_code);
                                                $flag_url = "https://flagcdn.com/24x18/{$country_code_lower}.png";
                                            ?>
                                            <img src="<?php echo $flag_url; ?>" alt="<?php echo esc_attr($country->country_code); ?>" style="width: 24px; height: 18px;">
                                        <?php endif; ?>
                                        <span><?php echo esc_html($country->country_name ?: 'Nieznany'); ?></span>
                                        <code style="font-size: 11px; color: #666;"><?php echo esc_html($country->country_code); ?></code>
                                    </div>
                                </td>
                                <td style="font-weight: bold;"><?php echo number_format($country->attempts); ?></td>
                                <td><?php echo number_format($country->unique_ips); ?></td>
                                <td>
                                    <?php 
                                        $avg = $country->unique_ips > 0 ? $country->attempts / $country->unique_ips : 0;
                                        echo number_format($avg, 1);
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Brak danych geolokalizacji.</p>
        <?php endif; ?>
    </div>
    <?php
}

public function display_users_tab($period) {
    global $wpdb;
    
    $top_users = $wpdb->get_results($wpdb->prepare("
        SELECT username, 
               COUNT(*) as attempts,
               COUNT(DISTINCT ip_address) as unique_attackers
        FROM {$this->table_name} 
        WHERE username IS NOT NULL AND last_attempt >= %s
        GROUP BY username 
        ORDER BY attempts DESC
    ", date('Y-m-d', strtotime("-$period days"))));
    ?>
    <div class="card">
        <h3>Najczęściej atakowani użytkownicy (<?php echo $period; ?> dni)</h3>
        <?php if ($top_users): ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Użytkownik</th>
                            <th>Próby</th>
                            <th>Unikalni atakujący</th>
                            <th>Średnio na atakującego</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_users as $user): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($user->username); ?></strong>
                                </td>
                                <td style="font-weight: bold; color: #d63638;"><?php echo number_format($user->attempts); ?></td>
                                <td><?php echo number_format($user->unique_attackers); ?></td>
                                <td>
                                    <?php 
                                        $avg = $user->unique_attackers > 0 ? $user->attempts / $user->unique_attackers : 0;
                                        echo number_format($avg, 1);
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Brak danych.</p>
        <?php endif; ?>
    </div>
    <?php
}

public function display_ips_tab($period) {
    global $wpdb;
    
    $top_ips = $wpdb->get_results($wpdb->prepare("
        SELECT ip_address, country_code, country_name, city, 
               COUNT(*) as attempts,
               MAX(is_blocked) as is_blocked
        FROM {$this->table_name} 
        WHERE last_attempt >= %s
        GROUP BY ip_address, country_code, country_name, city
        ORDER BY attempts DESC
    ", date('Y-m-d', strtotime("-$period days"))));
    ?>
    <div class="card">
        <h3>Najaktywniejsze adresy IP (<?php echo $period; ?> dni)</h3>
        <?php if ($top_ips): ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Adres IP</th>
                            <th>Lokalizacja</th>
                            <th>Próby</th>
                            <th>Status</th>
                            <th style="width: 120px;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_ips as $ip): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: bold;"><?php echo esc_html($ip->ip_address); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <?php if (!empty($ip->country_code) && $ip->country_code !== 'LOCAL'): ?>
                                            <?php 
                                                $country_code_lower = strtolower($ip->country_code);
                                                $flag_url = "https://flagcdn.com/16x12/{$country_code_lower}.png";
                                            ?>
                                            <img src="<?php echo $flag_url; ?>" alt="<?php echo esc_attr($ip->country_code); ?>" title="<?php echo esc_attr($ip->country_name); ?>">
                                        <?php endif; ?>
                                        <span>
                                            <?php 
                                                echo esc_html($ip->city ?: '');
                                                if ($ip->city && $ip->country_name) echo ', ';
                                                echo esc_html($ip->country_name ?: '');
                                            ?>
                                        </span>
                                    </div>
                                </td>
                                <td style="font-weight: bold; color: #d63638;"><?php echo number_format($ip->attempts); ?></td>
                                <td>
                                    <?php if ($ip->is_blocked): ?>
                                        <span style="color: red; font-weight: bold;">BLOKADA</span>
                                    <?php else: ?>
                                        <span style="color: green;">aktywny</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 2px;">
                                        <?php if ($ip->is_blocked): ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=unblock&ip=' . $ip->ip_address), 'login_blocker_action'); ?>" 
                                               class="button button-small">
                                               Odblokuj
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=login-blocker-blocked&action=delete&ip=' . $ip->ip_address), 'login_blocker_action'); ?>" 
                                           class="button button-danger button-small" 
                                           onclick="return confirm('Usunąć?')">
                                           Usuń
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Brak danych.</p>
        <?php endif; ?>
    </div>
    <?php
}

public function display_map_tab($period) {
    ?>
    <div class="card">
        <h3>Mapa ataków (<?php echo $period; ?> dni)</h3>
        <?php $this->main_class->display_attack_map($period); ?>
    </div>
    <?php
}
    // Koniec Strona ze statystykami
}
