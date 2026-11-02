<?php
/**
 * Uprząż testowa wtyczki CC Connector – minimalne zaślepki funkcji WordPressa.
 * Uruchamia prawdziwy kod wtyczki i zapisuje wysłane przez nią zapytania (do odtworzenia na panelu).
 *
 * Użycie: php harness.php <katalog_tymczasowy> <klucz cc1.…> [podpis_panelu_json]
 * Wynik: JSON { requests: [...], verify: ..., rest_report_keys: [...] } na stdout.
 */

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    if (! (error_reporting() & $no)) {
        return false; // wyciszone przez @
    }
    throw new ErrorException($str, 0, $no, $file, $line); // każde ostrzeżenie PHP = błąd testu
});

[$_, $root, $key] = $argv + [null, null, null];
$panelRequest = isset($argv[3]) ? json_decode($argv[3], true) : null;
$root = rtrim(str_replace('\\', '/', $root), '/') . '/';

// --- struktura „WordPressa” na dysku
foreach (['wp-admin/includes', 'wp-includes', 'wp-content/plugins/akismet', 'wp-content/themes/twentyfive', 'wp-content/uploads/2026/11', 'wp-content/mu-plugins'] as $d) {
    @mkdir($root . $d, 0777, true);
}
foreach (['wp-admin/includes/plugin.php', 'wp-admin/includes/update.php', 'wp-admin/includes/theme.php'] as $f) {
    file_put_contents($root . $f, "<?php\n");
}
file_put_contents($root . 'wp-admin/index.php', "<?php // core\n");
file_put_contents($root . 'wp-includes/version.php', "<?php // core\n");
file_put_contents($root . 'index.php', "<?php // core\n");
if (! is_file($root . 'wp-config.php')) {
    file_put_contents($root . 'wp-config.php', "<?php // config\n");
    file_put_contents($root . 'wp-content/plugins/akismet/akismet.php', "<?php // akismet 5.0\n");
    file_put_contents($root . 'wp-content/themes/twentyfive/functions.php', "<?php // theme\n");
}

define('ABSPATH', $root);
define('WP_CONTENT_DIR', $root . 'wp-content');
define('WP_PLUGIN_DIR', $root . 'wp-content/plugins');
define('WPMU_PLUGIN_DIR', $root . 'wp-content/mu-plugins');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('CC_CONNECTOR_KEY', $key);
$GLOBALS['wp_version'] = '6.8.1';

// --- opcje w pliku (trwałe między uruchomieniami – baseline skanu)
$optFile = $root . '.options.json';
$GLOBALS['__opts'] = is_file($optFile) ? json_decode(file_get_contents($optFile), true) : [];
register_shutdown_function(function () use ($optFile) {
    file_put_contents($optFile, json_encode($GLOBALS['__opts']));
});
$GLOBALS['__requests'] = [];
$GLOBALS['__actions'] = [];

class WP_Error
{
    public $msg;
    public function __construct($code = '', $msg = '', $data = null) { $this->msg = $msg; }
    public function get_error_message() { return $this->msg; }
}
class WP_User
{
    public $roles = ['administrator'];
    public $user_login = 'adrian';
    public $user_email = 'a@example.com';
    public $ID = 1;
    public function exists() { return true; }
}
class WP_REST_Request
{
    private $h; private $b;
    public function __construct($headers, $body) { $this->h = $headers; $this->b = $body; }
    public function get_header($k) { return $this->h[$k] ?? null; }
    public function get_body() { return $this->b; }
}
class FakeTheme
{
    public function get($k) { return ['Name' => 'Twenty Twenty-Five', 'Version' => '1.0'][$k] ?? ''; }
    public function get_stylesheet() { return 'twentyfive'; }
    public function get_template() { return 'twentyfive'; }
}
$GLOBALS['wpdb'] = new class { public function db_version() { return '8.0.36'; } };

