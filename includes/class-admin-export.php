<?php
/**
 * Login Blocker - Admin Export Class
 * Handles data export functionality
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Eksport Danych Login Blocker', 'login-blocker'); ?></h1>

            <div class="card">
                <h2><?php echo esc_html__('Eksport Prób Logowania', 'login-blocker'); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url('admin.php') ); ?>">
                    <input type="hidden" name="login_blocker_export" value="1">
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
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php echo esc_html__('Eksportuj Dane', 'login-blocker'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2><?php echo esc_html__('Eksport Statystyk', 'login-blocker'); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url('admin.php') ); ?>">
                    <input type="hidden" name="login_blocker_export" value="1">
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
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-secondary">
                            <?php echo esc_html__('Eksportuj Statystyki', 'login-blocker'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
