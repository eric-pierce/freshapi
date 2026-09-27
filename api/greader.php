<?php
declare(strict_types=1);

error_reporting(E_ERROR | E_PARSE);

$ttrss_root = dirname(__DIR__, 3);
$config_path = $ttrss_root . "/config.php";

// Check if config.php exists and require it
if (!file_exists($config_path)) {
	$ttrss_root = dirname(__DIR__, 2);
	$config_path = $ttrss_root . "/config.php";
}

// Set the include path
set_include_path(implode(PATH_SEPARATOR, [
	__DIR__,
	$ttrss_root,
	$ttrss_root . "/include",
	get_include_path(),
]));

require_once $ttrss_root . "/include/autoload.php";
require_once $ttrss_root . "/include/sessions.php";
require_once $ttrss_root . "/include/functions.php";
require_once __DIR__ . "/freshapi.php";

// Like TT-RSS's api/index.php, run from the TT-RSS root: relative paths such as LOCAL_PLUGINS_DIR ("plugins.local")
// resolve against it, and per-user plugins (including this one) silently fail to load otherwise
chdir($ttrss_root);

// TT-RSS's ORM opens its own PDO connection by default, doubling Postgres connections per request (#16).
// Hand it the connection Db::pdo() already uses instead.
ORM::set_db(Db::pdo());

define('NO_SESSION_AUTOSTART', true);
define('TTRSS_SELF_URL_PATH', preg_replace('/(\/api\/{1,}|\/+plugins(.local)?\/.{1,}\/{1,})?(\w+\.php).*/', '', Config::get_self_url()));
const JSON_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

ini_set('session.use_cookies', "0");
ini_set("session.gc_maxlifetime", "86400");

$ORIGINAL_INPUT = file_get_contents('php://input', false, null, 0, 1048576) ?: '';

if (!init_plugins()) return;

$headerAuth = headerVariable('Authorization', 'GoogleLogin_auth');
if ($headerAuth != '') {
	$headerAuthX = explode('/', $headerAuth, 2);
	if (count($headerAuthX) === 2) {
		$session_id = $headerAuthX[1];
		// Only resume sessions that already exist: session_start() with an unknown id creates (and later saves)
		// a new empty session, so any unauthenticated request with a made-up token added a row to ttrss_sessions
		if (preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $session_id)
			&& (!method_exists('Sessions', 'exists') || Sessions::exists($session_id))) {
			session_id($session_id);
			session_start();

			// Apply the same checks as TT-RSS's own API entry point (api/index.php), so that changing the
			// password, disabling the account, turning off API access or disabling FreshAPI revokes access
			if (!empty($_SESSION['uid']) && !freshapiSessionAllowed((int)$_SESSION['uid'])) {
				session_abort(); // don't persist the cleared session, just refuse this request
				$_SESSION = [];
			}
		}
	}
}

startup_gettext();

//error_log(print_r($_SERVER['PATH_INFO'], true));
//error_log(print_r($_REQUEST, true));

$freshapi = new FreshGReaderAPI($_REQUEST);
$freshapi->parse();