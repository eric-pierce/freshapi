<?php

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

function escapeToUnicodeAlternative(string $text, bool $extended = false): string {
	// Decode all entities (including numeric ones like &#8220;) before replacing characters
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

	//Problematic characters
	$problem = array('&', '<', '>');
	//Use their fullwidth Unicode form instead:
	$replace = array('＆', '＜', '＞');

	// https://raw.githubusercontent.com/mihaip/google-reader-api/master/wiki/StreamId.wiki
	// Quotes and ^ are deliberately left alone: an earlier `+=` array union meant they were never replaced,
	// and clients have always received them as-is
	if ($extended) {
		$problem = array_merge($problem, array('?', '\\', '/', ';'));
		$replace = array_merge($replace, array('？', '＼', '／', '；'));
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

// Minimal request context for error logs. Never include request bodies, cookies or headers here:
// they carry passwords (ClientLogin) and session tokens (Authorization)
function debugInfo(): string {
	$path = $_SERVER['PATH_INFO'] ?? ($_SERVER['ORIG_PATH_INFO'] ?? '');
	return ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path . ' UA=' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function freshapiEnabledForUser(): bool {
	// System-wide plugins are loaded by init_plugins(), per-user ones by UserHelper::load_user_plugins()
	return PluginHost::getInstance()->get_plugin('FreshAPI') !== null;
}

// Mirrors the checks TT-RSS's api/index.php and API::before() apply to every request
function freshapiSessionAllowed(int $uid): bool {
	if (method_exists('Sessions', 'validate_session')) {
		$valid = Sessions::validate_session();
	} else { // TT-RSS releases before the Sessions class
		$valid = \Sessions\validate_session();
	}
	if (!$valid) {
		return false;
	}
	if (!Prefs::get(Prefs::ENABLE_API_ACCESS, $uid)) {
		return false;
	}
	UserHelper::load_user_plugins($uid);
	return freshapiEnabledForUser();
}

// Category and label names reach clients HTML-decoded (see tagList), while older TT-RSS versions stored them escaped
function nameMatches(?string $stored, string $wanted): bool {
	return $stored !== null && ($stored === $wanted || htmlspecialchars_decode($stored, ENT_QUOTES) === $wanted);
}

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

function dec2hex($dec): string {
	return PHP_INT_SIZE < 8 ? // 32-bit ?
		str_pad(gmp_strval(gmp_init($dec, 10), 16), 16, '0', STR_PAD_LEFT) :
		str_pad(dechex((int)($dec)), 16, '0', STR_PAD_LEFT);
}

final class FreshGReaderAPI extends API {

	/** @return never */
	private function noContent() {
		header('HTTP/1.1 204 No Content');
		exit();
	}

	/** @return never */
	private function badRequest() {
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 400 Bad Request');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Bad Request!');
	}

	/** @return never */
	private function unauthorized() {
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 401 Unauthorized');
		header('Content-Type: text/plain; charset=UTF-8');
		header('Google-Bad-Token: true');
		die('Unauthorized!');
	}

	/** @return never */
	private function internalServerError() {
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 500 Internal Server Error');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Internal Server Error!');
	}

	/** @return never */
	private function notImplemented() {
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 501 Not Implemented');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Not Implemented!');
	}

	/** @return never */
	private function serviceUnavailable() {
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 503 Service Unavailable');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Service Unavailable!');
	}

	/** @return never */
	private function apiNotEnabled() {
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 503 Service Unavailable');
		header('Content-Type: text/plain; charset=UTF-8');
		die('API access or the FreshAPI plugin is not enabled for this user in the TT-RSS Preferences!');
	}

    private function triggerGarbageCollection(): void {
        if (gc_enabled()) {
            gc_collect_cycles();
            if (function_exists('gc_mem_caches')) {
                gc_mem_caches();
            }
        }
    }

    private string $capturedOutput = '';

    // Function to make API requests with session management
    private function callTinyTinyRssApi($operation, $params = [], $session_id = null) {
		if ($session_id) {
            $params['sid'] = $session_id;
        }

        $params['op'] = $operation;
        $savedRequest = $_REQUEST;
        $_REQUEST = $params;

		ob_start();
		try {
			if ($operation && method_exists($this, $operation)) {
				$result = parent::$operation($_REQUEST);
			} else  { //if (method_exists($handler, 'index'))
				$result = $this->index($operation);
			}
		} finally {
			$this->capturedOutput = (string)ob_get_clean();
			$_REQUEST = $savedRequest;
		}

		// If the result is true (indicating success), return the captured output
		if ($result === true) {
			return json_decode($this->capturedOutput, true);
		}
        return $result;
    }

	// Function to check if the session is still valid
    private function isSessionActive($session_id) {
        $response = self::callTinyTinyRssApi('isLoggedIn', [], $session_id);
        return $response && isset($response['status']) && $response['status'] == 0 && $response['content']['status'] === true;
    }

	private function authorizationToUser(): string {
		$headerAuth = headerVariable('Authorization', 'GoogleLogin_auth');
		if ($headerAuth != '') {
			$headerAuthX = explode('/', $headerAuth, 2);
			if (count($headerAuthX) === 2) {
				$email = $headerAuthX[0];
				$session_id = $headerAuthX[1];
				// The token is "username/session_id": the username must belong to the session
				$userMatches = Config::get(Config::SINGLE_USER_MODE) || strcasecmp($email, (string)($_SESSION['name'] ?? '')) === 0;
				if ($userMatches && self::isSessionActive($session_id)) {
					return $session_id;
				}
			}
		}
		return '';
	}

	/** @return never */
	private function clientLogin(string $email, string $password) {
		// Always check the credentials, even when the request also carries a valid session token
		$loginResponse = self::callTinyTinyRssApi('login', [
			'user' => $email,
			'password' => $password
		]);
		if (!($loginResponse && isset($loginResponse['status']) && $loginResponse['status'] == 0)) {
			self::unauthorized();
		}
		// API::login loads the user's plugins, so this reflects the per-user plugin preference
		if (!freshapiEnabledForUser()) {
			session_destroy();
			self::apiNotEnabled();
		}
		$session_id = $loginResponse['content']['session_id'];
		// Format the response as expected by Google Reader API clients
		$auth = ($_SESSION['name'] ?? $email) . '/' . $session_id;
		$response = "SID={$auth}\n";
		$response .= "LSID=\n";
		$response .= "Auth={$auth}\n";
		header('Content-Type: text/plain; charset=UTF-8');
		header('Cache-Control: no-store');
		echo $response;
		exit();
	}

	/** @return never */
	private function token(string $session_id) {
		//http://blog.martindoms.com/2009/08/15/using-the-google-reader-api-part-1/
		//https://github.com/ericmann/gReader-Library/blob/master/greader.class.php
		// Clients request this token and send it back as T=, but it isn't verified: every endpoint already
		// requires the Authorization header, which a cross-site request can't forge
		if (!self::isSessionActive($session_id)) {
			self::unauthorized();
		}

		$salt = '';
		try {
			$pdo = Db::pdo();
			$sth = $pdo->prepare("SELECT salt FROM ttrss_users WHERE id = ?");
			$sth->execute([$_SESSION['uid']]);
			$salt = (string)($sth->fetchColumn() ?: '');
		} catch (PDOException $e) {
			error_log("Database error when pulling salt: " . $e->getMessage());
		}
		echo substr(hash('sha256', $session_id . $salt), 0, 57), "\n";	//Must have 57 characters
		exit();
	}

	private function isReadOnlyUser(): bool {
		try {
			$sth = Db::pdo()->prepare("SELECT access_level FROM ttrss_users WHERE id = ?");
			$sth->execute([$_SESSION['uid']]);
			return (int)$sth->fetchColumn() === UserHelper::ACCESS_LEVEL_READONLY;
		} catch (PDOException $e) {
			error_log("Database error when checking access level: " . $e->getMessage());
			return true;
		}
	}

	// TT-RSS blocks read-only users from managing subscriptions; FreshAPI writes some of these tables directly
	private function requireWriteAccess(): void {
		if (self::isReadOnlyUser()) {
			header('HTTP/1.1 403 Forbidden');
			header('Content-Type: text/plain; charset=UTF-8');
			die('Forbidden!');
		}
	}

	/**
	 * Same shape as the API's getCategories(include_empty) response, without the per-category unread counters
	 * that make that call expensive (#16). Callers here only need ids and titles.
	 */
	private function categoriesResponse(): array {
		$cats = [];
		try {
			$sth = Db::pdo()->prepare("SELECT id, title FROM ttrss_feed_categories WHERE owner_uid = ?");
			$sth->execute([$_SESSION['uid']]);
			while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
				$cats[] = ['id' => (int)$row['id'], 'title' => $row['title']];
			}
		} catch (PDOException $e) {
			error_log("Database error when pulling categories: " . $e->getMessage());
			return ['status' => 1, 'content' => []];
		}
		foreach ([Feeds::CATEGORY_LABELS, Feeds::CATEGORY_SPECIAL, Feeds::CATEGORY_UNCATEGORIZED] as $cat_id) {
			$cats[] = ['id' => $cat_id, 'title' => Feeds::_get_cat_title($cat_id, $_SESSION['uid'])];
		}
		return ['status' => 0, 'content' => $cats];
	}

	/** @return array<int, array{id: int, title: string, feed_url: string, site_url: string, cat_id: int}> */
	private function userFeeds(): array {
		$sth = Db::pdo()->prepare("SELECT id, title, feed_url, site_url, COALESCE(cat_id, 0) AS cat_id FROM ttrss_feeds WHERE owner_uid = ? ORDER BY title");
		$sth->execute([$_SESSION['uid']]);
		$feeds = [];
		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$row['id'] = (int)$row['id'];
			$row['cat_id'] = (int)$row['cat_id'];
			$feeds[$row['id']] = $row;
		}
		return $feeds;
	}

	/** @return never */
	private function userInfo() {
		$user = $_SESSION['name'];
		exit(json_encode(array(
				'userId' => $user,
				'userName' => $user,
				'userProfileId' => $user,
				'userEmail' => '',
			), JSON_OPTIONS));
	}

	/** @return never */
	private function tagList($session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		//header('Cache-Control: no-transform');

		$tags = [
			['id' => 'user/-/state/com.google/starred'],
		];

		// Fetch categories
		$categoriesResponse = self::categoriesResponse();
		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				if ($category['id'] != Feeds::CATEGORY_SPECIAL && $category['id'] != Feeds::CATEGORY_LABELS) { // by id: the titles are translated
					$tags[] = [
						'id' => isset($category['title']) ? 'user/-/label/' . htmlspecialchars_decode($category['title'], ENT_QUOTES) : null,
						'type' => 'folder',
					];
				}
			}
		}
		// Fetch labels (tags)
		$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
		if ($labelsResponse && isset($labelsResponse['status']) && $labelsResponse['status'] == 0) {
			foreach ($labelsResponse['content'] as $label) {
				$tags[] = [
					'id' => 'user/-/label/' . htmlspecialchars_decode($label['caption'], ENT_QUOTES),
					'type' => 'tag',
				];
			}
		}
		echo json_encode(['tags' => $tags], JSON_OPTIONS), "\n";
		exit();
	}

	/** @return never */
	private function subscriptionExport(string $session_id) {
		$opml = new OPML($_REQUEST);
		ob_start();
		$opml_exp = $opml->opml_export('', $_SESSION['uid'], false, 1, false);
		$opml_export = ob_get_contents();
		ob_end_clean();
		echo $opml_export;
		exit();
	}

	/** @return never */
	private function subscriptionImport(string $opml, string $session_id) {
		
		if (stripos($opml, '<opml') === false) {
			self::badRequest();
		}

		$ttrss_root = dirname(__DIR__, 3);
		$config_path = $ttrss_root . "/config.php";

		if (!file_exists($config_path)) {
			$ttrss_root = dirname(__DIR__, 2);
		}

		$cache_dir = Config::get(Config::CACHE_DIR);
		if (!str_starts_with($cache_dir, '/')) {
			$cache_dir = $ttrss_root . '/' . $cache_dir;
		}
		// tempnam() creates a unique file (falling back to the system temp dir), so concurrent imports can't collide
		$tmp_file = tempnam($cache_dir . '/upload', 'freshapi_opml_');
		if ($tmp_file === false) {
			self::internalServerError();
		}
		try {
			file_put_contents($tmp_file, $opml);
			$upl_opml = new OPML($_REQUEST);

			ob_start();
			$upl_opml->opml_import($_SESSION["uid"], $tmp_file);
			$capturedOutput = (string)ob_get_clean();
		} finally {
			@unlink($tmp_file);
		}
		$capturedOutput = preg_replace('/(&nbsp;|<br\/>)+/', "\n", $capturedOutput);
		$capturedOutput = $capturedOutput . "Done!";
		echo $capturedOutput;
		exit();
	}

	private function getCategoryId(string $categoryName, string $session_id): int {
		// First, try to find an existing category
		$categoriesResponse = self::categoriesResponse();
		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				if (nameMatches($category['title'], $categoryName)) {
					return $category['id'];
				}
			}
		}
		return 0;
	}

	/** @return never */
	private function subscriptionList($session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		//header('Cache-Control: no-transform');

		$categoriesResponse = self::categoriesResponse();
		$subscriptions = [];
		$categoryMap = [];

		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				$categoryMap[$category['id']] = $category['title'];
			}
		}

		try {
			$feeds = self::userFeeds();
		} catch (PDOException $e) {
			error_log("Database error when pulling feeds: " . $e->getMessage());
			self::internalServerError();
		}
		foreach ($feeds as $feed) {
			$categoryTitle = htmlspecialchars_decode($categoryMap[$feed['cat_id']] ?? '', ENT_QUOTES);
			$subscriptions[] = [
				'id' => 'feed/' . $feed['id'],
				'title' => $feed['title'],
				'categories' => [
					[
						'id' => 'user/-/label/' . $categoryTitle,
						'label' => $categoryTitle
					]
				],
				'url' => $feed['feed_url'] ?? '',
				'htmlUrl' => $feed['site_url'] ?? '',
				'iconUrl' => TTRSS_SELF_URL_PATH . '/public.php?op=feed_icon&id=' . $feed['id'] . '.ico' //TTRSS_SELF_URL_PATH . '/feed-icons/' . $feed['id'] . '.ico'
			];
		}
		echo json_encode(['subscriptions' => $subscriptions], JSON_OPTIONS), "\n";
		exit();
	}

	/** @return never */
	private function renameFeed($feed_id, $title, $uid, $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		//header('Cache-Control: no-transform');

		if (!self::isSessionActive($session_id)) {
			exit();
		}
		$feed_id = clean($feed_id);
		$title = clean($title);

		if (isset($feed_id)) {
			try {
				$pdo = Db::pdo();
				$sth = $pdo->prepare("UPDATE ttrss_feeds SET title = ? WHERE id = ? AND owner_uid = ?");
				return $sth->execute([$title, $feed_id, $uid]);
			} catch (PDOException $e) {
				error_log("Database error when renaming feed: " . $e->getMessage());
				return false;
			}
		}
	}

	private function feedIdByUrl(string $url): int {
		try {
			$sth = Db::pdo()->prepare("SELECT id FROM ttrss_feeds WHERE feed_url = ? AND owner_uid = ?");
			$sth->execute([$url, $_SESSION['uid']]);
			return (int)$sth->fetchColumn();
		} catch (PDOException $e) {
			error_log("Database error when looking up feed: " . $e->getMessage());
			return 0;
		}
	}

	/** Creates a folder and returns its id, or 0 on failure */
	private function createCategory(string $name, int $userId): int {
		$name = clean($name);
		if ($name === '') {
			return 0;
		}
		try {
			$sth = Db::pdo()->prepare("INSERT INTO ttrss_feed_categories (title, owner_uid) VALUES (?, ?) RETURNING id");
			$sth->execute([$name, $userId]);
			return (int)$sth->fetchColumn();
		} catch (PDOException $e) {
			error_log("Database error when creating category: " . $e->getMessage());
			return 0;
		}
	}

	private function addCategoryFeed(int $feedId, int $userId, string $session_id, int $category_id): bool {
		if (!self::isSessionActive($session_id)) {
			exit();
		}
		try {
			$pdo = Db::pdo();
			// Now, update the feed with the new category
			$sth = $pdo->prepare("UPDATE ttrss_feeds SET cat_id = ? WHERE id = ? AND owner_uid = ?");
			return $sth->execute([$category_id, $feedId, $userId]);
	
		} catch (PDOException $e) {
			error_log("Database error when adding category to feed: " . $e->getMessage());
			return false;
		}
	}

	private function removeCategoryFeed(int $feedId, int $userId, string $session_id): bool {
		if (!self::isSessionActive($session_id)) {
			exit();
		}
		try {
			$pdo = Db::pdo();
			$sth = $pdo->prepare("UPDATE ttrss_feeds SET cat_id = NULL WHERE id = ? AND owner_uid = ?");
			return $sth->execute([$feedId, $userId]);
		} catch (PDOException $e) {
			error_log("Database error when removing category from feed: " . $e->getMessage());
			return false;
		}
	}

	private function deleteCategory(int $catId, int $userId, string $session_id): bool {
		if (!self::isSessionActive($session_id)) {
			exit();
		}
		try {
			$pdo = Db::pdo();
			$sth = $pdo->prepare("SELECT count(*) FROM ttrss_feeds WHERE cat_id = ? and owner_uid = ?");
			$sth->execute([$catId, $userId]);
			$count = $sth->fetch()[0];
		} catch (PDOException $e) {
			error_log("Database error when removing category from feed: " . $e->getMessage());
			return false;
		}
		if ($count != 0) {
			error_log("Category Not Empty");
			return false;
		} else {
			try {
				$pdo = Db::pdo();
				$sth = $pdo->prepare("DELETE FROM ttrss_feed_categories WHERE id = ? AND owner_uid = ?");
				return $sth->execute([$catId, $userId]);
			} catch (PDOException $e) {
				error_log("Database error when removing category from feed: " . $e->getMessage());
				return false;
			}
		}
	}

	/**
	 * @param array<string> $streamNames
	 * @param array<string> $titles
	 * @return never
	 */
	private function subscriptionEdit(array $streamNames, array $titles, string $action, string $session_id, string $add = '', string $remove = '') {
		$uid = $_SESSION['uid'];
        if ($uid === null) {
            self::unauthorized();
        }

		// Target folder for subscribe/edit: 0 = none yet, -1 = "Uncategorized" (no folder)
		$category_id = 0;
		$addName = ($add != '' && strpos($add, 'user/-/label/') === 0) ? substr($add, 13) : '';
		if ($addName !== '') {
			foreach (self::categoriesResponse()['content'] as $category) {
				if (nameMatches($category['title'], $addName)) {
					// the virtual categories (Uncategorized, Special, Labels) all mean "no folder"
					$category_id = $category['id'] > 0 ? $category['id'] : -1;
					break;
				}
			}
		}

		foreach ($streamNames as $i => $streamUrl) {
			if (strpos($streamUrl, 'feed/') === 0) {
				$streamUrl = substr($streamUrl, 5);
				if (strpos($streamUrl, 'feed/') === 0) { //doubling up as some readers seem to push double feed/ prefixes here
					$streamUrl = substr($streamUrl, 5);
				}
				$feedId = 0;
				if (is_numeric($streamUrl)) {
					$feedId = (int)$streamUrl;
				} else {
					$feedId = self::feedIdByUrl($streamUrl);
				}

				$title = $titles[$i] ?? '';

				switch ($action) {
					case 'subscribe':
						if ($feedId == 0) {
							try {
								self::subscribeFeed($streamUrl, $session_id, max($category_id, 0));
							} catch (Exception $e) {
								error_log('subscribe error: ' . $e->getMessage());
								self::badRequest();
							}
						}
						break;
					case 'unsubscribe':
						if ($feedId > 0) {
							$unsubscribeResponse = self::callTinyTinyRssApi('unsubscribeFeed', [
								'feed_id' => $feedId,
							], $session_id);
							if (!($unsubscribeResponse && isset($unsubscribeResponse['status']) && $unsubscribeResponse['status'] == 0)) {
								self::badRequest();
							}
						}
						break;
					case 'edit':
						if ($feedId > 0) {
							// Remove first: clients move a feed by sending both r=<old folder> and a=<new folder>,
							// and applying the removal last left the feed without a folder
							if ($remove != '' && strpos($remove, 'user/-/label/') === 0) {
								if (!self::removeCategoryFeed($feedId, $uid, $session_id)) {
									self::badRequest();
								}
							}
							if ($addName !== '') {
								if ($category_id == 0) {
									// create the folder once, even when several feeds are moved into it
									$category_id = self::createCategory($addName, $uid);
								}
								$ok = $category_id > 0
									? self::addCategoryFeed($feedId, $uid, $session_id, $category_id)
									: self::removeCategoryFeed($feedId, $uid, $session_id);
								if (!$ok) {
									self::badRequest();
								}
							}
							if ($title != '') {
								$renameFeedResponse = self::renameFeed($feedId, $title, $uid, $session_id);
								if (!$renameFeedResponse) {
									self::badRequest();
								}
							}
						} else {
							self::badRequest();
						}
						break;
				}
			}
		}
		self::triggerGarbageCollection();
		exit('OK');
	}

	/**
	 * Subscribes to a feed and returns [feed_id, title], or throws on failure
	 * @return array{0: int, 1: string}
	 */
	private function subscribeFeed(string $url, string $session_id, int $category_id = 0): array {
		if (str_starts_with($url, 'feed/')) {
			$url = substr($url, 5);
		}

		// Call Tiny Tiny RSS API to add the feed. The URL is passed as-is: TT-RSS validates it, and
		// HTML-escaping it here turned "&" in query strings into "&amp;"
		$response = self::callTinyTinyRssApi('subscribeToFeed', [
			'feed_url' => $url,
			'category_id' => $category_id,
		], $session_id);

		// status.code: 0 = already subscribed, 1 = subscribed; other codes are errors and carry no feed_id
		$feedId = (int)($response['content']['status']['feed_id'] ?? 0);
		if (!($response && isset($response['status']) && $response['status'] == 0) || $feedId <= 0) {
			throw new Exception('Failed to add feed (code ' . ($response['content']['status']['code'] ?? '?') . ')');
		}

		$sth = Db::pdo()->prepare("SELECT title FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
		$sth->execute([$feedId, $_SESSION['uid']]);
		return [$feedId, (string)$sth->fetchColumn()];
	}

	/** @return never */
	private function quickadd(string $url, string $session_id, int $category_id = 0) {
		header('Content-Type: application/json; charset=UTF-8');
		try {
			[$feedId, $streamName] = self::subscribeFeed($url, $session_id, $category_id);
			exit(json_encode([
				'numResults' => 1,
				'query' => $url,
				'streamId' => 'feed/' . $feedId,
				'streamName' => $streamName,
			], JSON_OPTIONS));
		} catch (Exception $e) {
			error_log('quickadd error: ' . $e->getMessage());
			die(json_encode([
				'numResults' => 0,
				'error' => $e->getMessage(),
			], JSON_OPTIONS));
		}
	}

	private function unreadCount(string $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		//header('Cache-Control: no-transform');

		// Fetch categories
		$categoriesResponse = self::categoriesResponse();
		if (!($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0)) {
			self::internalServerError();
		}
	
		$categories = [];
		foreach ($categoriesResponse['content'] as $category) {
			$categories[$category['id']] = $category['title'];
		}
	
		// Fetch labels
		$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
		if (!($labelsResponse && isset($labelsResponse['status']) && $labelsResponse['status'] == 0)) {
			self::internalServerError();
		}

		$labels = [];
		foreach ($labelsResponse['content'] as $label) {
			$labels[$label['id']] = $label['caption']; // label[0] is id, label[1] is caption
		}

		$countersResponse = self::callTinyTinyRssApi('getCounters', [], $session_id);
		if (!($countersResponse && isset($countersResponse['status']) && $countersResponse['status'] == 0)) {
			self::internalServerError();
		}
	
		$unreadcounts = [];
		$totalUnreads = 0;
		$maxTimestamp = time();
	
		foreach ($countersResponse['content'] as $counter) {
			$id = '';
			$count = $counter['counter'];
			$lastUpdate = isset($counter['ts']) ? strval($counter['ts']) : '0';
			$lastUpdate = str_pad($lastUpdate, 16, "0", STR_PAD_RIGHT);
			if (isset($counter['kind']) && ($counter['kind'] == 'cat')) {
				if ($counter['id'] == Feeds::CATEGORY_SPECIAL || $counter['id'] == Feeds::CATEGORY_LABELS) {
					continue; // not folders; tag/list doesn't list them either
				}
				$categoryTitle = $categories[$counter['id']] ?? $counter['title'];
				$id = 'user/-/label/' . htmlspecialchars_decode($categoryTitle, ENT_QUOTES);
			} else if (isset($counter['title'])) {
				$id = 'feed/' . $counter['id'];
			} else if ($counter['id'] && array_key_exists('description', $counter)) {
				$labelTitle = $labels[$counter['id']] ?? $counter['description']; 
				$id = 'user/-/label/' . htmlspecialchars_decode($labelTitle, ENT_QUOTES);
			} else if ($counter['id'] == 'global-unread') {
				$id = 'user/-/state/com.google/reading-list';
				$totalUnreads = $count;
			}else {
				continue;
			}
	
			$unreadcounts[] = [
				'id' => $id,
				'count' => $count,
				'newestItemTimestampUsec' => $lastUpdate, //$maxTimestamp . '000000',
			];
		}

		$result = [
			'max' => $totalUnreads,
			'unreadcounts' => $unreadcounts,
		];
		echo json_encode($result, JSON_OPTIONS), "\n";
		exit();
	}

	private function streamContentsItemsIds($streamId, $start_time, $stop_time, $count, $order, $filter_target, $exclude_target, $continuation, $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		// Everything is answered with one SQL query: states (read/starred/reading-list), feeds, folders and labels.
		// Pages are keyed on the last returned ref_id rather than OFFSET, and the ot filter uses the time TT-RSS
		// fetched the article (date_entered), so back-dated articles aren't dropped (#16)
		$streamId = self::normalizeStreamId($streamId);
		$where = ['a.owner_uid = ?'];
		$args = [$_SESSION['uid']];
		$readOnly = false;
		$exists = true;
		switch ($streamId) {
			case 'user/-/state/com.google/read':
				$readOnly = true;
				break;
			case 'user/-/state/com.google/starred':
				$where[] = 'a.marked = true';
				break;
			case 'user/-/state/com.google/reading-list':
				break;
			default:
				$scope = self::streamScope($streamId, $session_id);
				if ($scope === null) {
					$exists = false;
				} else {
					$where = array_merge($where, $scope[0]);
					$args = array_merge($args, $scope[1]);
				}
		}
		if ($exclude_target == 'user/-/state/com.google/unread') {
			$readOnly = true;
		}
		if ($readOnly) {
			// "read since ot" is based on when the article was marked read
			$where[] = 'a.unread = false';
			$where[] = "a.last_read >= (to_timestamp(?) AT TIME ZONE 'UTC')";
			$join = '';
		} else {
			if ($exclude_target == 'user/-/state/com.google/read') {
				$where[] = 'a.unread = true';
			}
			$where[] = "b.date_entered >= (to_timestamp(?) AT TIME ZONE 'UTC')";
			$join = 'INNER JOIN ttrss_entries b ON a.ref_id = b.id';
		}
		$args[] = isset($start_time) ? intval($start_time) : 0;

		$ascending = ($order == 'o');
		$offset = $continuation ? intval($continuation) : 0;
		if ($offset > 0) {
			$where[] = $ascending ? 'a.ref_id > ?' : 'a.ref_id < ?';
			$args[] = $offset;
		}
		$args[] = $count + 1; // one extra row tells us whether another page exists

		$items = [];
		if ($exists) {
			try {
				$sth = Db::pdo()->prepare("SELECT a.ref_id::varchar AS id FROM ttrss_user_entries a $join
					WHERE " . implode(' AND ', $where) . "
					ORDER BY a.ref_id " . ($ascending ? 'ASC' : 'DESC') . "
					LIMIT ?");
				$sth->execute($args);
				$items = $sth->fetchAll(PDO::FETCH_ASSOC);
			} catch (PDOException $e) {
				error_log("Database error when pulling item ids: " . $e->getMessage());
				self::badRequest();
			}
		}

		$result = ['itemRefs' => $items];
		if (count($items) > $count) {
			array_pop($result['itemRefs']);
			$result['continuation'] = end($result['itemRefs'])['id'];
		}
		unset($items);
		self::triggerGarbageCollection();
		echo json_encode($result, JSON_OPTIONS), "\n";
		exit();
	}

	// Some clients put their user id in stream ids (user/1234/state/...) instead of "-"
	private static function normalizeStreamId(string $streamId): string {
		return preg_replace('#^user/[^/]+/(state|label)/#', 'user/-/$1/', $streamId);
	}

	/**
	 * SQL conditions (on ttrss_user_entries aliased "a") selecting the articles of a feed/ or user/-/label/ stream.
	 * Returns null when the stream doesn't exist. Folders cover the feeds directly in them, as clients show them.
	 * @return array{0: array<string>, 1: array<mixed>}|null
	 */
	private function streamScope(string $streamId, string $session_id): ?array {
		if (str_starts_with($streamId, 'feed/')) {
			$feed = substr($streamId, 5);
			$feedId = is_numeric($feed) ? (int)$feed : self::feedIdByUrl($feed);
			return $feedId > 0 ? [['a.feed_id = ?'], [$feedId]] : null;
		}
		if (str_starts_with($streamId, 'user/-/label/')) {
			$id = self::getCategoryLabelID(substr($streamId, 13), $session_id);
			if ($id === null) {
				return null;
			}
			if ($id == Feeds::CATEGORY_UNCATEGORIZED) {
				return [['a.feed_id IN (SELECT id FROM ttrss_feeds WHERE owner_uid = ? AND cat_id IS NULL)'], [$_SESSION['uid']]];
			}
			if ($id > 0) {
				return [['a.feed_id IN (SELECT id FROM ttrss_feeds WHERE owner_uid = ? AND cat_id = ?)'], [$_SESSION['uid'], $id]];
			}
			if ($id < LABEL_BASE_INDEX) {
				return [['a.ref_id IN (SELECT article_id FROM ttrss_user_labels2 WHERE label_id = ?)'], [Labels::feed_to_label_id($id)]];
			}
		}
		return null; // unknown stream, or the virtual Special/Labels categories
	}

	private function getCategoryLabelID($cat_id, $session_id) {
		// First, check if it's a category
		$categoryResponse = self::categoriesResponse();
		$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
		if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
			foreach ($categoryResponse['content'] as $category) {
				if (nameMatches($category['title'], $cat_id)) {
					return intval($category['id']);
				}
			}
		} 
		// Not a Category, must be a label. Note that if a label and category have the same name, we'll always return the category
		if ($labelsResponse && isset($labelsResponse['status']) && $labelsResponse['status'] == 0) {
			foreach ($labelsResponse['content'] as $label) {
				if (nameMatches($label['caption'], $cat_id)) {
					return (intval($label['id']));
				}
			}
		}
		return null;
	}

	private function streamContentsItems(array $e_ids, string $order, string $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		//header('Cache-Control: no-transform');
		
		// Fetch categories
		$categoriesResponse = self::categoriesResponse();
		$categoryMap = [];
		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				$categoryMap[$category['id']] = $category['title'];
			}
		}

		// Fetch feeds to get the category mapping
		$feedCategoryMap = [];
		try {
			foreach (self::userFeeds() as $feed) {
				$feedCategoryMap[$feed['id']] = [
					'category_id' => $feed['cat_id'],
					'category_name' => htmlspecialchars_decode($categoryMap[$feed['cat_id']] ?? 'Uncategorized', ENT_QUOTES),
				];
			}
		} catch (PDOException $e) {
			error_log("Database error when pulling feeds: " . $e->getMessage());
		}
		foreach ($e_ids as $i => $e_id) {
			// https://feedhq.readthedocs.io/en/latest/api/terminology.html#items
			if (!ctype_digit($e_id) || $e_id[0] === '0' || (substr($_SERVER['HTTP_USER_AGENT'], 0, 11) == 'NetNewsWire')) {
				$e_ids[$i] = hex2dec(basename($e_id));	//Strip prefix 'tag:google.com,2005:reader/item/'
			}
		}
		$article_ids_string = implode(',', $e_ids);
		// Make a single API call for all requested articles
		$response = self::callTinyTinyRssApi('getArticle', [
			'article_id' => $article_ids_string,
		], $session_id);
		$items = [];
		if ($response && isset($response['status']) && $response['status'] == 0 && !empty($response['content'])) {
			foreach ($response['content'] as $article) {				
				// Add category information
				if (isset($feedCategoryMap[$article['feed_id']])) {
					$categoryInfo = $feedCategoryMap[$article['feed_id']];
					if (empty($article['feed_title'])) {
						$article['feed_title'] = $categoryInfo['category_name'];
					}
					$greaderArticle = self::convertTtrssArticleToGreaderFormat($article);
					$greaderArticle['categories'][] = 'user/-/label/' . $categoryInfo['category_name'];
				} else {
					$greaderArticle = self::convertTtrssArticleToGreaderFormat($article);
				}
				$items[] = $greaderArticle;
			}
		}

		// Sort items based on the order parameter
		if ($order === 'o') {  // Ascending order
			usort($items, function($a, $b) {
				return $a['published'] - $b['published'];
			});
		} else {  // Descending order (default)
			usort($items, function($a, $b) {
				return $b['published'] - $a['published'];
			});
		}
		$result = [
			'id' => 'user/-/state/com.google/reading-list',
			'updated' => time(),
			'items' => $items,
		];
		echo json_encode($result, JSON_OPTIONS);
		exit();
	}

	private function streamContents($path, $streamId, $start_time, $stop_time, $count, $order, $filter_target, $exclude_target, $continuation, $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		$params = [
			'limit' => $count ? intval($count) : 0, // Max articles to send to client
			'skip' => $continuation ? intval($continuation) : 0, //May look at replacing this with since_id
			//'since_id' => $start_time,
			'include_attachments' => true,
			'view_mode' => (($path == 'user/-/state/com.google/starred') || ($path == 'starred')) ? 'marked' : 'unread', //this appears to only support starred and unread in testing with fluent reader
			'feed_id' => -4, //setting to all articles by default
			'order_by' => ($order == 'o') ? 'date_reverse' : 'feed_dates',
			'show_content' => true,
		];
		$itemRefs = [];
		$totalFetched = 0;
		$moreAvailable = false;
		$min_date = isset($start_time) ? intval($start_time) : 0;
		$offset = $continuation ? intval($continuation) : 0;
		// Scope the request to the feed or label/category the client asked for
		if ($path === 'feed' && $streamId !== '') {
			$params['feed_id'] = is_numeric($streamId) ? (int)$streamId : self::feedIdByUrl($streamId);
			if ($params['feed_id'] <= 0) {
				$count = 0; // unknown feed: return an empty stream rather than every article
			}
		} elseif ($path === 'label' && $streamId !== '') {
			$cat_or_label = self::getCategoryLabelID($streamId, $session_id);
			if ($cat_or_label === null) {
				$count = 0;
			} else {
				if ($cat_or_label > -10) { // below -10 are Labels, above are categories
					$params['is_cat'] = true;
				}
				$params['feed_id'] = $cat_or_label;
			}
		}
		while (($totalFetched < $count) && ($totalFetched <= 15000)) { //setting max cap just in case
			$response = self::callTinyTinyRssApi('getHeadlines', $params, $session_id);

			if (!($response && isset($response['status']) && $response['status'] == 0)) {
				self::internalServerError();
			}
	
			$items = $response['content'];
			$itemCount = count($items);

			foreach ($items as $article) {
				if ($totalFetched < $count) {
					$itemRefs[] = self::convertTtrssArticleToGreaderFormat($article);
					$totalFetched++;
				} else {
					$moreAvailable = true;
					break;
				}
			}

			if ($itemCount < 200) {
				// We've reached the end of available items
				break;
			}
			$params['skip'] += $itemCount;
		}

		$result = [
			'id' => match ($path) {
				'feed' => 'feed/' . $streamId,
				'label' => 'user/-/label/' . $streamId,
				'starred' => 'user/-/state/com.google/starred',
				default => 'user/-/state/com.google/reading-list',
			},
			'updated' => time(),
			'items' => $itemRefs,
		];
	
		if ($moreAvailable || ($totalFetched == $count)) {
			// There are more items available
			$result['continuation'] = '' . ($continuation ? intval($continuation) : 0) + $totalFetched;
		}
		unset($itemRefs);
		unset($items);
		unset($response);
		self::triggerGarbageCollection();
		echo json_encode($result, JSON_OPTIONS), "\n";
		exit();
	}

	private function convertTtrssArticleToGreaderFormat($article) {
		$formatted_article = [
			'id' => 'tag:google.com,2005:reader/item/' . dec2hex(strval($article['id'])),
			'crawlTimeMsec' => $article['updated'] . '000',
			'timestampUsec' => $article['updated'] . '000000', //EasyRSS & Reeder
			'published' => $article['updated'],
			'title' => (array_key_exists('title', $article) && !empty($article['title'])) ? escapeToUnicodeAlternative($article['title'], true) : '',
			//'updated' => date(DATE_ATOM, $article['updated']),
			'canonical' => [
				['href' => htmlspecialchars_decode($article['link'], ENT_QUOTES)]
			],
			'alternate' => [
				[
					'href' => htmlspecialchars_decode($article['link'], ENT_QUOTES),
					//'type' => 'text/html',
				]
			],
			'categories' => [
				'user/-/state/com.google/' . ($article['unread'] ? 'reading-list' : 'read'),
			],
			'origin' => [
				'streamId' => 'feed/' . $article['feed_id'],
				'title' => (array_key_exists('feed_title', $article) && !empty($article['feed_title'])) ? escapeToUnicodeAlternative($article['feed_title'], true) : '',
			],
			'summary' => [
				//'content' => $article['content'],
				'content' => isset($article['content']) ? mb_strcut($article['content'], 0, 500000, 'UTF-8') : '',
			],
			'author' => $article['author'],
		];
		// Add starred state
		if (isset($article['marked']) && $article['marked']) {
			$formatted_article['categories'][] = 'user/-/state/com.google/starred';
		}

		if (isset($article['link'])) {
			$components = parse_url($article['link']);
			$site_url = $components['scheme'] . '://' . $components['host'];
			$formatted_article['origin']['htmlUrl'] = htmlspecialchars_decode($site_url, ENT_QUOTES);
		}

		// Add labels
		if (isset($article['labels']) && is_array($article['labels'])) {
			foreach ($article['labels'] as $label) {
				if (isset($label[0]) && isset($label[1])) {
					$formatted_article['categories'][] = 'user/-/label/' . $label[1];
				}
			}
		}

		// Add enclosures if available
		if (isset($article['attachments']) && !empty($article['attachments'])) {
			$formatted_article['enclosure'] = [];
			foreach ($article['attachments'] as $enclosure) {
				if (!empty($enclosure['duration']) && intval($enclosure['duration']) != 0) {
					$formatted_article['enclosure'][] = [
						'href' => $enclosure['content_url'],
						'type' => $enclosure['content_type'],
						'length' => $enclosure['duration'],
					];
				} else {
					$formatted_article['enclosure'][] = [
						'href' => $enclosure['content_url'],
						'type' => $enclosure['content_type'],
					];
				}

			}
		}
	
		return $formatted_article;
	}

	/**
	 * @param array<string> $e_ids
	 * @return never
	 */
	private function editTag(array $e_ids, string $a, string $r, string $session_id): void {
		$action = '';
		$field = 0;

		if ($a === 'user/-/state/com.google/read') {
			$action = 'updateArticle';
			$mode = 0; // Add Read Flag
			$field = 2; // Mark as read
		} elseif ($r === 'user/-/state/com.google/read') {
			$action = 'updateArticle';
			$mode = 1; // Remove Read Flag
			$field = 2; // Mark as unread
		} elseif ($a === 'user/-/state/com.google/starred') {
			$action = 'updateArticle';
			$mode = 1; // Add Star
			$field = 0; // Type is Star
		} elseif ($r === 'user/-/state/com.google/starred') {
			$action = 'updateArticle';
			$mode = 0; // Remove Star
			$field = 0; // Unstar
		}

		if ($action) {
			foreach ($e_ids as $e_id) {
				if (!ctype_digit($e_id) || $e_id[0] === '0' || (substr($_SERVER['HTTP_USER_AGENT'], 0, 11) == 'NetNewsWire')) {
					$article_id = hex2dec(basename($e_id));	//Strip prefix 'tag:google.com,2005:reader/item/'
				} else {
					$article_id = $e_id;
				}
				$result = self::callTinyTinyRssApi($action, [
					'article_ids' => $article_id,
					'mode' => $mode,
					'field' => $field
				], $session_id);
			}
		}

		// Handle article labels (user/-/label/labelname)
		$add_label = null;
		$remove_label = null;

		if ($a != '' && strpos($a, 'user/-/label/') === 0) {
			$add_label = clean(substr($a, 13));
			if ($add_label === '') {
				$add_label = null;
			}
		}
		if ($r != '' && strpos($r, 'user/-/label/') === 0) {
			$remove_label = clean(substr($r, 13));
		}

		if ($add_label !== null || $remove_label !== null) {
			$uid = $_SESSION['uid'] ?? null;
			if ($uid === null) {
				self::unauthorized();
			}

			foreach ($e_ids as $e_id) {
				if (!ctype_digit($e_id) || $e_id[0] === '0' || (substr($_SERVER['HTTP_USER_AGENT'], 0, 11) == 'NetNewsWire')) {
					$article_id = hex2dec(basename($e_id));
				} else {
					$article_id = (int)$e_id;
				}

				// Add label to article
				if ($add_label !== null) {
					// Create label if it doesn't exist
					if (!Labels::find_id($add_label, $uid)) {
						Labels::create($add_label, '', '', $uid);
					}
					// Add label to article
					Labels::add_article($article_id, $add_label, $uid);
				}

				// Remove label from article
				if ($remove_label !== null) {
					Labels::remove_article($article_id, $remove_label, $uid);
				}
			}
		}

		exit('OK');
	}

	/** @return never */
	private function renameTag(string $s, string $dest, string $session_id) {
		if ($s != '' && strpos($s, 'user/-/label/') === 0 &&
			$dest != '' && strpos($dest, 'user/-/label/') === 0) {
			$oldName = substr($s, 13);
			$newName = clean(substr($dest, 13));
			if ($newName === '') {
				self::badRequest();
			}
			// First, check if it's a category
			$categoryResponse = self::categoriesResponse();
			$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
			if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
				foreach ($categoryResponse['content'] as $category) {
					if (nameMatches($category['title'], $oldName)) {
						// It's a category, so we can rename it
						try {
							$pdo = Db::pdo();
							$sth = $pdo->prepare("UPDATE ttrss_feed_categories SET title = ? WHERE id = ? AND owner_uid = ?");
							$sth->execute([$newName, $category['id'], $_SESSION['uid']]);
							exit('OK');
						} catch (PDOException $e) {
							error_log("Database error when renaming feed: " . $e->getMessage());
						}
					}
				}
			} 
			
			if ($labelsResponse && isset($labelsResponse['status']) && $labelsResponse['status'] == 0) {
				foreach ($labelsResponse['content'] as $label) {
					if (nameMatches($label['caption'], $oldName)) {
						// It's a label, so we can rename it
						try {
							$pdo = Db::pdo();
							$sth = $pdo->prepare("UPDATE ttrss_labels2 SET caption = ? WHERE caption = ? AND owner_uid = ?");
							$sth->execute([$newName, $label['caption'], $_SESSION['uid']]);
							exit('OK');
						} catch (PDOException $e) {
							error_log("Database error when renaming feed: " . $e->getMessage());
						}
					}
				}
			}
		}
		self::badRequest();
	}

	/** @return never */
	private function disableTag(string $s, string $session_id) {
		if ($s != '' && strpos($s, 'user/-/label/') === 0) {
			$tagName = substr($s, 13);

			// First, check if it's a category
			$categoryResponse = self::categoriesResponse();
			if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
				foreach ($categoryResponse['content'] as $category) {
					if ($category['id'] > 0 && nameMatches($category['title'], $tagName)) {
						// It's a category, so we need to move all feeds to uncategorized and then delete the category
						try {
							$sth = Db::pdo()->prepare("UPDATE ttrss_feeds SET cat_id = NULL WHERE cat_id = ? AND owner_uid = ?");
							$sth->execute([$category['id'], $_SESSION['uid']]);
						} catch (PDOException $e) {
							error_log("Database error when removing category from feeds: " . $e->getMessage());
							self::badRequest();
						}
						
						// Now delete the category				
						if (!self::deleteCategory($category['id'], $_SESSION['uid'], $session_id)) {
							self::badRequest();
						}
						exit('OK');
					}
				}
			}

			// If it's not a category, it might be a label
			$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
			if ($labelsResponse && isset($labelsResponse['status']) && $labelsResponse['status'] == 0) {
				foreach ($labelsResponse['content'] as $label) {
					if (nameMatches($label['caption'], $tagName)) {
						try {
							$pdo = Db::pdo();
							$sth = $pdo->prepare("SELECT id FROM ttrss_labels2 WHERE caption = ? and owner_uid = ?");
							$sth->execute([$label['caption'], $_SESSION['uid']]);
							$deletelabelid = $sth->fetch()[0];
						} catch (PDOException $e) {
							error_log("Database error when removing category from feed: " . $e->getMessage());
							self::badRequest();
						}
						if ($deletelabelid == null) {
							error_log("Label Not Found");
							self::badRequest();
						} else {
							
							try {
								$pdo = Db::pdo();
								$sth = $pdo->prepare("DELETE FROM ttrss_user_labels2 WHERE label_id = ?");
								$sth->execute([$deletelabelid]);
							} catch (PDOException $e) {
								error_log("Database error when removing category from feed: " . $e->getMessage());
								self::badRequest();
							}
							try {
								$pdo = Db::pdo();
								$sth = $pdo->prepare("DELETE FROM ttrss_labels2 WHERE id = ? AND owner_uid = ?");
								$sth->execute([$deletelabelid, $_SESSION['uid']]);
							} catch (PDOException $e) {
								error_log("Database error when removing category from feed: " . $e->getMessage());
								self::badRequest();
							}
							exit('OK');
						}
					}
				}
			}
		}
		self::badRequest();
	}

	/**
	 * Marks every unread article in a stream as read. With a ts cutoff, only articles TT-RSS had fetched before
	 * that time are marked, so articles that arrived after the client last refreshed stay unread.
	 * (The TT-RSS catchupFeed API supports neither label streams nor a cutoff: labels used to mark every
	 * article read, and the cutoff was ignored.)
	 * @param numeric-string $olderThan
	 * @return never
	 */
	private function markAllAsRead(string $streamId, string $olderThan, string $session_id) {
		$streamId = self::normalizeStreamId($streamId);
		$where = ['a.owner_uid = ?', 'a.unread = true', 'e.id = a.ref_id'];
		$args = [$_SESSION['uid']];

		if ($streamId === 'user/-/state/com.google/starred') {
			$where[] = 'a.marked = true';
		} elseif ($streamId !== 'user/-/state/com.google/reading-list') {
			$scope = self::streamScope($streamId, $session_id);
			if ($scope === null) {
				self::badRequest();
			}
			$where = array_merge($where, $scope[0]);
			$args = array_merge($args, $scope[1]);
		}

		$cutoff = self::timestampToSeconds($olderThan);
		if ($cutoff > 0) {
			$where[] = "e.date_entered < (to_timestamp(?) AT TIME ZONE 'UTC')";
			$args[] = $cutoff;
		}

		try {
			$sth = Db::pdo()->prepare("UPDATE ttrss_user_entries a SET unread = false, last_read = NOW()
				FROM ttrss_entries e WHERE " . implode(' AND ', $where));
			$sth->execute($args);
		} catch (PDOException $e) {
			error_log("Database error when marking stream as read: " . $e->getMessage());
			self::internalServerError();
		}
		exit('OK');
	}

	// Clients send ts in microseconds per the spec, but some send seconds, milliseconds or nanoseconds
	private static function timestampToSeconds(string $ts): int {
		$value = (int)$ts;
		if ($value >= 100000000000000000) return intdiv($value, 1000000000);
		if ($value >= 100000000000000) return intdiv($value, 1000000);
		if ($value >= 100000000000) return intdiv($value, 1000);
		return $value;
	}

	/** @return never */
	public function parse() {
        $ORIG_REQUEST = $_REQUEST;
		global $ORIGINAL_INPUT;
		header('Access-Control-Allow-Headers: Authorization');
		header('Access-Control-Allow-Methods: GET, POST');
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Max-Age: 600');
		//header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
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

		$input = $_REQUEST;

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
			if (($pathInfos[2] === 'ClientLogin') && isset($input['Email']) && isset($input['Passwd'])) {
				self::clientLogin($input['Email'], $input['Passwd']);
			}
		} elseif (isset($pathInfos[3], $pathInfos[4]) && $pathInfos[1] === 'reader' && $pathInfos[2] === 'api' && $pathInfos[3] === '0') {
			$session_id = self::authorizationToUser();
			if ($session_id == '') {
				self::unauthorized();
			}
			$timestamp = isset($input['ck']) ? (int)$input['ck'] : 0;	//ck=[unix timestamp] : Use the current Unix time here, helps Google with caching.
			switch ($pathInfos[4]) {
				case 'stream':
					/* xt=[exclude target] : Used to exclude certain items from the feed.
					* For example, using xt=user/-/state/com.google/read will exclude items
					* that the current user has marked as read, or xt=feed/[feedurl] will
					* exclude items from a particular feed (obviously not useful in this
					* request, but xt appears in other listing requests). */
					$exclude_target = $input['xt'] ?? '';
					$filter_target = $input['it'] ?? '';
					//n=[integer] : The maximum number of results to return.
					$count = isset($input['n']) ? (int)$input['n'] : 20;
					if ($count < 1) {
						$count = 20; // zero or negative values made the queries fail
					}
					$count = min($count, 10000); // unbounded values let a single request run away
					//r=[d|n|o] : Sort order of item results. d or n gives items in descending date order, o in ascending order.
					$order = $input['r'] ?? 'd';
					/* ot=[unix timestamp] : The time from which you want to retrieve
					* items. Only items that have been crawled by Google Reader after
					* this time will be returned. */
					$start_time = isset($input['ot']) ? (int)$input['ot'] : 0;
					$stop_time = isset($input['nt']) ? (int)$input['nt'] : 0;
					/* Continuation token. If a StreamContents response does not represent
					* all items in a timestamp range, it will have a continuation attribute.
					* The same request can be re-issued with the value of that attribute put
					* in this parameter to get more items */
					$continuation = isset($input['c']) ? trim($input['c']) : '';
					if (!ctype_digit($continuation)) {
						$continuation = '';
					}
					if (isset($pathInfos[5]) && $pathInfos[5] === 'contents') {
						if (!isset($pathInfos[6]) && isset($input['s'])) {
							// Compatibility BazQux API https://github.com/bazqux/bazqux-api#fetching-streams
							$streamIdInfos = explode('/', $input['s']);
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
						if ($pathInfos[6] === 'ids' && isset($input['s'])) {
							/* StreamId for which to fetch the item IDs. The parameter may
							* be repeated to fetch the item IDs from multiple streams at once
							* (more efficient from a backend perspective than multiple requests). */
							$streamId = $input['s'];
							self::streamContentsItemsIds($streamId, $start_time, $stop_time, $count, $order, $filter_target, $exclude_target, $continuation, $session_id);
						} elseif ($pathInfos[6] === 'contents' && isset($input['i'])) {	//FeedMe
							$e_ids = multiplePosts('i');	//item IDs
							self::streamContentsItems($e_ids, $order, $session_id);
						}
					}
					self::triggerGarbageCollection();
					break;
				case 'tag':
					if (isset($pathInfos[5]) && $pathInfos[5] === 'list') {
						$output = $input['output'] ?? '';
						if ($output !== 'json') self::notImplemented();
						self::tagList($session_id);
					}
					self::triggerGarbageCollection();
					break;
				case 'subscription':
					if (isset($pathInfos[5])) {
						switch ($pathInfos[5]) {
							case 'export':
								self::subscriptionExport($session_id);
								// Always exits
								break;
							case 'import':
								self::requireWriteAccess();
								if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && $ORIGINAL_INPUT != '') {
									self::subscriptionImport($ORIGINAL_INPUT, $session_id);
								}
								break;
							case 'list':
								$output = $input['output'] ?? '';
								if ($output !== 'json') self::notImplemented();
								self::subscriptionList($session_id);
								// Always exits
								break;
							case 'edit':
								if (isset($ORIG_REQUEST['s'], $ORIG_REQUEST['ac'])) {
									self::requireWriteAccess();
									//StreamId to operate on. The parameter may be repeated to edit multiple subscriptions at once
									$streamNames = empty($input['s']) && isset($input['s']) ? array($input['s']) : multiplePosts('s');
									/* Title to use for the subscription. For the `subscribe` action,
									* if not specified then the feed's current title will be used. Can
									* be used with the `edit` action to rename a subscription */
									$titles = empty($input['t']) && isset($input['t']) ? array($input['t']) : multiplePosts('t');
									$action = $ORIG_REQUEST['ac'];	//Action to perform on the given StreamId. Possible values are `subscribe`, `unsubscribe` and `edit`
									$add = $ORIG_REQUEST['a'] ?? '';	//StreamId to add the subscription to (generally a user label)
									$remove = $ORIG_REQUEST['r'] ?? '';	//StreamId to remove the subscription from (generally a user label)
									self::subscriptionEdit($streamNames, $titles, $action, $session_id, $add, $remove);
								}
								break;
							case 'quickadd':	//https://github.com/theoldreader/api
								if (isset($ORIG_REQUEST['quickadd'])) {
									self::requireWriteAccess();
									self::quickadd($ORIG_REQUEST['quickadd'], $session_id);
								}
								break;
						}
					}
					self::triggerGarbageCollection();
					break;
				case 'unread-count':
					$output = $input['output'] ?? '';
					if ($output !== 'json') self::notImplemented();
					self::unreadCount($session_id);
					// Always exits
					break; //just in case somethign goes wrong
				case 'edit-tag':	//http://blog.martindoms.com/2010/01/20/using-the-google-reader-api-part-3/
					$a = $input['a'] ?? '';	//Add:	user/-/state/com.google/read	user/-/state/com.google/starred
					$r = $input['r'] ?? '';	//Remove:	user/-/state/com.google/read	user/-/state/com.google/starred
					$e_ids = multiplePosts('i');	//item IDs
					self::editTag($e_ids, $a, $r, $session_id);
					// Always exits
					break; //just in case 
				case 'rename-tag':    //https://github.com/theoldreader/api
					self::requireWriteAccess();
					$s = $input['s'] ?? '';    //user/-/label/Folder
					$dest = $input['dest'] ?? '';    //user/-/label/NewFolder
					self::renameTag($s, $dest, $session_id);
					// Always exits
					break; //just in case 
				case 'disable-tag':    //https://github.com/theoldreader/api
					self::requireWriteAccess();
					$s_s = multiplePosts('s');
					foreach ($s_s as $s) {
						self::disableTag($s, $session_id);    //user/-/label/Folder
					}
					// Always exits
					break; //just in case 
				case 'mark-all-as-read':
					$streamId = trim($input['s'] ?? '');
					$ts = trim($input['ts'] ?? '0');    //Older than timestamp in nanoseconds
					if (!ctype_digit($ts)) {
						self::badRequest();
					}
					self::markAllAsRead($streamId, $ts, $session_id);
					// Always exits
					break; //just in case 
				case 'token':
					self::token($session_id);
					// Always exits
					break; //just in case 
				case 'user-info':
					self::userInfo();
					// Always exits
					break; //just in case 
			}
		}
		self::triggerGarbageCollection();
		self::badRequest();
	}
}
