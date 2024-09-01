<?php
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

    require_once $config_path;
    require_once $ttrss_root . "/include/autoload.php";
    require_once $ttrss_root . "/include/sessions.php";
    require_once $ttrss_root . "/plugins.local/freshapi/api/freshapi.php";
    require_once $ttrss_root . "/include/functions.php";
    require_once $ttrss_root . "/classes/API.php";

	define('NO_SESSION_AUTOSTART', true);
    define('TT_RSS_API_URL', clean($_SERVER["TTRSS_SELF_URL_PATH"]) . '/api/');

    #ini_set('session.use_cookies', "0");
	#ini_set("session.gc_maxlifetime", "86400");

	ob_start();

    error_log(print_r($_REQUEST, true));

    // Handling different API requests
    $action = explode('/reader/api/0/', clean($_SERVER["PATH_INFO"]))[1] ?? null;
    error_log(print_r("action=" . $action, true));
    if (!empty(clean($_SERVER["HTTP_AUTHORIZATION"]))) {
        $session_id = explode('/', clean($_SERVER["HTTP_AUTHORIZATION"]))[1];
        if (!isSessionValid($session_id)) {
            session_id($session_id);
            session_start();
        }
        } else if (!isset($session_id) && !empty(clean($_REQUEST["Email"])) && !empty(clean($_REQUEST["Passwd"]))) {
        $session_id = ttrssLogin(clean($_REQUEST["Email"]), clean($_REQUEST["Passwd"]));
        if (isSessionValid($session_id)) {
            $auth = clean($_REQUEST["Email"]) . "/" . $session_id;
            echo 'SID=', $auth, "\n",
            'LSID=null', "\n",	//Vienna RSS
            'Auth=', $auth, "\n";
        } else {
            "Invalid Credentials";
        }
        exit();
    }

    if ($session_id) {
        switch ($action) {
            case 'user-info':
                echo mapUserInfo($session_id);
                break;
            case 'unread-count':
                echo mapTagList($session_id);
                break;
            case 'tag/list':
                echo mapTagList($session_id);
                break;
            case 'subscriptionExport':
                $result = mapSubscriptionExport($session_id);
                header('Content-Type: application/xml');
                header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                echo $result['content'];
                break;
            case 'subscriptionImport':
                $opml = file_get_contents('php://input');
                echo mapSubscriptionImport($opml, $session_id);
                break;
            case 'subscription/list':
                echo mapSubscriptionList($session_id);
                break;
            case 'stream/items/ids':
                echo mapStreamItemsIds($session_id, $_GET);
                break;
            case 'stream/items/contents':
                echo mapStreamItemsContents($session_id, $_GET);
                break;
            case 'token':
                echo mapToken($session_id);
                break;
            default:
                http_response_code(400);
                die('Invalid action');
        }
    } else {
        http_response_code(401);
        die('Session ID required');
    }

/*

	startup_gettext();

	if (!init_plugins()) return;

	if (!empty($_SESSION["uid"])) {
		if (!Sessions::validate_session()) {
			header("Content-Type: text/json");

			print json_encode([
						"seq" => -1,
						"status" => API::STATUS_ERR,
						"content" => [ "error" => API::E_NOT_LOGGED_IN ]
					]);

			return;
		}

		UserHelper::load_user_plugins($_SESSION["uid"]);
	}


/*
	$handler = new FreshAPI_Class($_REQUEST);

	if ($handler->before($method)) {
		if ($method && method_exists($handler, $method)) {
			$handler->$method();
		} else { # if (method_exists($handler, 'index'))
			$handler->index($method);
		}
		$handler->after();
	}

	header("Api-Content-Length: " . ob_get_length());

	ob_end_flush();
*/