function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS['__actions'][$h][] = $cb; }
function add_filter($h, $cb, $p = 10, $a = 1) {}
function plugin_basename($f) { return 'cc-connector/cc-connector.php'; }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($t, $r, $h) { return true; }
function wp_schedule_single_event($t, $h) { return true; }
function wp_clear_scheduled_hook($h) {}
function home_url($p = '') { return 'https://klient.example/'; }
function rest_url($p = '') { return 'https://klient.example/wp-json/' . $p; }
function wp_json_encode($v) { return json_encode($v); }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_remote_post($url, $args)
{
    $GLOBALS['__requests'][] = ['url' => $url, 'headers' => $args['headers'], 'body' => $args['body'], 'blocking' => $args['blocking'], 'timeout' => $args['timeout']];
    if (getenv('CC_FAIL_SEND')) {
        return new WP_Error('http', 'Połączenie odrzucone');
    }
    return ['response' => ['code' => 200], 'body' => '{"ok":true}'];
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function register_rest_route($ns, $route, $args) { $GLOBALS['__rest'][$ns . $route] = $args; }
function rest_ensure_response($v) { return $v; }
function get_plugins() { return ['akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.0', 'Author' => 'Automattic'], 'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7', 'Author' => 'Matt']]; }
function is_plugin_active($f) { return $f === 'akismet/akismet.php'; }
function get_site_transient($k)
{
    if ($k === 'update_plugins') { return (object) ['response' => ['akismet/akismet.php' => (object) ['new_version' => '5.3']]]; }
    if ($k === 'update_themes') { return (object) ['response' => []]; }
    return false;
}
function wp_get_theme() { return new FakeTheme; }
function wp_get_themes() { return ['twentyfive' => new FakeTheme]; }
function get_core_updates($o = []) { return [(object) ['response' => 'upgrade', 'current' => '6.9']]; }
function get_users($a) { return [(object) ['ID' => 1, 'user_login' => 'adrian', 'user_email' => 'a@example.com', 'user_registered' => '2024-01-01 10:00:00', 'display_name' => 'Adrian']]; }
function is_multisite() { return false; }
function get_super_admins() { return []; }
function count_users() { return ['total_users' => 3]; }
function get_bloginfo($k) { return 'Klient'; }
function get_locale() { return 'pl_PL'; }
function wp_strip_all_tags($s) { return strip_tags($s); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_user($s) { return preg_replace('/[^a-z0-9_.@-]/i', '', $s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $s)); }
function wp_unslash($s) { return $s; }
function wp_upload_dir($t = null, $c = true) { return ['basedir' => ABSPATH . 'wp-content/uploads']; }
function get_theme_root() { return ABSPATH . 'wp-content/themes'; }
function get_core_checksums($v, $l)
{
    if ($l !== 'en_US') { return false; } // jak w realu: brak sum dla lokalizacji → fallback na en_US
    return [
        'wp-admin/index.php' => md5("<?php // core\n"),
        'wp-includes/version.php' => md5("<?php // core\n"),
        'index.php' => md5("<?php // core\n"),
        'wp-admin/includes/plugin.php' => md5("<?php\n"),
        'wp-admin/includes/update.php' => md5("<?php\n"),
        'wp-admin/includes/theme.php' => md5("<?php\n"),
        'wp-content/plugins/akismet/akismet.php' => 'ignored',
    ];
}
function wp_version_check() {}
function wp_update_plugins() {}
function wp_update_themes() {}
function wp_get_current_user() { return new WP_User; }
function wp_doing_cron() { return false; }
function get_userdata($id) { $u = new WP_User; $u->user_login = 'nowy_admin'; $u->user_email = 'x@evil.example'; return $u; }
function get_file_data($p, $h) { return ['Name' => 'Akismet', 'Version' => '5.3']; }
function current_user_can($c) { return true; }

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

require __DIR__ . '/../../resources/wordpress/cc-connector/cc-connector.php';

$mode = getenv('CC_MODE') ?: 'first';
$out = ['mode' => $mode];

if ($mode === 'first') {
    foreach ($GLOBALS['__actions']['init'] ?? [] as $cb) { call_user_func($cb); }
    $out['push_ok'] = CC_Connector::push_full_report();
} elseif ($mode === 'tamper') {
    // zmiany „po włamaniu”: plik wtyczki, PHP w uploads, wp-config, nieznany plik w wp-includes
    file_put_contents(ABSPATH . 'wp-content/plugins/akismet/akismet.php', "<?php eval(\$_POST['x']);\n");
    file_put_contents(ABSPATH . 'wp-content/uploads/2026/11/shell.php', "<?php system(\$_GET['c']);\n");
    file_put_contents(ABSPATH . 'wp-config.php', "<?php // config changed\n");
    file_put_contents(ABSPATH . 'wp-includes/class-wp-cache-x.php', "<?php // unknown\n");
    file_put_contents(ABSPATH . 'index.php', "<?php // core modified\n");
    CC_Connector::daily();
} elseif ($mode === 'events') {
    CC_Connector::on_user_register(5);
    CC_Connector::on_file_editor();
    CC_Connector::on_login('adrian', new WP_User);
    CC_Connector::flush(); // shutdown
    $out['pending'] = count(get_option('cc_connector_pending', []));
} elseif ($mode === 'update') {
    // aktualizacja wtyczki z kokpitu: nowe pliki NIE mogą być zgłoszone jako podejrzane
    file_put_contents(ABSPATH . 'wp-content/plugins/akismet/akismet.php', "<?php // akismet 5.3 – oficjalna aktualizacja\n");
    CC_Connector::on_upgrade(null, ['type' => 'plugin', 'action' => 'update', 'plugins' => ['akismet/akismet.php']]);
    CC_Connector::flush();
    $out['scan'] = CC_Connector::scan(false);
} elseif ($mode === 'panel') {
    $req = new WP_REST_Request($panelRequest['headers'], $panelRequest['body']);
    $v = CC_Connector::verify_panel_request($req);
    $out['verify'] = $v === true ? true : $v->get_error_message();
    if ($v === true) {
        $resp = CC_Connector::rest_report($req);
        $out['rest_report_keys'] = array_keys($resp);
        $out['scan_present'] = is_array($resp['scan']);
        $out['wp_version'] = $resp['report']['wp_version'];
    }
}

$out['requests'] = $GLOBALS['__requests'];
echo json_encode($out);
