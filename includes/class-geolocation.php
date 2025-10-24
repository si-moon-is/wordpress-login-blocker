<?php
/**
 * Login Blocker - Geolocation Class
 * Handles IP geolocation functionality
 */

// DODANE: Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

class LoginBlocker_Geolocation {
    
    private $cache_duration;
    
    public function __construct() {
        $this->cache_duration = HOUR_IN_SECONDS * 6; // 6 godzin
    }
    
    public function get_location($ip) {
        // WALIDACJA ADRESU IP
        if (!$this->is_valid_ip($ip)) {
            return $this->get_unknown_location();
        }
        
        // Pomijanie prywatnych IP
        if ($this->is_private_ip($ip)) {
            return $this->get_private_ip_location();
        }
        
        $cached = $this->get_cached_location($ip);
        if ($cached !== false) {
            return $cached;
        }
        
        $location = $this->fetch_location($ip);
        $this->cache_location($ip, $location);
        
        return $location;
    }

    public function get_country_code($ip) {
        $location = $this->get_location($ip);
        return $location['country_code'] ?? 'XX';
    }

    private function is_valid_ip($ip) {
        if (!is_string($ip) || empty($ip)) {
            return false;
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    private function is_private_ip($ip) {
        if (!$this->is_valid_ip($ip)) {
            return false;
        }
        
        // Sprawdź zakresy prywatnych IP używając filter_var
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
    
    private function get_private_ip_location() {
        return array(
            'country_code' => 'LOCAL',
            'country_name' => 'Local Network',
            'city' => 'Local',
            'region' => 'Local',
            'isp' => 'Local',
            'latitude' => null,
            'longitude' => null
        );
    }
    
    private function get_cached_location($ip) {
        $transient_key = 'login_blocker_geo_' . md5($ip);
        $cached = get_transient($transient_key);
        
        // Sprawdź czy cache nie wygasł
        if ($cached === false) {
            return false;
        }

        // Sprawdź strukturę danych
        if (!is_array($cached) || !isset($cached['country_code'])) {
            delete_transient($transient_key);
            return false;
        }
        
        return $cached;
    }
    
    private function cache_location($ip, $location) {
        $transient_key = 'login_blocker_geo_' . md5($ip);
        set_transient($transient_key, $location, $this->cache_duration);
    }

    private function can_make_request() {
        $transient_key = 'login_blocker_geo_rate_limit';
        $request_count = get_transient($transient_key) ?: 0;
        
        // Max 100 zapytań na godzinę
        if ($request_count >= 100) {
            error_log('Login Blocker: Limit zapytań geolokalizacji przekroczony');
            return false;
        }
        
        set_transient($transient_key, $request_count + 1, HOUR_IN_SECONDS);
        return true;
    }

    private function fetch_location($ip) {
        // SPRAWDŹ RATE LIMITING
        if (!$this->can_make_request()) {
            return $this->get_unknown_location();
        }
        
        $services = array(
            array('ip-api.com', "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,city,region,isp,lat,lon,query"),
            array('ipapi.co', "https://ipapi.co/{$ip}/json/")
        );
        
        foreach ($services as $service) {
            list($name, $url) = $service;
            $location = $this->fetch_from_service($name, $url);
            
            if (!empty($location)) {
                return $this->format_location_data($location, $name);
            }
            
            // Krótka przerwa między serwisami
            sleep(1);
        }
        
        return $this->get_unknown_location();
    }
    
    private function fetch_from_service($service_name, $url) {
        $args = array(
            'timeout' => 5,
            'user-agent' => 'WordPress-Login-Blocker-Plugin/1.0'
        );
        
        if ($service_name === 'ipapi.co') {
            $args['headers'] = array('User-Agent' => 'WordPress-Login-Blocker-Plugin/1.0');
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            error_log("Login Blocker: Błąd geolokalizacji z " . esc_html($service_name) . ": " . esc_html($response->get_error_message()));
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            error_log("Login Blocker: Błąd HTTP {$status_code} z " . esc_html($service_name));
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Login Blocker: Nieprawidłowa odpowiedź JSON z " . esc_html($service_name));
            return null;
        }
        
        if ($service_name === 'ip-api.com' && isset($data['status']) && $data['status'] === 'success') {
            return $data;
        }
        
        if ($service_name === 'ipapi.co' && !isset($data['error'])) {
            return $data;
        }
        
        return null;
    }
    
    private function format_location_data($data, $service_name) {
        // FUNKCJA POMOCNICZA DO BEZPIECZNEGO FORMATOWANIA
        $safe_string = function($value, $max_length = 100) {
            if (!is_string($value)) {
                return '';
            }
            return substr(sanitize_text_field($value), 0, $max_length);
        };
        
        if ($service_name === 'ip-api.com') {
            return array(
                'country_code' => $safe_string($data['countryCode'] ?? '', 2),
                'country_name' => $safe_string($data['country'] ?? '', 100),
                'city' => $safe_string($data['city'] ?? '', 100),
                'region' => $safe_string($data['regionName'] ?? '', 100),
                'isp' => $safe_string($data['isp'] ?? '', 255),
                'latitude' => isset($data['lat']) ? floatval($data['lat']) : null,
                'longitude' => isset($data['lon']) ? floatval($data['lon']) : null
            );
        }
        
        if ($service_name === 'ipapi.co') {
            return array(
                'country_code' => $safe_string($data['country_code'] ?? '', 2),
                'country_name' => $safe_string($data['country_name'] ?? '', 100),
                'city' => $safe_string($data['city'] ?? '', 100),
                'region' => $safe_string($data['region'] ?? '', 100),
                'isp' => $safe_string($data['org'] ?? '', 255),
                'latitude' => isset($data['latitude']) ? floatval($data['latitude']) : null,
                'longitude' => isset($data['longitude']) ? floatval($data['longitude']) : null
            );
        }
        
        return $this->get_unknown_location();
    }
    
    private function get_unknown_location() {
        return array(
            'country_code' => 'XX',
            'country_name' => 'Unknown',
            'city' => 'Unknown',
            'region' => 'Unknown',
            'isp' => 'Unknown',
            'latitude' => null,
            'longitude' => null
        );
    }
}