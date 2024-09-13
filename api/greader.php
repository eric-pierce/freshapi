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
require_once "./freshapi.php";

define('NO_SESSION_AUTOSTART', true);
define('TTRSS_SELF_URL_PATH', clean($_SERVER["TTRSS_SELF_URL_PATH"]));
define('TT_RSS_API_URL', clean($_SERVER["TTRSS_SELF_URL_PATH"]) . '/api/');
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
		if (isset($session_id)) {
			session_id($session_id);
			session_start();
		}
	}
}

startup_gettext();

//error_log(print_r($_SERVER['PATH_INFO'], true));
//error_log(print_r($_REQUEST, true));

$freshapi = new FreshGReaderAPI($_REQUEST);
$freshapi->parse();