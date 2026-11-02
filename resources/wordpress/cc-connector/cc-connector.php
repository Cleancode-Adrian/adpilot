<?php
/**
 * Plugin Name:       CC Connector
 * Description:       Łączy stronę z panelem opieki CleanCode AS: wersje WordPress i PHP, aktualizacje wtyczek i motywów, administratorzy, monitoring zmian w plikach i ważnych zdarzeń.
 * Version:           1.0.1
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            CleanCode AS
 * License:           GPL-2.0-or-later
 * Text Domain:       cc-connector
 *
 * Instalacja zalecana: jako mu-plugin – plik wgrany do wp-content/mu-plugins/ (z wbudowanym kluczem)
 * działa od razu i nie da się go wyłączyć z kokpitu. Widoczny w Wtyczki → „Obowiązkowe”.
 *
 * Bezpieczeństwo: wtyczka niczego nie wykonuje na polecenie z zewnątrz. Panel może jedynie
 * poprosić (podpisanym zapytaniem HMAC) o raport stanu strony i skan plików. Wszystko inne
 * to wysyłanie informacji z tej strony do panelu.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class CC_Connector
{
    const VERSION = '1.0.1';
    /** Klucz wbudowany przy pobieraniu z panelu (wersja mu-plugin). Puste = klucz z ustawień lub stałej CC_CONNECTOR_KEY. */
    const EMBEDDED_KEY = '';
    const OPT_KEY = 'cc_connector_key';
    const OPT_STATE = 'cc_connector_state';
    const OPT_BASELINE = 'cc_connector_baseline';
    const OPT_PENDING = 'cc_connector_pending';

    /** Zdarzenia z bieżącego żądania – wysyłane razem na końcu (shutdown). */
    private static $queue = array();
    const CRON = 'cc_connector_daily';
    const MAX_FILES = 40000;
    const SCAN_EXT = array('php', 'phtml', 'phar', 'php5', 'php7', 'phps', 'inc', 'htaccess');

    public static function init()
    {
        add_action('init', array(__CLASS__, 'ensure_scheduled'));
        add_action('cc_connector_first_push', array(__CLASS__, 'push_full_report'));
        if (! self::key_is_fixed()) {
            add_action('admin_menu', array(__CLASS__, 'menu'));
        }
        add_action('admin_post_cc_connector_save', array(__CLASS__, 'save_settings'));
        add_action('admin_post_cc_connector_test', array(__CLASS__, 'test_connection'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action(self::CRON, array(__CLASS__, 'daily'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(__CLASS__, 'action_links'));

        // Zdarzenia
        add_action('user_register', array(__CLASS__, 'on_user_register'), 20, 1);
        add_action('set_user_role', array(__CLASS__, 'on_set_user_role'), 20, 3);
        add_action('add_user_role', array(__CLASS__, 'on_add_user_role'), 20, 2);
        add_action('grant_super_admin', array(__CLASS__, 'on_grant_super_admin'), 20, 1);
        add_action('delete_user', array(__CLASS__, 'on_delete_user'), 10, 1);
        add_action('wp_login', array(__CLASS__, 'on_login'), 10, 2);
        add_action('wp_login_failed', array(__CLASS__, 'on_login_failed'), 10, 1);
        add_action('activated_plugin', array(__CLASS__, 'on_plugin_activated'), 10, 1);
        add_action('deactivated_plugin', array(__CLASS__, 'on_plugin_deactivated'), 10, 1);
        add_action('deleted_plugin', array(__CLASS__, 'on_plugin_deleted'), 10, 2);
        add_action('switch_theme', array(__CLASS__, 'on_switch_theme'), 10, 1);
        add_action('upgrader_process_complete', array(__CLASS__, 'on_upgrade'), 20, 2);
        add_action('wp_ajax_edit-theme-plugin-file', array(__CLASS__, 'on_file_editor'), 0);
    }

    // ------------------------------------------------------------------ konfiguracja

    /** Klucz z panelu: "cc1." + base64url(JSON {u: adres panelu, s: id strony, k: sekret}). */
    private static function config()
    {
        $raw = self::key_is_fixed()
            ? (self::EMBEDDED_KEY !== '' ? self::EMBEDDED_KEY : (string) CC_CONNECTOR_KEY)
            : trim((string) get_option(self::OPT_KEY, ''));
        if (strpos($raw, 'cc1.') !== 0) {
            return null;
        }
        $json = base64_decode(strtr(substr($raw, 4), '-_', '+/'), true);
        $c = $json ? json_decode($json, true) : null;
        if (! is_array($c) || empty($c['u']) || empty($c['s']) || empty($c['k'])) {
            return null;
        }
        if (strpos($c['u'], 'https://') !== 0 && strpos($c['u'], 'http://localhost') !== 0 && strpos($c['u'], 'http://127.0.0.1') !== 0) {
            return null; // tylko HTTPS
        }

        return array('url' => rtrim($c['u'], '/'), 'site' => (string) $c['s'], 'secret' => (string) $c['k']);
    }

    /** Klucz wbudowany w plik albo zdefiniowany w wp-config.php – wtedy bez strony ustawień. */
    private static function key_is_fixed()
    {
        return self::EMBEDDED_KEY !== '' || (defined('CC_CONNECTOR_KEY') && CC_CONNECTOR_KEY);
    }

    /**
     * mu-pluginy nie mają aktywacji – harmonogram i pierwsze połączenie ustawiamy przy starcie.
     * Pierwszy raport idzie w tle (wp-cron), żeby nie spowalniać wejścia na stronę.
     */
    public static function ensure_scheduled()
    {
        if (! self::config()) {
            return;
        }
        if (! wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON);
        }
        $s = self::state();
        if (empty($s['last_ok']) && (empty($s['first_try']) || $s['first_try'] < time() - 15 * MINUTE_IN_SECONDS)) {
            self::state(array('first_try' => time()));
            if (! wp_next_scheduled('cc_connector_first_push')) {
                wp_schedule_single_event(time() + 5, 'cc_connector_first_push');
            }
        }
    }

    private static function state($patch = null)
    {
        $s = get_option(self::OPT_STATE, array());
        $s = is_array($s) ? $s : array();
        if (is_array($patch)) {
            $s = array_merge($s, $patch);
            update_option(self::OPT_STATE, $s, false);
        }

        return $s;
    }

    public static function activate()
    {
        if (! wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON);
        self::event('connector_deactivated');
        self::flush();
    }

    // ------------------------------------------------------------------ komunikacja z panelem

    private static function sign($secret, $timestamp, $body)
    {
        return hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    }

    /** Wysyła dane do panelu. Zdarzenia nie blokują strony (blocking = false). */
    public static function send($kind, $data, $blocking = false, $timeout = null)
    {
        $c = self::config();
        if (! $c) {
            return new WP_Error('cc_not_configured', 'Brak klucza połączenia.');
        }
        $body = wp_json_encode(array(
            'kind' => $kind,
            'site_url' => home_url('/'),
            'connector' => self::VERSION,
            'sent_at' => gmdate('c'),
            'data' => $data,
        ));
        $ts = (string) time();
        $res = wp_remote_post($c['url'] . '/api/wp/push', array(
            'timeout' => $timeout ? $timeout : ($blocking ? 25 : 3),
            'blocking' => $blocking,
            'user-agent' => 'CC-Connector/' . self::VERSION . '; ' . home_url('/'),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-CC-Site' => $c['site'],
                'X-CC-Timestamp' => $ts,
                'X-CC-Signature' => self::sign($c['secret'], $ts, $body),
            ),
            'body' => $body,
        ));
        if ($blocking) {
            if (is_wp_error($res)) {
                self::state(array('last_error' => $res->get_error_message(), 'last_error_at' => time()));
            } elseif (wp_remote_retrieve_response_code($res) >= 300) {
                $msg = 'Panel odpowiedział kodem ' . wp_remote_retrieve_response_code($res);
                $j = json_decode(wp_remote_retrieve_body($res), true);
                if (is_array($j) && ! empty($j['message'])) {
                    $msg .= ': ' . $j['message'];
                }
                self::state(array('last_error' => $msg, 'last_error_at' => time()));
                $res = new WP_Error('cc_http', $msg);
            } else {
                self::state(array('last_ok' => time(), 'last_error' => null));
            }
        }

        return $res;
    }

    private static function event($type, $data = array())
    {
        $data['type'] = $type;
        $data['occurred_at'] = gmdate('c');
        if (! isset($data['user'])) {
            $data['user'] = self::current_user_login();
        }
        $data['ip'] = self::client_ip();
        if (! self::$queue) {
            add_action('shutdown', array(__CLASS__, 'flush'), 1);
        }
        self::$queue[] = $data;
    }

    /**
     * Wysyła zebrane zdarzenia na końcu żądania (raz, krótki timeout). Gdy panel nie odpowie,
     * zdarzenia czekają w kolejce i pójdą z następnym raportem – nic nie ginie.
     */
    public static function flush()
    {
        $events = self::$queue;
        self::$queue = array();
        if (! $events || ! self::config()) {
            return;
        }
        $res = self::send_events($events, 5);
        if (is_wp_error($res)) {
            $pending = get_option(self::OPT_PENDING, array());
            $pending = array_slice(array_merge(is_array($pending) ? $pending : array(), $events), -200);
            update_option(self::OPT_PENDING, $pending, false);
        }
    }

    /** Zaległe zdarzenia (z nieudanych wysyłek) – próbujemy przy każdym raporcie. */
    private static function flush_pending()
    {
        $pending = get_option(self::OPT_PENDING, array());
        if (is_array($pending) && $pending && ! is_wp_error(self::send_events($pending, 15))) {
            delete_option(self::OPT_PENDING);
        }
    }

    private static function send_events($events, $timeout)
    {
        return self::send('events', array('events' => array_values($events)), true, $timeout);
    }

    public static function routes()
    {
        register_rest_route('cc-connector/v1', '/report', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_report'),
            'permission_callback' => array(__CLASS__, 'verify_panel_request'),
        ));
    }

    /** Zapytanie od panelu musi być podpisane tym samym sekretem i świeże (±5 min). */
    public static function verify_panel_request(WP_REST_Request $request)
    {
        $c = self::config();
        if (! $c) {
            return new WP_Error('cc_not_configured', 'Brak klucza połączenia.', array('status' => 503));
        }
        $ts = (string) $request->get_header('x_cc_timestamp');
        $sig = (string) $request->get_header('x_cc_signature');
        $site = (string) $request->get_header('x_cc_site');
        if ($ts === '' || $sig === '' || ! hash_equals($c['site'], $site) || abs(time() - (int) $ts) > 300) {
            return new WP_Error('cc_forbidden', 'Nieprawidłowy podpis.', array('status' => 403));
        }
        if (! hash_equals(self::sign($c['secret'], $ts, $request->get_body()), $sig)) {
            return new WP_Error('cc_forbidden', 'Nieprawidłowy podpis.', array('status' => 403));
        }

        return true;
    }

    public static function rest_report(WP_REST_Request $request)
    {
        $params = json_decode($request->get_body(), true);
        $scan = null;
        if (is_array($params) && ! empty($params['scan'])) {
            $scan = self::scan(false);
        }
        self::state(array('last_pull' => time()));

        return rest_ensure_response(array('report' => self::report(), 'scan' => $scan));
    }

    public static function daily()
    {
        self::flush_pending();
        $scan = self::scan(false);
        self::send('report', array('report' => self::report(), 'scan' => $scan), true);
    }

    // ------------------------------------------------------------------ raport stanu

    public static function report()
    {
        global $wp_version, $wpdb;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        // odśwież informacje o aktualizacjach (WordPress sam pilnuje, żeby nie robić tego za często)
        if (function_exists('wp_version_check')) {
            @wp_version_check();
        }
        if (function_exists('wp_update_plugins')) {
            @wp_update_plugins();
        }
        if (function_exists('wp_update_themes')) {
            @wp_update_themes();
        }

        $plugin_updates = get_site_transient('update_plugins');
        $plugins = array();
        foreach (get_plugins() as $file => $p) {
            $update = isset($plugin_updates->response[$file]) ? $plugin_updates->response[$file] : null;
            $plugins[] = array(
                'file' => $file,
                'name' => wp_strip_all_tags($p['Name']),
                'version' => $p['Version'],
                'active' => is_plugin_active($file),
                'update' => $update && ! empty($update->new_version) ? (string) $update->new_version : null,
                'author' => wp_strip_all_tags($p['Author']),
            );
        }

        $theme_updates = get_site_transient('update_themes');
        $active_theme = wp_get_theme();
        $themes = array();
        foreach (wp_get_themes() as $slug => $t) {
            $update = isset($theme_updates->response[$slug]) ? $theme_updates->response[$slug] : null;
            $themes[] = array(
                'slug' => $slug,
                'name' => wp_strip_all_tags($t->get('Name')),
                'version' => $t->get('Version'),
                'active' => $slug === $active_theme->get_stylesheet() || $slug === $active_theme->get_template(),
                'update' => $update && ! empty($update['new_version']) ? (string) $update['new_version'] : null,
            );
        }

        $core_update = null;
        $offers = function_exists('get_core_updates') ? get_core_updates(array('dismissed' => false)) : array();
        if (is_array($offers)) {
            foreach ($offers as $o) {
                if (isset($o->response) && $o->response === 'upgrade') {
                    $core_update = $o->current;
                    break;
                }
            }
        }

        $admins = array();
        foreach (get_users(array('role' => 'administrator', 'fields' => array('ID', 'user_login', 'user_email', 'user_registered', 'display_name'))) as $u) {
            $admins[] = array('id' => (int) $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'name' => $u->display_name, 'registered' => $u->user_registered);
        }
        if (is_multisite()) {
            foreach (get_super_admins() as $login) {
                $admins[] = array('id' => 0, 'login' => $login, 'email' => '', 'name' => 'Super Admin', 'registered' => '');
            }
        }

        $state = self::state();
        $failed = isset($state['failed_logins']) && is_array($state['failed_logins']) ? $state['failed_logins'] : array();

        return array(
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'rest_url' => rest_url('cc-connector/v1/report'),
            'wp_version' => $wp_version,
            'wp_update' => $core_update,
            'php_version' => PHP_VERSION,
            'db_version' => $wpdb->db_version(),
            'server' => isset($_SERVER['SERVER_SOFTWARE']) ? substr(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])), 0, 100) : '',
            'multisite' => is_multisite(),
            'locale' => get_locale(),
            'https' => strpos(home_url('/'), 'https://') === 0,
            'indexing_blocked' => ! get_option('blog_public'),
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_display' => defined('WP_DEBUG') && WP_DEBUG && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY),
            'file_edit_allowed' => ! (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT),
            'auto_update_core' => ! (defined('WP_AUTO_UPDATE_CORE') && WP_AUTO_UPDATE_CORE === false),
            'users_count' => (int) count_users()['total_users'],
            'plugins' => $plugins,
            'themes' => $themes,
            'admins' => $admins,
            'failed_logins_24h' => (int) array_sum(array_filter($failed, function ($k) {
                return (int) $k >= (int) gmdate('YmdH', time() - DAY_IN_SECONDS);
            }, ARRAY_FILTER_USE_KEY)),
            'last_scan' => isset($state['last_scan']) ? $state['last_scan'] : null,
            'connector' => self::VERSION,
        );
    }

    // ------------------------------------------------------------------ skan plików

    /**
     * 1) pliki WordPressa porównane z oficjalnymi sumami kontrolnymi z wordpress.org,
     * 2) pliki PHP w katalogu uploads (nie powinno ich tam być),
     * 3) zmiany w plikach wtyczek, motywów, wp-config.php i .htaccess od poprzedniego skanu.
     */
    public static function scan($force_baseline = false)
    {
        global $wp_version;
        @set_time_limit(300);
        require_once ABSPATH . 'wp-admin/includes/update.php';
        $started = microtime(true);
        $result = array('core_modified' => array(), 'core_unknown' => array(), 'uploads_php' => array(), 'added' => array(), 'changed' => array(), 'removed' => array(), 'baseline' => false, 'uploads_silent' => 0);

        // 1) rdzeń
        $checksums = function_exists('get_core_checksums') ? get_core_checksums($wp_version, get_locale() ?: 'en_US') : false;
        if (! is_array($checksums) && get_locale() !== 'en_US') {
            $checksums = get_core_checksums($wp_version, 'en_US');
        }
        if (is_array($checksums)) {
            foreach ($checksums as $file => $md5) {
                if (strpos($file, 'wp-content/') === 0) {
                    continue;
                }
                $path = ABSPATH . $file;
                // wersje językowe (np. pl_PL) mają w version.php dopisek $wp_local_package – to nie jest zmiana
                if ($file === 'wp-includes/version.php' && is_file($path) && strpos((string) file_get_contents($path), '$wp_local_package') !== false) {
                    continue;
                }
                if (is_file($path) && md5_file($path) !== $md5) {
                    $result['core_modified'][] = $file;
                }
            }
            foreach (array('wp-admin', 'wp-includes') as $dir) {
                foreach (self::files(ABSPATH . $dir, array('php')) as $path) {
                    $rel = self::rel($path, ABSPATH);
                    if (! isset($checksums[$rel])) {
                        $result['core_unknown'][] = $rel;
                    }
                }
            }
            foreach ((array) glob(ABSPATH . '*.php') as $path) {
                $rel = basename($path);
                if ($rel !== 'wp-config.php' && ! isset($checksums[$rel])) {
                    $result['core_unknown'][] = $rel;
                }
            }
        } else {
            $result['core_checksums_unavailable'] = true;
        }

        // 2) PHP w uploads
        $uploads = wp_upload_dir(null, false);
        if (! empty($uploads['basedir']) && is_dir($uploads['basedir'])) {
            foreach (self::files($uploads['basedir'], array('php', 'phtml', 'phar', 'php5', 'php7', 'phps')) as $path) {
                if (self::is_silent_php($path)) {
                    $result['uploads_silent']++;
                    continue; // puste index.php („Silence is golden”) – wtyczki blokują nimi listowanie katalogów
                }
                $result['uploads_php'][] = self::rel($path, ABSPATH);
            }
        }

        // 3) wtyczki, motywy, mu-plugins, wp-config, .htaccess – porównanie z poprzednim skanem
        $current = self::hash_map();
        $baseline = get_option(self::OPT_BASELINE);
        if ($force_baseline || ! is_array($baseline) || empty($baseline['files'])) {
            $result['baseline'] = true;
        } else {
            foreach ($current as $rel => $hash) {
                if (! isset($baseline['files'][$rel])) {
                    $result['added'][] = $rel;
                } elseif ($baseline['files'][$rel] !== $hash) {
                    $result['changed'][] = $rel;
                }
            }
            foreach ($baseline['files'] as $rel => $hash) {
                if (! isset($current[$rel])) {
                    $result['removed'][] = $rel;
                }
            }
        }
        update_option(self::OPT_BASELINE, array('at' => time(), 'files' => $current), false);

        $result['files_checked'] = count($current);
        $result['seconds'] = round(microtime(true) - $started, 1);
        $result['at'] = gmdate('c');
        foreach (array('core_modified', 'core_unknown', 'uploads_php', 'added', 'changed', 'removed') as $k) {
            $result[$k . '_count'] = count($result[$k]);
            $result[$k] = array_slice($result[$k], 0, 100);
        }
        self::state(array('last_scan' => array(
            'at' => $result['at'],
            'issues' => $result['core_modified_count'] + $result['core_unknown_count'] + $result['uploads_php_count'] + $result['added_count'] + $result['changed_count'],
        )));

        return $result;
    }

    private static function hash_map($only_prefixes = null)
    {
        $map = array();
        $roots = array(WP_PLUGIN_DIR, get_theme_root());
        if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
            $roots[] = WPMU_PLUGIN_DIR;
        }
        foreach ($roots as $root) {
            foreach (self::files($root, self::SCAN_EXT) as $path) {
                if (realpath($path) === realpath(__FILE__)) {
                    continue; // własny plik – zmienia się przy aktualizacji wtyczki
                }
                $rel = self::rel($path, ABSPATH);
                if ($only_prefixes !== null && ! self::starts_with_any($rel, $only_prefixes)) {
                    continue;
                }
                $map[$rel] = md5_file($path);
                if (count($map) >= self::MAX_FILES) {
                    break 2;
                }
            }
        }
        if ($only_prefixes === null) {
            foreach (array(ABSPATH . 'wp-config.php', dirname(ABSPATH) . '/wp-config.php', ABSPATH . '.htaccess') as $path) {
                if (is_file($path)) {
                    $map[self::rel($path, ABSPATH)] = md5_file($path);
                }
            }
        }

        return $map;
    }

    /** Po aktualizacji wtyczki/motywu przyjmujemy nowe pliki jako wzorzec (to nie jest podejrzana zmiana). */
    private static function rebaseline($prefixes)
    {
        $baseline = get_option(self::OPT_BASELINE);
        if (! is_array($baseline) || empty($baseline['files']) || ! $prefixes) {
            return;
        }
        foreach (array_keys($baseline['files']) as $rel) {
            if (self::starts_with_any($rel, $prefixes)) {
                unset($baseline['files'][$rel]);
            }
        }
        $baseline['files'] = array_merge($baseline['files'], self::hash_map($prefixes));
        update_option(self::OPT_BASELINE, $baseline, false);
    }

    private static function files($dir, $extensions)
    {
        $out = array();
        if (! is_dir($dir) || ! is_readable($dir)) {
            return $out;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $f) {
                if (! $f->isFile()) {
                    continue;
                }
                $name = $f->getFilename();
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, $extensions, true) || ($name === '.htaccess' && in_array('htaccess', $extensions, true))) {
                    $out[] = $f->getPathname();
                    if (count($out) >= self::MAX_FILES) {
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            // brak dostępu do części katalogów – skanujemy to, co się da
        }

        return $out;
    }

    /** Plik PHP bez żadnego kodu – tylko znacznik otwierający i komentarze. */
    private static function is_silent_php($path)
    {
        if (@filesize($path) > 1024) {
            return false;
        }
        $code = (string) @file_get_contents($path);
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        $code = preg_replace('#(//|\#)[^\r\n]*#', '', $code);
        $code = str_replace(array('<?php', '<?', '?>'), '', $code);

        return trim($code) === '';
    }

    private static function rel($path, $base)
    {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';

        return strpos($path, $base) === 0 ? substr($path, strlen($base)) : $path;
    }

    private static function starts_with_any($s, $prefixes)
    {
        foreach ($prefixes as $p) {
            if (strpos($s, $p) === 0) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ zdarzenia

    public static function on_user_register($user_id)
    {
        $u = get_userdata($user_id);
        if ($u && in_array('administrator', (array) $u->roles, true)) {
            self::event('admin_added', array('target' => $u->user_login, 'target_email' => $u->user_email, 'how' => 'nowe konto'));
        }
    }

    public static function on_set_user_role($user_id, $role, $old_roles)
    {
        if ($role === 'administrator' && ! in_array('administrator', (array) $old_roles, true)) {
            $u = get_userdata($user_id);
            self::event('admin_added', array('target' => $u ? $u->user_login : '#' . $user_id, 'target_email' => $u ? $u->user_email : '', 'how' => 'zmiana roli'));
        }
    }

    public static function on_add_user_role($user_id, $role)
    {
        if ($role === 'administrator') {
            $u = get_userdata($user_id);
            self::event('admin_added', array('target' => $u ? $u->user_login : '#' . $user_id, 'target_email' => $u ? $u->user_email : '', 'how' => 'dodanie roli'));
        }
    }

    public static function on_grant_super_admin($user_id)
    {
        $u = get_userdata($user_id);
        self::event('admin_added', array('target' => $u ? $u->user_login : '#' . $user_id, 'target_email' => $u ? $u->user_email : '', 'how' => 'super admin (multisite)'));
    }

    public static function on_delete_user($user_id)
    {
        $u = get_userdata($user_id);
        if ($u && in_array('administrator', (array) $u->roles, true)) {
            self::event('admin_removed', array('target' => $u->user_login, 'target_email' => $u->user_email));
        }
    }

    public static function on_login($login, $user)
    {
        if ($user instanceof WP_User && in_array('administrator', (array) $user->roles, true)) {
            self::event('admin_login', array('user' => $login, 'agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 200) : ''));
        }
    }

    public static function on_login_failed($login)
    {
        $s = self::state();
        $failed = isset($s['failed_logins']) && is_array($s['failed_logins']) ? $s['failed_logins'] : array();
        $hour = gmdate('YmdH');
        $failed[$hour] = (isset($failed[$hour]) ? $failed[$hour] : 0) + 1;
        $failed = array_slice($failed, -48, null, true); // ostatnie 48 godzin
        self::state(array('failed_logins' => $failed));
        // seria nieudanych logowań = możliwy atak (raz na godzinę)
        if ($failed[$hour] === 20) {
            self::event('login_attack', array('count' => 20, 'last_login_tried' => substr(sanitize_user((string) $login), 0, 60), 'user' => ''));
        }
    }

    public static function on_plugin_activated($plugin)
    {
        self::event('plugin_activated', array('plugin' => self::plugin_label($plugin)));
    }

    public static function on_plugin_deactivated($plugin)
    {
        if ($plugin === plugin_basename(__FILE__)) {
            return; // obsługuje deactivate()
        }
        self::event('plugin_deactivated', array('plugin' => self::plugin_label($plugin)));
    }

    public static function on_plugin_deleted($plugin, $deleted)
    {
        if ($deleted) {
            self::event('plugin_deleted', array('plugin' => $plugin));
        }
    }

    public static function on_switch_theme($name)
    {
        self::event('theme_switched', array('theme' => $name));
    }

    public static function on_upgrade($upgrader, $options)
    {
        $type = isset($options['type']) ? $options['type'] : '';
        $action = isset($options['action']) ? $options['action'] : '';
        $items = array();
        $prefixes = array();
        $plugins_rel = self::rel(WP_PLUGIN_DIR, ABSPATH) . '/';
        $themes_rel = self::rel(get_theme_root(), ABSPATH) . '/';

        if ($type === 'plugin') {
            $list = isset($options['plugins']) ? (array) $options['plugins'] : (isset($options['plugin']) ? array($options['plugin']) : array());
            if (! $list && $action === 'install' && is_object($upgrader) && method_exists($upgrader, 'plugin_info')) {
                $info = $upgrader->plugin_info();
                $list = $info ? array($info) : array();
            }
            foreach ($list as $p) {
                $items[] = self::plugin_label($p);
                $prefixes[] = $plugins_rel . (strpos($p, '/') !== false ? dirname($p) . '/' : $p);
            }
        } elseif ($type === 'theme') {
            $list = isset($options['themes']) ? (array) $options['themes'] : array();
            if (! $list && $action === 'install' && is_object($upgrader) && isset($upgrader->result['destination_name'])) {
                $list = array($upgrader->result['destination_name']);
            }
            foreach ($list as $t) {
                $items[] = $t;
                $prefixes[] = $themes_rel . $t . '/';
            }
        } elseif ($type === 'core') {
            global $wp_version;
            $items[] = 'WordPress ' . $wp_version;
        }

        if ($prefixes) {
            self::rebaseline($prefixes);
        }
        self::event($action === 'install' ? $type . '_installed' : $type . '_updated', array('items' => $items));
    }

    /** Edycja kodu z poziomu kokpitu (Wygląd → Edytor plików motywu / Wtyczki → Edytor). */
    public static function on_file_editor()
    {
        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        $where = isset($_POST['theme']) ? 'motyw ' . sanitize_text_field(wp_unslash($_POST['theme'])) : (isset($_POST['plugin']) ? 'wtyczka ' . sanitize_text_field(wp_unslash($_POST['plugin'])) : '');
        self::event('file_edited', array('file' => $file, 'where' => $where));
    }

    // ------------------------------------------------------------------ pomocnicze

    private static function plugin_label($file)
    {
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (is_file($path)) {
            $data = get_file_data($path, array('Name' => 'Plugin Name', 'Version' => 'Version'));
            if (! empty($data['Name'])) {
                return $data['Name'] . (empty($data['Version']) ? '' : ' ' . $data['Version']);
            }
        }

        return (string) $file;
    }

    private static function current_user_login()
    {
        $u = wp_get_current_user();

        return $u && $u->exists() ? $u->user_login : (defined('WP_CLI') && WP_CLI ? 'WP-CLI' : (wp_doing_cron() ? 'cron' : ''));
    }

    private static function client_ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    // ------------------------------------------------------------------ ustawienia (kokpit)

    public static function menu()
    {
        add_options_page('CC Connector', 'CC Connector', 'manage_options', 'cc-connector', array(__CLASS__, 'settings_page'));
    }

    public static function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=cc-connector')) . '">Ustawienia</a>');

        return $links;
    }

    public static function settings_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $c = self::config();
        $s = self::state();
        $notice = isset($_GET['cc']) ? sanitize_key(wp_unslash($_GET['cc'])) : '';
        ?>
        <div class="wrap">
            <h1>CC Connector – połączenie z panelem opieki CleanCode AS</h1>
            <?php if ($notice === 'ok') : ?>
                <div class="notice notice-success"><p><strong>Połączono.</strong> Strona wysłała raport do panelu.</p></div>
            <?php elseif ($notice === 'error') : ?>
                <div class="notice notice-error"><p><strong>Nie udało się połączyć:</strong> <?php echo esc_html(isset($s['last_error']) ? $s['last_error'] : 'nieznany błąd'); ?></p></div>
            <?php elseif ($notice === 'invalid') : ?>
                <div class="notice notice-error"><p>Nieprawidłowy klucz. Skopiuj go jeszcze raz z panelu (zakładka WordPress u klienta).</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cc_connector_save">
                <?php wp_nonce_field('cc_connector_save'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cc_key">Klucz połączenia</label></th>
                        <td>
                            <input type="password" id="cc_key" name="cc_key" class="large-text code" autocomplete="off"
                                   placeholder="<?php echo $c ? esc_attr('zapisany – wklej nowy, aby zmienić') : 'cc1.…'; ?>">
                            <p class="description">Klucz otrzymasz od CleanCode AS (panel opieki → klient → zakładka „WordPress”).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button($c ? 'Zapisz nowy klucz i połącz' : 'Zapisz i połącz'); ?>
            </form>

            <?php if ($c) : ?>
                <h2>Stan</h2>
                <p>
                    Panel: <code><?php echo esc_html($c['url']); ?></code><br>
                    Ostatni udany kontakt: <?php echo ! empty($s['last_ok']) ? esc_html(wp_date('d.m.Y H:i', (int) $s['last_ok'])) : '—'; ?><br>
                    Ostatni skan plików: <?php echo ! empty($s['last_scan']['at']) ? esc_html(wp_date('d.m.Y H:i', strtotime($s['last_scan']['at']))) : '—'; ?>
                    <?php if (! empty($s['last_error'])) : ?><br><span style="color:#b32d2e">Ostatni błąd: <?php echo esc_html($s['last_error']); ?></span><?php endif; ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="cc_connector_test">
                    <?php wp_nonce_field('cc_connector_test'); ?>
                    <?php submit_button('Wyślij raport teraz', 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <p class="description" style="margin-top:24px">Wtyczka wysyła do panelu: wersje WordPress/PHP, listę wtyczek i motywów z dostępnymi aktualizacjami, listę administratorów, wyniki skanu plików oraz zdarzenia (nowy administrator, logowanie administratora, instalacja/aktualizacja/usunięcie wtyczki, edycja plików z kokpitu). Nie wysyła haseł ani treści strony.</p>
        </div>
        <?php
    }

    public static function save_settings()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('cc_connector_save');
        $key = isset($_POST['cc_key']) ? trim(sanitize_text_field(wp_unslash($_POST['cc_key']))) : '';
        if ($key !== '') {
            $old = get_option(self::OPT_KEY, '');
            update_option(self::OPT_KEY, $key, false);
            if (! self::config()) {
                update_option(self::OPT_KEY, $old, false);
                wp_safe_redirect(admin_url('options-general.php?page=cc-connector&cc=invalid'));
                exit;
            }
        }
        self::activate();
        wp_safe_redirect(admin_url('options-general.php?page=cc-connector&cc=' . (self::push_full_report() ? 'ok' : 'error')));
        exit;
    }

    public static function test_connection()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('cc_connector_test');
        wp_safe_redirect(admin_url('options-general.php?page=cc-connector&cc=' . (self::push_full_report() ? 'ok' : 'error')));
        exit;
    }

    public static function push_full_report()
    {
        self::flush_pending();
        $baseline = get_option(self::OPT_BASELINE);
        $scan = self::scan(! is_array($baseline) || empty($baseline['files']));
        $res = self::send('report', array('report' => self::report(), 'scan' => $scan), true);

        return ! is_wp_error($res);
    }
}

register_activation_hook(__FILE__, array('CC_Connector', 'activate'));
register_deactivation_hook(__FILE__, array('CC_Connector', 'deactivate'));
CC_Connector::init();
