<?php
declare(strict_types=1);

/**
== Description ==
Server-side API compatible with FreshRSS API for the Tiny Tiny RSS project https://tt-rss.org

== Credits ==
* Adapted and implemented by Eric Pierce https://eric-pierce.com
* Based on the Google Reader API implementation for FreshRSS by Alexandre Alapetite https://alexandre.alapetite.fr
    https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php
	Released under GNU AGPL 3 license http://www.gnu.org/licenses/agpl-3.0.html

== Versioning ==
    * 2024-08-31: Initial Release by Eric Pierce
*/

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
//require_once $ttrss_root . "/plugins.local/freshapi/api/freshapi.php";
require_once $ttrss_root . "/include/functions.php";
require_once $ttrss_root . "/classes/API.php";

$ORIGINAL_INPUT = file_get_contents('php://input', false, null, 0, 1048576) ?: '';

define('NO_SESSION_AUTOSTART', true);
define('TTRSS_SELF_URL_PATH', clean($_SERVER["TTRSS_SELF_URL_PATH"]));
define('TT_RSS_API_URL', clean($_SERVER["TTRSS_SELF_URL_PATH"]) . '/api/');

if (PHP_INT_SIZE < 8) {	//32-bit
	/** @return numeric-string */
	function hex2dec(string $hex): string {
		if (!ctype_xdigit($hex)) return '0';
		$result = gmp_strval(gmp_init($hex, 16), 10);
		/** @var numeric-string $result */
		return $result;
	}
} else {	//64-bit
	/** @return numeric-string */
	function hex2dec(string $hex): string {
		if (!ctype_xdigit($hex)) {
			return '0';
		}
		return '' . hexdec($hex);
	}
}

const JSON_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

function headerVariable(string $headerName, string $varName): string {
	$header = '';
	$upName = 'HTTP_' . strtoupper($headerName);
	if (isset($_SERVER[$upName])) {
		$header = '' . $_SERVER[$upName];
	} elseif (isset($_SERVER['REDIRECT_' . $upName])) {
		$header = '' . $_SERVER['REDIRECT_' . $upName];
	} elseif (function_exists('getallheaders')) {
		$ALL_HEADERS = getallheaders();
		if (isset($ALL_HEADERS[$headerName])) {
			$header = '' . $ALL_HEADERS[$headerName];
		}
	}
	parse_str($header, $pairs);
	if (empty($pairs[$varName])) {
		return '';
	}
	return is_string($pairs[$varName]) ? $pairs[$varName] : '';
}

function escapeToUnicodeAlternative(string $text, bool $extended = true): string {
	$text = htmlspecialchars_decode($text, ENT_QUOTES);

	//Problematic characters
	$problem = array('&', '<', '>');
	//Use their fullwidth Unicode form instead:
	$replace = array('＆', '＜', '＞');

	// https://raw.githubusercontent.com/mihaip/google-reader-api/master/wiki/StreamId.wiki
	if ($extended) {
		$problem += array("'", '"', '^', '?', '\\', '/', ',', ';');
		$replace += array("’", '＂', '＾', '？', '＼', '／', '，', '；');
	}

	return trim(str_replace($problem, $replace, $text));
}

/** @return array<string> */
function multiplePosts(string $name): array {
	//https://bugs.php.net/bug.php?id=51633
	global $ORIGINAL_INPUT;
	$inputs = explode('&', $ORIGINAL_INPUT);
	$result = array();
	$prefix = $name . '=';
	$prefixLength = strlen($prefix);
	foreach ($inputs as $input) {
		if (strpos($input, $prefix) === 0) {
			$result[] = urldecode(substr($input, $prefixLength));
		}
	}
	return $result;
}

