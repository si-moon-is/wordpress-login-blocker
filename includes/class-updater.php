<?php
if ( (isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'login_blocker') !== false) ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Brak dostępu' );
    }
    if ( isset($_REQUEST['_wpnonce']) ) {
        check_admin_referer( 'login_blocker_action' );
    }
}
/**
 * Login Blocker - Updater Class
 * Handles automatic updates from GitHub
 */

if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Updater {
    private $slug;
    private $plugin_data;
    private $username;
    private $repo;
    private $plugin_file;
    private $cache_key;
    private $cache_duration;
    private $github_url;

    function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->username = 'si-moon-is';
        $this->repo = 'wordpress-login-blocker';
        $this->github_url = 'https://github.com/si-moon-is/wordpress-login-blocker';
        $this->cache_key = 'login_blocker_github_latest';
        $this->cache_duration = HOUR_IN_SECONDS * 12;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'set_transient'));
        add_filter('plugins_api', array($this, 'set_plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
        
        // DODANE: Akcja dla ręcznego sprawdzania
        add_action('admin_init', array($this, 'maybe_clear_cache'));
        
        // DODANE: Pokazanie informacji o wersji
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
    }

    /**
     * DODANE: Czyśczenie cache przy ręcznym sprawdzaniu
     */
    public function maybe_clear_cache() {
        if (isset($_GET['force-check']) && $_GET['force-check'] == '1') {
            $this->clear_update_cache();
        }
    }

    /**
     * DODANE: Czyszczenie cache aktualizacji
     */
    public function clear_update_cache() {
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
    }

    private function init_plugin_data() {
        $this->slug = plugin_basename($this->plugin_file);
        $this->plugin_data = get_plugin_data($this->plugin_file);
    }

    private function get_repository_info() {
        // SPRAWDŹ CACHE
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases/latest";
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'WordPress-Login-Blocker-Plugin/1.0',
                'Accept' => 'application/vnd.github.v3+json'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Login Blocker Updater: ' . esc_html($response->get_error_message()));
            set_transient($this->cache_key, array(), 15 * MINUTE_IN_SECONDS); // Krótki cache przy błędzie
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log("Login Blocker Updater: GitHub API returned status {$status_code}");
            set_transient($this->cache_key, array(), 15 * MINUTE_IN_SECONDS);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $repo_info = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Login Blocker Updater: Invalid JSON response from GitHub');
            set_transient($this->cache_key, array(), 15 * MINUTE_IN_SECONDS);
            return false;
        }

        // WALIDACJA STRUKTURY ODPOWIEDZI
        if (!$this->validate_github_response($repo_info)) {
            error_log('Login Blocker Updater: Invalid GitHub response structure');
            set_transient($this->cache_key, array(), 15 * MINUTE_IN_SECONDS);
            return false;
        }

        // ZAPISZ DO CACHE
        set_transient($this->cache_key, $repo_info, $this->cache_duration);

        return $repo_info;
    }

    private function validate_github_response($data) {
        return (
            isset($data['tag_name']) && 
            isset($data['published_at']) && 
            isset($data['zipball_url']) &&
            is_string($data['tag_name']) &&
            is_string($data['zipball_url'])
        );
    }

    private function sanitize_version($version) {
        $clean_version = ltrim($version, 'v');
        
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $clean_version)) {
            error_log("Login Blocker Updater: Invalid version format: {$clean_version}");
            return false;
        }
        
        return $clean_version;
    }

    public function set_transient($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $this->init_plugin_data();
        $repo_info = $this->get_repository_info();

        if (!$repo_info) {
            return $transient;
        }

        $latest_version = $this->sanitize_version($repo_info['tag_name']);
        $current_version = $this->plugin_data['Version'];

        if (!$latest_version) {
            return $transient;
        }

        if (version_compare($current_version, $latest_version, '<')) {
            $package = esc_url_raw($repo_info['zipball_url']);

            if (!filter_var($package, FILTER_VALIDATE_URL)) {
                error_log('Login Blocker Updater: Invalid package URL');
                return $transient;
            }

            $obj = new stdClass();
            $obj->slug = $this->slug;
            $obj->new_version = sanitize_text_field($latest_version);
            $obj->url = esc_url_raw($this->plugin_data['PluginURI']);
            $obj->package = $package;
            $obj->plugin = $this->slug;
            
            // DODANE: Źródło aktualizacji
            $obj->source = $package;
            $obj->id = $this->github_url;

            $transient->response[$this->slug] = $obj;
        } else {
            // DODANE: Informacja że wtyczka jest aktualna
            if (!isset($transient->no_update)) {
                $transient->no_update = array();
            }
            $transient->no_update[$this->slug] = true;
        }

        return $transient;
    }

    public function set_plugin_info($false, $action, $response) {
        $this->init_plugin_data();

        if (empty($response->slug) || $response->slug !== $this->slug) {
            return $false;
        }

        $repo_info = $this->get_repository_info();

        if (!$repo_info) {
            return $false;
        }

        $latest_version = $this->sanitize_version($repo_info['tag_name']);
        if (!$latest_version) {
            return $false;
        }

        // KOMPLETNA INFORMACJA O WTYCZCE
        $response->last_updated = sanitize_text_field($repo_info['published_at']);
        $response->slug = $this->slug;
        $response->name = sanitize_text_field($this->plugin_data['Name']);
        $response->version = $latest_version;
        $response->author = sanitize_text_field($this->plugin_data['Author']);
        $response->homepage = esc_url_raw($this->plugin_data['PluginURI']);
        $response->download_link = esc_url_raw($repo_info['zipball_url']);
        $response->requires = '5.6';
        $response->tested = '6.4';
        $response->requires_php = '7.4';
        
        // DODANE: Sekcje informacyjne
        $response->sections = array(
            'description' => wp_kses_post($this->plugin_data['Description']),
            'changelog' => $this->get_changelog_section($repo_info)
        );

        // DODANE: Baner i ikony
        $response->banners = array(
            'low' => esc_url_raw(plugin_dir_url($this->plugin_file) . 'assets/images/banner-772x250.jpg'),
        );

        return $response;
    }

    /**
     * DODANE: Sekcja changelog z GitHub
     */
    private function get_changelog_section($repo_info) {
        $changelog = '<h3>Latest Release: ' . esc_html($repo_info['tag_name']) . '</h3>';
        $changelog .= '<p><strong>Released:</strong> ' . esc_html(date('Y-m-d', strtotime($repo_info['published_at']))) . '</p>';
        
        if (!empty($repo_info['body'])) {
            $changelog .= wp_kses_post(wpautop($repo_info['body']));
        } else {
            $changelog .= '<p>No release notes available.</p>';
        }

        $changelog .= '<p><a href="' . esc_url($this->github_url . '/releases') . '" target="_blank">View all releases on GitHub</a></p>';

        return $changelog;
    }

    /**
     * DODANE: Informacje w wierszu wtyczki
     */
    public function plugin_row_meta($links, $file) {
        if ($file === $this->slug) {
            $repo_info = $this->get_repository_info();
            
            if ($repo_info) {
                $latest_version = $this->sanitize_version($repo_info['tag_name']);
                $current_version = $this->plugin_data['Version'];
                
                if ($latest_version && version_compare($current_version, $latest_version, '<')) {
                    $links[] = '<strong><span style="color:#d63638">⚠️ Update available: ' . esc_html($latest_version) . '</span></strong>';
                } else {
                    $links[] = '<span style="color:#00a32a">✅ Up to date</span>';
                }
                
                $links[] = '<a href="' . esc_url($this->github_url) . '" target="_blank">GitHub Repository</a>';
            }
        }

        return $links;
    }

    public function post_install($true, $hook_extra, $result) {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->slug) {
            return $true;
        }

        $plugin_path = plugin_dir_path($this->plugin_file);
        
        if (!$wp_filesystem->exists($result['destination']) || !$wp_filesystem->exists($plugin_path)) {
            error_log('Login Blocker Updater: Invalid paths during post-install');
            return $true;
        }

        $move_result = $wp_filesystem->move($result['destination'], $plugin_path, true);
        
        if (!$move_result) {
            error_log('Login Blocker Updater: Failed to move files during update');
            return new WP_Error('move_failed', 'Failed to move updated files.');
        }

        $result['destination'] = $plugin_path;

        // WYCZYŚĆ CACHE
        $this->clear_update_cache();

        // DODANE: Akcja po udanej aktualizacji
        do_action('login_blocker_after_update', $this->plugin_data['Version'], $hook_extra);

        return $result;
    }

    /**
     * DODANE: Status aktualizacji dla dashboarda
     */
    public function get_update_status() {
        $repo_info = $this->get_repository_info();
        
        if (!$repo_info) {
            return array(
                'status' => 'error',
                'message' => 'Cannot connect to GitHub'
            );
        }

        $latest_version = $this->sanitize_version($repo_info['tag_name']);
        $current_version = $this->plugin_data['Version'];

        if (!$latest_version) {
            return array(
                'status' => 'error', 
                'message' => 'Invalid version format'
            );
        }

        if (version_compare($current_version, $latest_version, '<')) {
            return array(
                'status' => 'update_available',
                'current' => $current_version,
                'latest' => $latest_version,
                'url' => esc_url($repo_info['html_url'])
            );
        }

        return array(
            'status' => 'up_to_date',
            'current' => $current_version,
            'latest' => $latest_version
        );
    }
}

// Inicjalizacja updatera tylko jeśli nie ma konfliktów
if (!class_exists('LoginBlocker_Updater_Initialized')) {
    class LoginBlocker_Updater_Initialized {
        private static $instance = null;
        
        public static function init($plugin_file) {
            if (self::$instance === null) {
                self::$instance = new LoginBlocker_Updater($plugin_file);
            }
            return self::$instance;
        }
    }
    
    // Zainicjuj updater
    LoginBlocker_Updater_Initialized::init(__FILE__);
}