function debugInfo(): string {
	if (function_exists('getallheaders')) {
		$ALL_HEADERS = getallheaders();
	} else {	//nginx	http://php.net/getallheaders#84262
		$ALL_HEADERS = array();
		foreach ($_SERVER as $name => $value) {
			if (substr($name, 0, 5) === 'HTTP_') {
				$ALL_HEADERS[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
			}
		}
	}
	global $ORIGINAL_INPUT;
	$log = [
			'date' => date('c'),
			'headers' => $ALL_HEADERS,
			'_SERVER' => $_SERVER,
			'_GET' => $_GET,
			'_POST' => $_POST,
			'_COOKIE' => $_COOKIE,
			'INPUT' => $ORIGINAL_INPUT,
		];
	return print_r($log, true);
}

final class GReaderAPI extends Handler{

	/** @return never */
	private static function noContent() {
		header('HTTP/1.1 204 No Content');
		exit();
	}

	/** @return never */
	private static function badRequest() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 400 Bad Request');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Bad Request!');
	}

	/** @return never */
	private static function unauthorized() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 401 Unauthorized');
		header('Content-Type: text/plain; charset=UTF-8');
		header('Google-Bad-Token: true');
		die('Unauthorized!');
	}

	/** @return never */
	private static function internalServerError() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 500 Internal Server Error');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Internal Server Error!');
	}

	/** @return never */
	private static function notImplemented() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 501 Not Implemented');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Not Implemented!');
	}

	/** @return never */
	private static function serviceUnavailable() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
        error_log('HERE1');
		header('HTTP/1.1 503 Service Unavailable');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Service Unavailable!');
	}

	/** @return never */
	private static function checkCompatibility() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('Content-Type: text/plain; charset=UTF-8');
		if (PHP_INT_SIZE < 8 && !function_exists('gmp_init')) {
			die('FAIL 64-bit or GMP extension! Wrong PHP configuration.');
		}
		$headerAuth = headerVariable('Authorization', 'GoogleLogin_auth');
		if ($headerAuth == '') {
			die('FAIL get HTTP Authorization header! Wrong Web server configuration.');
		}
		echo 'PASS';
		exit();
	}

    // Function to make API requests with session management
    private static function callTinyTinyRssApi($operation, $params = [], $session_id = null) {
        if ($session_id) {
            $params['sid'] = $session_id;
        }
    
        $params['op'] = $operation;
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, TT_RSS_API_URL);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($curl);
        curl_close($curl);
    
        return json_decode($response, true);
    }

    // Function to check if the session is still valid
    private static function isSessionActive($session_id) {
        $response = self::callTinyTinyRssApi('isLoggedIn', [], $session_id);

        return $response && isset($response['status']) && $response['status'] == 0 && $response['content']['status'] === true;
    }

	private static function authorizationToUser(): string {
		$headerAuth = headerVariable('Authorization', 'GoogleLogin_auth');
		if ($headerAuth != '') {
			$headerAuthX = explode('/', $headerAuth, 2);
			if (count($headerAuthX) === 2) {
				$email = $headerAuthX[0];
				$session_id = $headerAuthX[1];
				if (self::isSessionActive($session_id)) {
					return $session_id;
				}
			}
		}
		self::unauthorized();
	}

	private static function clientLogin(string $email, string $password) {
		$loginResponse = self::callTinyTinyRssApi('login', [
			'user' => $email,
			'password' => $password
		]);

		if ($loginResponse && isset($loginResponse['status']) && $loginResponse['status'] == 0) {
			$session_id = $loginResponse['content']['session_id'];
			
			// Format the response as expected by Google Reader API clients
			$auth = $email . '/' . $session_id;
			$response = "SID={$auth}\n";
			$response .= "LSID=\n";
			$response .= "Auth={$auth}\n";
			
			header('Content-Type: text/plain; charset=UTF-8');
			echo $response;
			exit();
		} else {
			self::unauthorized();
		}
	}

	/** @return never */
	private static function token($session_id) {
		//http://blog.martindoms.com/2009/08/15/using-the-google-reader-api-part-1/
		//https://github.com/ericmann/gReader-Library/blob/master/greader.class.php
		if ($session_id === null) {
			self::unauthorized();
		}
		//Minz_Log::debug('token('. $user . ')', API_LOG);	//TODO: Implement real token that expires
		$token = str_pad($session_id, 57, 'Z');	//Must have 57 characters

		echo $token, "\n";
		exit();
	}


	private static function checkToken(?FreshRSS_UserConfiguration $conf, string $token): bool {
		//http://code.google.com/p/google-reader-api/wiki/ActionToken
		$user = Minz_User::name();
		if ($user === null || $conf === null || !FreshRSS_Context::hasSystemConf()) {
			self::unauthorized();
		}
		if ($user !== Minz_User::INTERNAL_USER && (	//TODO: Check security consequences
			$token === '' || //FeedMe
			$token === 'x')) { //Reeder
			return true;
		}
		if ($token === str_pad(sha1(FreshRSS_Context::systemConf()->salt . $user . $conf->apiPasswordHash), 57, 'Z')) {
			return true;
		}
		error_log('Invalid POST token: ' . $token);
		self::unauthorized();
	}

	/** @return never */
	private static function userInfo() {
		$user = $_SESSION['name'];
		exit(json_encode(array(
				'userId' => $user,
				'userName' => $user,
				'userProfileId' => $user,
				'userEmail' => '',
			), JSON_OPTIONS));
	}

	/** @return never */
	private static function tagList($session_id) {
		header('Content-Type: application/json; charset=UTF-8');

		$tags = [
			['id' => 'user/-/state/com.google/starred'],
			// You can add more default tags here if needed
		];

		// Fetch categories
		$categoriesResponse = self::callTinyTinyRssApi('getCategories', [], $session_id);
		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				$tags[] = [
					'id' => 'user/-/label/' . htmlspecialchars_decode($category['title'], ENT_QUOTES),
					'type' => 'folder',
				];
			}
		}

		// Fetch labels (tags)
		$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
		if ($labelsResponse && isset($labelsResponse['status']) && $labelsResponse['status'] == 0) {
			foreach ($labelsResponse['content'] as $label) {
				$tags[] = [
					'id' => 'user/-/label/' . htmlspecialchars_decode($label[1], ENT_QUOTES),
					'type' => 'tag',
				];
			}
		}

		// Fetch unread counts
		$countersResponse = self::callTinyTinyRssApi('getCounters', [], $session_id);
		if ($countersResponse && isset($countersResponse['status']) && $countersResponse['status'] == 0) {
			foreach ($countersResponse['content'] as $counter) {
				if ($counter['type'] == 'cat') {
					$categoryTitle = $counter['title'] ?? '';
					foreach ($tags as &$tag) {
						if ($tag['id'] === 'user/-/label/' . $categoryTitle) {
							$tag['unread_count'] = $counter['counter'];
							break;
						}
					}
				} elseif ($counter['type'] == 'label') {
					$labelTitle = $counter['title'] ?? '';
					foreach ($tags as &$tag) {
						if ($tag['id'] === 'user/-/label/' . $labelTitle) {
							$tag['unread_count'] = $counter['counter'];
							break;
						}
					}
				}
			}
		}

		echo json_encode(['tags' => $tags], JSON_OPTIONS), "\n";
		exit();
	}

	/** @return never */
	private static function subscriptionExport() {
		$user = Minz_User::name() ?? Minz_User::INTERNAL_USER;
		$export_service = new FreshRSS_Export_Service($user);
		[$filename, $content] = $export_service->generateOpml();
		header('Content-Type: application/xml; charset=UTF-8');
		header('Content-disposition: attachment; filename="' . $filename . '"');
		echo $content;
		exit();
	}

	/** @return never */
	private static function subscriptionImport(string $opml) {
		$user = Minz_User::name() ?? Minz_User::INTERNAL_USER;
		$importService = new FreshRSS_Import_Service($user);
		$importService->importOpml($opml);
		if ($importService->lastStatus()) {
			FreshRSS_feed_Controller::actualizeFeedsAndCommit();
			invalidateHttpCache($user);
			exit('OK');
		} else {
			self::badRequest();
		}
	}

	/** @return never */
	private static function subscriptionList($session_id) {
		header('Content-Type: application/json; charset=UTF-8');

		$categoriesResponse = self::callTinyTinyRssApi('getCategories', [], $session_id);
		$feedsResponse = self::callTinyTinyRssApi('getFeeds', ['cat_id' => -4], $session_id);

		$subscriptions = [];
		$categoryMap = [];

		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				$categoryMap[$category['id']] = $category['title'];
			}
		}

		if ($feedsResponse && isset($feedsResponse['status']) && $feedsResponse['status'] == 0) {
			foreach ($feedsResponse['content'] as $feed) {
				$subscriptions[] = [
					'id' => 'feed/' . $feed['id'],
					'title' => $feed['title'],
					'categories' => [
						[
							'id' => 'user/-/label/' . $categoryMap[$feed['cat_id']],
							'label' => $categoryMap[$feed['cat_id']]
						]
					],
					'url' => $feed['feed_url'],
					'htmlUrl' => $feed['site_url'],
					'iconUrl' => TTRSS_SELF_URL_PATH . '/feed-icons/' . $feed['id'] . '.ico'
				];
			}
		}

		echo json_encode(['subscriptions' => $subscriptions], JSON_OPTIONS), "\n";
		exit();
	}

	/**
	 * @param array<string> $streamNames
	 * @param array<string> $titles
	 * @return never
	 */
	private static function subscriptionEdit(array $streamNames, array $titles, string $action, string $add = '', string $remove = '') {
		//https://github.com/mihaip/google-reader-api/blob/master/wiki/ApiSubscriptionEdit.wiki
		switch ($action) {
			case 'subscribe':
			case 'unsubscribe':
			case 'edit':
				break;
			default:
				self::badRequest();
		}
		$addCatId = 0;
		$c_name = '';
		if ($add != '' && strpos($add, 'user/') === 0) {	//user/-/label/Example ; user/username/label/Example
			if (strpos($add, 'user/-/label/') === 0) {
				$c_name = substr($add, 13);
			} else {
				$user = Minz_User::name();
				$prefix = 'user/' . $user . '/label/';
				if (strpos($add, $prefix) === 0) {
					$c_name = substr($add, strlen($prefix));
				} else {
					$c_name = '';
				}
			}
			$c_name = htmlspecialchars($c_name, ENT_COMPAT, 'UTF-8');
			$categoryDAO = FreshRSS_Factory::createCategoryDao();
			$cat = $categoryDAO->searchByName($c_name);
			$addCatId = $cat == null ? 0 : $cat->id();
		} elseif ($remove != '' && strpos($remove, 'user/-/label/') === 0) {
			$addCatId = 1;	//Default category
		}
		$feedDAO = FreshRSS_Factory::createFeedDao();
		if (count($streamNames) < 1) {
			self::badRequest();
		}
		for ($i = count($streamNames) - 1; $i >= 0; $i--) {
			$streamUrl = $streamNames[$i];	//feed/http://example.net/sample.xml	;	feed/338
			if (strpos($streamUrl, 'feed/') === 0) {
				$streamUrl = '' . preg_replace('%^(feed/)+%', '', $streamUrl);
				$feedId = 0;
				if (is_numeric($streamUrl)) {
					if ($action === 'subscribe') {
						continue;
					}
					$feedId = (int)$streamUrl;
				} else {
					$streamUrl = htmlspecialchars($streamUrl, ENT_COMPAT, 'UTF-8');
					$feed = $feedDAO->searchByUrl($streamUrl);
					$feedId = $feed == null ? -1 : $feed->id();
				}
				$title = $titles[$i] ?? '';
				$title = htmlspecialchars($title, ENT_COMPAT, 'UTF-8');
				switch ($action) {
					case 'subscribe':
						if ($feedId <= 0) {
							$http_auth = '';
							try {
								$feed = FreshRSS_feed_Controller::addFeed($streamUrl, $title, $addCatId, $c_name, $http_auth);
								continue 2;
							} catch (Exception $e) {
								error_log('subscriptionEdit error subscribe: ' . $e->getMessage());
							}
						}
						self::badRequest();
						// Always exits
					case 'unsubscribe':
						if (!($feedId > 0 && FreshRSS_feed_Controller::deleteFeed($feedId))) {
							self::badRequest();
						}
						break;
					case 'edit':
						if ($feedId > 0) {
							if ($addCatId > 0 || $c_name != '') {
								FreshRSS_feed_Controller::moveFeed($feedId, $addCatId, $c_name);
							}
							if ($title != '') {
								FreshRSS_feed_Controller::renameFeed($feedId, $title);
							}
						} else {
							self::badRequest();
						}
						break;
				}
			}
		}
		exit('OK');
	}

	/** @return never */
	private static function quickadd(string $url) {
		try {
			$url = htmlspecialchars($url, ENT_COMPAT, 'UTF-8');
			if (str_starts_with($url, 'feed/')) {
				$url = substr($url, 5);
			}
			$feed = FreshRSS_feed_Controller::addFeed($url);
			exit(json_encode(array(
					'numResults' => 1,
					'query' => $feed->url(),
					'streamId' => 'feed/' . $feed->id(),
					'streamName' => $feed->name(),
				), JSON_OPTIONS));
		} catch (Exception $e) {
			error_log('quickadd error: ' . $e->getMessage());
			die(json_encode(array(
					'numResults' => 0,
					'error' => $e->getMessage(),
				), JSON_OPTIONS));
		}
	}

	/** @return never */
	private static function unreadCount() {
		//http://blog.martindoms.com/2009/10/16/using-the-google-reader-api-part-2/#unread-count
		header('Content-Type: application/json; charset=UTF-8');

		$totalUnreads = 0;
		$totalLastUpdate = 0;

		$categoryDAO = FreshRSS_Factory::createCategoryDao();
		$feedDAO = FreshRSS_Factory::createFeedDao();
		$feedsNewestItemUsec = $feedDAO->listFeedsNewestItemUsec();

		foreach ($categoryDAO->listCategories(true, true) ?: [] as $cat) {
			$catLastUpdate = 0;
			foreach ($cat->feeds() as $feed) {
				$lastUpdate = $feedsNewestItemUsec['f_' . $feed->id()] ?? 0;
				$unreadcounts[] = array(
					'id' => 'feed/' . $feed->id(),
					'count' => $feed->nbNotRead(),
					'newestItemTimestampUsec' => '' . $lastUpdate,
				);
				if ($catLastUpdate < $lastUpdate) {
					$catLastUpdate = $lastUpdate;
				}
			}
			$unreadcounts[] = array(
				'id' => 'user/-/label/' . htmlspecialchars_decode($cat->name(), ENT_QUOTES),
				'count' => $cat->nbNotRead(),
				'newestItemTimestampUsec' => '' . $catLastUpdate,
			);
			$totalUnreads += $cat->nbNotRead();
			if ($totalLastUpdate < $catLastUpdate) {
				$totalLastUpdate = $catLastUpdate;
			}
		}

		$tagDAO = FreshRSS_Factory::createTagDao();
		$tagsNewestItemUsec = $tagDAO->listTagsNewestItemUsec();
		foreach ($tagDAO->listTags(true) ?: [] as $label) {
			$lastUpdate = $tagsNewestItemUsec['t_' . $label->id()] ?? 0;
			$unreadcounts[] = array(
				'id' => 'user/-/label/' . htmlspecialchars_decode($label->name(), ENT_QUOTES),
				'count' => $label->nbUnread(),
				'newestItemTimestampUsec' => '' . $lastUpdate,
			);
		}

		$unreadcounts[] = array(
			'id' => 'user/-/state/com.google/reading-list',
			'count' => $totalUnreads,
			'newestItemTimestampUsec' => '' . $totalLastUpdate,
		);

		echo json_encode(array(
			'max' => $totalUnreads,
			'unreadcounts' => $unreadcounts,
		), JSON_OPTIONS), "\n";
		exit();
	}

	/**
	 * @param array<FreshRSS_Entry> $entries
	 * @return array<array<string,mixed>>
	 */
	private static function entriesToArray(array $entries): array {
		if (empty($entries)) {
			return array();
		}
		$catDAO = FreshRSS_Factory::createCategoryDao();
		$categories = $catDAO->listCategories(true) ?: [];

		$tagDAO = FreshRSS_Factory::createTagDao();
		$entryIdsTagNames = $tagDAO->getEntryIdsTagNames($entries);

		$items = array();
		foreach ($entries as $item) {
			/** @var FreshRSS_Entry $entry */
			$entry = Minz_ExtensionManager::callHook('entry_before_display', $item);
			if ($entry == null) {
				continue;
			}

			$feed = FreshRSS_Category::findFeed($categories, $entry->feedId());
			if ($feed === null) {
				continue;
			}
			$entry->_feed($feed);

			$items[] = $entry->toGReader('compat', $entryIdsTagNames['e_' . $entry->id()] ?? []);
		}
		return $items;
	}

	/**
	 * @param 'A'|'c'|'f'|'s' $type
	 * @param string|int $streamId
	 * @phpstan-return array{'A'|'c'|'f'|'s'|'t',int,int,FreshRSS_BooleanSearch}
	 */
    
	private static function streamContentsFilters(string $type, $streamId,
		string $filter_target, string $exclude_target, int $start_time, int $stop_time, string $session_id): array {
		switch ($type) {
			case 'f':	//feed
				if ($streamId != '' && is_string($streamId) && !is_numeric($streamId)) {
					$feedDAO = FreshRSS_Factory::createFeedDao();
					$streamId = htmlspecialchars($streamId, ENT_COMPAT, 'UTF-8');
					$feed = $feedDAO->searchByUrl($streamId);
					$streamId = $feed == null ? 0 : $feed->id();
				}
				break;
			case 'c':	//category or label
				$categoryDAO = FreshRSS_Factory::createCategoryDao();
				$streamId = htmlspecialchars((string)$streamId, ENT_COMPAT, 'UTF-8');
				$cat = $categoryDAO->searchByName($streamId);
				if ($cat != null) {
					$type = 'c';
					$streamId = $cat->id();
				} else {
					$tagDAO = FreshRSS_Factory::createTagDao();
					$tag = $tagDAO->searchByName($streamId);
					if ($tag != null) {
						$type = 't';
						$streamId = $tag->id();
					} else {
						$type = 'A';
						$streamId = -1;
					}
				}
				break;
		}
		$streamId = (int)$streamId;
        //error_log(print_r($_SERVER, true));

		switch ($filter_target) {
			case 'user/-/state/com.google/read':
				$state = 'all_articles';
				break;
			case 'user/-/state/com.google/unread':
				$state = 'unread';
				break;
			case 'user/-/state/com.google/starred':
				$state = 'marked';
				break;
			default:
				$state = 'all_articles';
				break;
		}

		switch ($exclude_target) {
			case 'user/-/state/com.google/read':
				$state &= 'unread';
				break;
			case 'user/-/state/com.google/unread':
				$state &= 'all_articles';
				break;
			case 'user/-/state/com.google/starred':
				$state &= 'marked';
				break;
		}
        $searches = '';
        

		$searches = new FreshRSS_BooleanSearch('');
		if ($start_time != '') {
			$search = new FreshRSS_Search('');
			$search->setMinDate($start_time);
			$searches->add($search);
		}
		if ($stop_time != '') {
			$search = new FreshRSS_Search('');
			$search->setMaxDate($stop_time);
			$searches->add($search);
		}
        
		return array($type, $streamId, $state, $searches);
	}

	/** @return never */

	private static function streamContentsItemsIds($streamId, $start_time, $stop_time, $count, $order, $filter_target, $exclude_target, $continuation, $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
	
		$params = [
			'limit' => $count,
			'skip' => $continuation ? intval($continuation) : 0,
			'since_id' => $start_time,
			'include_attachments' => false,
			'view_mode' => 'unread', // Adjust as needed
			'feed_id' => -4,
		];

		if (strpos($streamId, 'feed/') === 0) {
			$params['feed_id'] = substr($streamId, 5); // Remove 'feed/' prefix
		} elseif (strpos($streamId, 'user/-/label/') === 0) {
			$params['cat_id'] = substr($streamId, 13); // Remove 'user/-/label/' prefix
		} elseif ($streamId === 'user/-/state/com.google/reading-list') {
			$params['feed_id'] = -4; // All articles in TTRSS
		} elseif ($streamId === 'user/-/state/com.google/starred') {
			$params['feed_id'] = -1; // Starred articles in TTRSS
			$params['view_mode'] = 'marked';
		}
		error_log(print_r($params, true));
		$response = self::callTinyTinyRssApi('getHeadlines', $params, $session_id);
		if ($response && isset($response['status']) && $response['status'] == 0) {
			$itemRefs = [];
			foreach ($response['content'] as $article) {
				$itemRefs[] = [
					'id' => 'tag:google.com,2005:reader/item/' . $article['id'],
					'directStreamIds' => ['feed/' . $article['feed_id']],
					'timestampUsec' => $article['updated'] . '000000',
				];
			}
	
			$result = [
				'itemRefs' => $itemRefs,
			];
	
			if (count($itemRefs) >= $count) {
				$result['continuation'] = $params['skip'] + $count;
			}
			echo json_encode($result, JSON_OPTIONS);
			exit();
		}
	
		self::internalServerError();
	}
	private static function streamContentsItems(array $e_ids, string $order, string $session_id) {
		header('Content-Type: application/json; charset=UTF-8');

		// Prepare a comma-separated list of article IDs
		$article_ids = [];
		foreach ($e_ids as $e_id) {
			$article_ids[] = str_replace("tag:google.com,2005:reader/item/", "", $e_id);
		}
		$article_ids_string = implode(',', $article_ids);

		// Make a single API call for all requested articles
		$response = self::callTinyTinyRssApi('getArticle', [
			'article_id' => $article_ids_string,
		], $session_id);

		$items = [];
		if ($response && isset($response['status']) && $response['status'] == 0 && !empty($response['content'])) {
			foreach ($response['content'] as $article) {
				$items[] = self::convertTtrssArticleToGreaderFormat($article);
			}
		}

		$result = [
			'id' => 'user/-/state/com.google/reading-list',
			'updated' => time(),
			'items' => $items,
		];

		echo json_encode($result, JSON_OPTIONS);
		exit();
	}

	private static function streamContents(string $path, string $include_target, int $start_time, int $stop_time, int $count,
		string $order, string $filter_target, string $exclude_target, string $continuation, string $session_id) {
		header('Content-Type: application/json; charset=UTF-8');

		$params = [
			'limit' => $count,
			'skip' => $continuation ? intval($continuation) : 0,
			'since_id' => $start_time,
			'include_attachments' => true,
		];

		if ($path === 'feed') {
			$params['feed_id'] = substr($include_target, 5); // Remove 'feed/' prefix
		} elseif ($path === 'label') {
			$params['cat_id'] = $include_target;
		} elseif ($path === 'starred') {
			$params['feed_id'] = -1; // Starred articles in TTRSS
		} else {
			$params['feed_id'] = -4; // All articles in TTRSS
		}

		$response = self::callTinyTinyRssApi('getHeadlines', $params, $session_id);

		if ($response && isset($response['status']) && $response['status'] == 0) {
			$items = [];
			foreach ($response['content'][0] as $article) {
				$items[] = self::convertTtrssArticleToGreaderFormat($article);
			}

			$result = [
				'id' => $path === 'feed' ? $include_target : 'user/-/state/com.google/reading-list',
				'updated' => time(),
				'items' => $items,
			];

			if (count($items) >= $count) {
				$result['continuation'] = $params['skip'] + $count;
			}

			echo json_encode($result, JSON_OPTIONS);
			exit();
		}

		self::internalServerError();
	}

	private static function convertTtrssArticleToGreaderFormat($article) {
		//error_log(print_r($article, true));
		return [
			'id' => 'tag:google.com,2005:reader/item/' . $article['id'],
			'title' => $article['title'],
			'published' => date(DATE_ATOM, $article['updated']),
			'updated' => date(DATE_ATOM, $article['updated']),
			'alternate' => [
				[
					'href' => $article['link'],
					'type' => 'text/html',
				]
			],
			'summary' => [
				'content' => $article['content'],
			],
			'origin' => [
				'streamId' => 'feed/' . $article['feed_id'],
				'title' => $article['feed_title'],
			],
			'categories' => [
				'user/-/state/com.google/' . ($article['unread'] ? 'unread' : 'read'),
				'user/-/label/' . $article['feed_title'],
			],
		];
	}

	/**
	 * @param array<string> $e_ids
	 * @return never
	 */
	private static function editTag(array $e_ids, string $a, string $r, string $session_id): void {
		$action = '';
		$field = 0;

		if ($a === 'user/-/state/com.google/read') {
			$action = 'updateArticle';
			$field = 2; // Mark as read
		} elseif ($r === 'user/-/state/com.google/read') {
			$action = 'updateArticle';
			$field = 0; // Mark as unread
		} elseif ($a === 'user/-/state/com.google/starred') {
			$action = 'updateArticle';
			$field = 1; // Star
		} elseif ($r === 'user/-/state/com.google/starred') {
			$action = 'updateArticle';
			$field = 3; // Unstar
		}

		if ($action && $field !== 0) {
			foreach ($e_ids as $e_id) {
				$article_id = hex2dec(basename($e_id));
				self::callTinyTinyRssApi($action, [
					'article_ids' => $article_id,
					'mode' => $field,
					'field' => $field
				], $session_id);
			}
		}

		exit('OK');
	}

	/** @return never */
	public static function parse() {
		global $ORIGINAL_INPUT;

		header('Access-Control-Allow-Headers: Authorization');
		header('Access-Control-Allow-Methods: GET, POST');
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Max-Age: 600');
		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
			self::noContent();
		}

		$pathInfo = '';
		if (empty($_SERVER['PATH_INFO'])) {
			if (!empty($_SERVER['ORIG_PATH_INFO'])) {
				// Compatibility https://php.net/reserved.variables.server
				$pathInfo = $_SERVER['ORIG_PATH_INFO'];
			}
		} else {
			$pathInfo = $_SERVER['PATH_INFO'];
		}
        error_log(print_r($pathInfo,true));
        error_log(print_r($_GET,true));
		$pathInfo = urldecode($pathInfo);
		$pathInfo = '' . preg_replace('%^(/api)?(/greader\.php)?%', '', $pathInfo);	//Discard common errors
		if ($pathInfo == '' && empty($_SERVER['QUERY_STRING'])) {
			exit('OK');
		}
		$pathInfos = explode('/', $pathInfo);
		if (count($pathInfos) < 3) {
			self::badRequest();
		}

		if ($pathInfos[1] === 'accounts') {
			if (($pathInfos[2] === 'ClientLogin') && isset($_POST['Email']) && isset($_POST['Passwd'])) {
				self::clientLogin($_POST['Email'], $_POST['Passwd']);
			}
		} elseif (isset($pathInfos[3], $pathInfos[4]) && $pathInfos[1] === 'reader' && $pathInfos[2] === 'api' && $pathInfos[3] === '0') {
			$session_id = self::authorizationToUser();

			$timestamp = isset($_GET['ck']) ? (int)$_GET['ck'] : 0;	//ck=[unix timestamp] : Use the current Unix time here, helps Google with caching.
			switch ($pathInfos[4]) {
				case 'stream':
					/* xt=[exclude target] : Used to exclude certain items from the feed.
					* For example, using xt=user/-/state/com.google/read will exclude items
					* that the current user has marked as read, or xt=feed/[feedurl] will
					* exclude items from a particular feed (obviously not useful in this
					* request, but xt appears in other listing requests). */
					$exclude_target = $_GET['xt'] ?? '';
					$filter_target = $_GET['it'] ?? '';
					//n=[integer] : The maximum number of results to return.
					$count = isset($_GET['n']) ? (int)$_GET['n'] : 20;
					//r=[d|n|o] : Sort order of item results. d or n gives items in descending date order, o in ascending order.
					$order = $_GET['r'] ?? 'd';
					/* ot=[unix timestamp] : The time from which you want to retrieve
					* items. Only items that have been crawled by Google Reader after
					* this time will be returned. */
					$start_time = isset($_GET['ot']) ? (int)$_GET['ot'] : 0;
					$stop_time = isset($_GET['nt']) ? (int)$_GET['nt'] : 0;
					/* Continuation token. If a StreamContents response does not represent
					* all items in a timestamp range, it will have a continuation attribute.
					* The same request can be re-issued with the value of that attribute put
					* in this parameter to get more items */
					$continuation = isset($_GET['c']) ? trim($_GET['c']) : '';
					if (!ctype_digit($continuation)) {
						$continuation = '';
					}
					if (isset($pathInfos[5]) && $pathInfos[5] === 'contents') {
						if (!isset($pathInfos[6]) && isset($_GET['s'])) {
							// Compatibility BazQux API https://github.com/bazqux/bazqux-api#fetching-streams
							$streamIdInfos = explode('/', $_GET['s']);
							foreach ($streamIdInfos as $streamIdInfo) {
								$pathInfos[] = $streamIdInfo;
							}
						}
						if (isset($pathInfos[6]) && isset($pathInfos[7])) {
							if ($pathInfos[6] === 'feed') {
								$include_target = $pathInfos[7];
								if ($include_target != '' && !is_numeric($include_target)) {
									$include_target = empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI'];
									if (preg_match('#/reader/api/0/stream/contents/feed/([A-Za-z0-9\'!*()%$_.~+-]+)#', $include_target, $matches) === 1) {
										$include_target = urldecode($matches[1]);
									} else {
										$include_target = '';
									}
								}
								self::streamContents($pathInfos[6], $include_target, $start_time, $stop_time,
									$count, $order, $filter_target, $exclude_target, $continuation, $session_id);
							} elseif (isset($pathInfos[8], $pathInfos[9]) && $pathInfos[6] === 'user') {
								if ($pathInfos[8] === 'state') {
									if ($pathInfos[9] === 'com.google' && isset($pathInfos[10])) {
										if ($pathInfos[10] === 'reading-list' || $pathInfos[10] === 'starred') {
											$include_target = '';
											self::streamContents($pathInfos[10], $include_target, $start_time, $stop_time, $count, $order,
												$filter_target, $exclude_target, $continuation, $session_id);
										}
									}
								} elseif ($pathInfos[8] === 'label') {
									$include_target = $pathInfos[9];
									self::streamContents($pathInfos[8], $include_target, $start_time, $stop_time,
										$count, $order, $filter_target, $exclude_target, $continuation, $session_id);
								}
							}
						} else {	//EasyRSS, FeedMe
							$include_target = '';
							self::streamContents('reading-list', $include_target, $start_time, $stop_time,
								$count, $order, $filter_target, $exclude_target, $continuation, $session_id);
						}
					} elseif ($pathInfos[5] === 'items') {
						if ($pathInfos[6] === 'ids' && isset($_GET['s'])) {
							/* StreamId for which to fetch the item IDs. The parameter may
							* be repeated to fetch the item IDs from multiple streams at once
							* (more efficient from a backend perspective than multiple requests). */
							$streamId = $_GET['s'];
							self::streamContentsItemsIds($streamId, $start_time, $stop_time, $count, $order, $filter_target, $exclude_target, $continuation, $session_id);
						} elseif ($pathInfos[6] === 'contents' && isset($_POST['i'])) {	//FeedMe
							$e_ids = multiplePosts('i');	//item IDs
							self::streamContentsItems($e_ids, $order, $session_id);
						}
					}
					break;
				case 'tag':
					if (isset($pathInfos[5]) && $pathInfos[5] === 'list') {
						$output = $_GET['output'] ?? '';
						if ($output !== 'json') self::notImplemented();
						self::tagList($session_id);
					}
					break;
				case 'subscription':
					if (isset($pathInfos[5])) {
						switch ($pathInfos[5]) {
							case 'export':
								self::subscriptionExport();
								// Always exits
							case 'import':
								if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && $ORIGINAL_INPUT != '') {
									self::subscriptionImport($ORIGINAL_INPUT);
								}
								break;
							case 'list':
								$output = $_GET['output'] ?? '';
								if ($output !== 'json') self::notImplemented();
								self::subscriptionList($session_id);
								// Always exits
							case 'edit':
								if (isset($_REQUEST['s'], $_REQUEST['ac'])) {
									//StreamId to operate on. The parameter may be repeated to edit multiple subscriptions at once
									$streamNames = empty($_POST['s']) && isset($_GET['s']) ? array($_GET['s']) : multiplePosts('s');
									/* Title to use for the subscription. For the `subscribe` action,
									* if not specified then the feed's current title will be used. Can
									* be used with the `edit` action to rename a subscription */
									$titles = empty($_POST['t']) && isset($_GET['t']) ? array($_GET['t']) : multiplePosts('t');
									$action = $_REQUEST['ac'];	//Action to perform on the given StreamId. Possible values are `subscribe`, `unsubscribe` and `edit`
									$add = $_REQUEST['a'] ?? '';	//StreamId to add the subscription to (generally a user label)
									$remove = $_REQUEST['r'] ?? '';	//StreamId to remove the subscription from (generally a user label)
									self::subscriptionEdit($streamNames, $titles, $action, $add, $remove);
								}
								break;
							case 'quickadd':	//https://github.com/theoldreader/api
								if (isset($_REQUEST['quickadd'])) {
									self::quickadd($_REQUEST['quickadd']);
								}
								break;
						}
					}
					break;
				case 'unread-count':
					$output = $_GET['output'] ?? '';
					if ($output !== 'json') self::notImplemented();
					self::unreadCount();
					// Always exits
				case 'edit-tag':	//http://blog.martindoms.com/2010/01/20/using-the-google-reader-api-part-3/
					$token = isset($_POST['T']) ? trim($_POST['T']) : '';
					self::checkToken(FreshRSS_Context::userConf(), $token);
					$a = $_POST['a'] ?? '';	//Add:	user/-/state/com.google/read	user/-/state/com.google/starred
					$r = $_POST['r'] ?? '';	//Remove:	user/-/state/com.google/read	user/-/state/com.google/starred
					$e_ids = multiplePosts('i');	//item IDs
					self::editTag($e_ids, $a, $r, $session_id);
					// Always exits
				case 'rename-tag':	//https://github.com/theoldreader/api
					$token = isset($_POST['T']) ? trim($_POST['T']) : '';
					self::checkToken(FreshRSS_Context::userConf(), $token);
					$s = $_POST['s'] ?? '';	//user/-/label/Folder
					$dest = $_POST['dest'] ?? '';	//user/-/label/NewFolder
					self::renameTag($s, $dest);
					// Always exits
				case 'disable-tag':	//https://github.com/theoldreader/api
					$token = isset($_POST['T']) ? trim($_POST['T']) : '';
					self::checkToken(FreshRSS_Context::userConf(), $token);
					$s_s = multiplePosts('s');
					foreach ($s_s as $s) {
						self::disableTag($s);	//user/-/label/Folder
					}
					// Always exits
				case 'mark-all-as-read':
					$token = isset($_POST['T']) ? trim($_POST['T']) : '';
					self::checkToken(FreshRSS_Context::userConf(), $token);
					$streamId = trim($_POST['s'] ?? '');
					$ts = trim($_POST['ts'] ?? '0');	//Older than timestamp in nanoseconds
					if (!ctype_digit($ts)) {
						self::badRequest();
					}
					self::markAllAsRead($streamId, $ts);
					// Always exits
				case 'token':
					self::token($session_id);
					// Always exits
				case 'user-info':
					self::userInfo();
					// Always exits
			}
		}

		self::badRequest();
	}
}

GReaderAPI::parse();