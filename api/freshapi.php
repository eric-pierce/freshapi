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

function dateAdded(bool $raw = false, bool $microsecond = false) {
	if ($raw) {
		if ($microsecond) {
			return time();
		} else {
			return (int)substr(microtime(true), 0, -6);
		}
	} else {
		$date = (int)substr(microtime(true), 0, -6);
		return timestamptodate($date);
	}
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
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 400 Bad Request');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Bad Request!');
	}

	/** @return never */
	private function unauthorized() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 401 Unauthorized');
		header('Content-Type: text/plain; charset=UTF-8');
		header('Google-Bad-Token: true');
		die('Unauthorized!');
	}

	/** @return never */
	private function internalServerError() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 500 Internal Server Error');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Internal Server Error!');
	}

	/** @return never */
	private function notImplemented() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 501 Not Implemented');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Not Implemented!');
	}

	/** @return never */
	private function serviceUnavailable() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 503 Service Unavailable');
		header('Content-Type: text/plain; charset=UTF-8');
		die('Service Unavailable!');
	}

	/** @return never */
	private function apiNotEnabled() {
		error_log(__METHOD__);
		error_log(__METHOD__ . ' ' . debugInfo());
		header('HTTP/1.1 503 Service Unavailable');
		header('Content-Type: text/plain; charset=UTF-8');
		die('API Not Enabled in TT-RSS Pref Pane!');
	}

	/** @return never */
	private function checkCompatibility() {
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

    private function triggerGarbageCollection(): void {
        if (gc_enabled()) {
            gc_collect_cycles();
            if (function_exists('gc_mem_caches')) {
                gc_mem_caches();
            }
        }
    }

    // Function to make API requests with session management
    private function callTinyTinyRssApi($operation, $params = [], $session_id = null) {
		if ($session_id) {
            $params['sid'] = $session_id;
        }

        $params['op'] = $operation;
        $_REQUEST = null;
        $_REQUEST = $params;

        ob_start();

		if ($operation && method_exists($this, $operation)) {
			$result = parent::$operation($_REQUEST);
		} else  { //if (method_exists($handler, 'index'))
			$result = $this->index($operation);
		}
		$this->capturedOutput = ob_get_clean();
		
		// If the result is true (indicating success), return the captured output
		if ($result === true) {
			return json_decode($this->capturedOutput, true);
		}

        // The result is already wrapped, so we can return it directly
		header("Api-Content-Length: " . ob_get_length());
		ob_end_flush();
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
				if (self::isSessionActive($session_id)) {
					return $session_id;
				}
			}
		}
		return '';
	}

	private function clientLogin(string $email, string $password) {
		$session_id = self::authorizationToUser();
		if ($session_id == '') {
			$loginResponse = self::callTinyTinyRssApi('login', [
				'user' => $email,
				'password' => $password
			]);
			if ($loginResponse && isset($loginResponse['status']) && $loginResponse['status'] == 0) {
				$session_id = $loginResponse['content']['session_id'];
			} else {
				self::unauthorized();
			}			
		}
		// Format the response as expected by Google Reader API clients
		$auth = $email . '/' . $session_id;
		$response = "SID={$auth}\n";
		$response .= "LSID=\n";
		$response .= "Auth={$auth}\n";
		header('Content-Type: text/plain; charset=UTF-8');
		echo $response;
		exit();
	}

	/** @return never */
	private function token(string $session_id) {
		//http://blog.martindoms.com/2009/08/15/using-the-google-reader-api-part-1/
		//https://github.com/ericmann/gReader-Library/blob/master/greader.class.php
		if ($session_id === null || !self::isSessionActive($session_id)) {
			self::unauthorized();
		}

		$salt = null;
		try {
			$pdo = Db::pdo();
			$sth = $pdo->prepare("SELECT salt FROM ttrss_users WHERE id = ?");
			$sth->execute([$_SESSION['uid']]);
			$salt = $sth->fetch()[0];
		} catch (PDOException $e) {
			error_log("Database error when pulling salt: " . $e->getMessage());
		}
		if (isset($salt)) {
			$token = substr(hash('sha256', $session_id . $salt),0,57);	//Must have 57 characters
			echo $token, "\n";
		}
		exit();
	}


	private function checkToken(string $token, string $session_id): bool {
		//http://code.google.com/p/google-reader-api/wiki/ActionToken
		if ($session_id === null || !self::isSessionActive($session_id)) {
			self::unauthorized();
		}
		$salt = null;
		try {
			$pdo = Db::pdo();
			$sth = $pdo->prepare("SELECT salt FROM ttrss_users WHERE id = ?");
			$sth->execute([$_SESSION['uid']]);
			$salt = $sth->fetch()[0];
		} catch (PDOException $e) {
			error_log("Database error when pulling salt: " . $e->getMessage());
		}
		if (isset($salt)) {
			if ($token === substr(hash('sha256', $session_id . $salt),0,57)) {
				return true;
			} else if (($token === '') && (substr($_SERVER['HTTP_USER_AGENT'], 0, 6) == 'FeedMe')) { //FeedMe apparently doesn't use tokens? Adding exception now, but may do away with token checking alltogether 
				return true;
			}
		}
		error_log('Invalid POST token: ' . $token);
		self::unauthorized();
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

		$tags = [
			['id' => 'user/-/state/com.google/starred'],
		];

		// Fetch categories
		$categoriesResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				if ($category['title'] != 'Special' && $category['title'] != 'Labels') { //Removing "Special" and "Labels"
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
		$_REQUEST['include_settings'] = 1;
		$opml = new OPML($_REQUEST);
		$opml_exp = $opml->export();

		echo $opml_exp[0];
		exit();
	}

	/** @return never */
	private function subscriptionImport(string $opml, string $session_id) {
		
		$ttrss_root = dirname(__DIR__, 3);
		$config_path = $ttrss_root . "/config.php";

		if (!file_exists($config_path)) {
			$ttrss_root = dirname(__DIR__, 2);
		}

		$tmp_file = $ttrss_root . '/' . Config::get(Config::CACHE_DIR) . '/upload/' . $_SESSION['name'] . '_opml_import.opml';
		file_put_contents($tmp_file, $opml);
		$upl_opml = new OPML($_REQUEST);
        ob_start();
		$opml_imp = $upl_opml->opml_import($_SESSION["uid"], $tmp_file);
		$capturedOutput = ob_get_clean();
		ob_end_flush();
		$capturedOutput = preg_replace('/(&nbsp;|<br\/>)+/', "\n", $capturedOutput);
		$capturedOutput = $capturedOutput . "Done!";
		unlink($tmp_file);
		echo $capturedOutput;
		exit();
	}

	private function getCategoryId(string $categoryName, string $session_id): int {
		// First, try to find an existing category
		$categoriesResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				if ($category['title'] == $categoryName) {
					return $category['id'];
				}
			}
		}
		return 0;
	}

	/** @return never */
	private function subscriptionList($session_id) {
		header('Content-Type: application/json; charset=UTF-8');

		$categoriesResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
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
				if ($feed['id'] > 0) { //Removing "Special" and "Label" cat lists
					$subscriptions[] = [
						'id' => 'feed/' . $feed['id'],
						'title' => $feed['title'],
						'categories' => [
							[
								'id' => 'user/-/label/' . $categoryMap[$feed['cat_id']],
								'label' => $categoryMap[$feed['cat_id']]
							]
						],
						'url' => isset($feed['feed_url']) ? $feed['feed_url'] : '',
						'htmlUrl' => isset($feed['feed_url']) ? $feed['feed_url'] : '', //site_url is not in the categories TTRSS API call
						'iconUrl' => TTRSS_SELF_URL_PATH . '/public.php?op=feed_icon&id=' . $feed['id'] . '.ico' //TTRSS_SELF_URL_PATH . '/feed-icons/' . $feed['id'] . '.ico'
					];
				}
			}
		}
		echo json_encode(['subscriptions' => $subscriptions], JSON_OPTIONS), "\n";
		exit();
	}

	/** @return never */
	private function renameFeed($feed_id, $title, $uid, $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
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

	private function addCategoryFeed(int $feedId, int $userId, string $session_id, int $category_id = -100, string $category_name = ''): bool {
		if (!self::isSessionActive($session_id)) {
			exit();
		}
		try {
			$pdo = Db::pdo();
			$category_name = clean($category_name);
			if ($category_id == -100 && $category_name != '') {
				// Category doesn't exist, create it
				$sth = $pdo->prepare("INSERT INTO ttrss_feed_categories (title, owner_uid) VALUES (?, ?)");
				$sth->execute([$category_name, $userId]);
				$category_id = $pdo->lastInsertId();
			}
	
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

		$category_id = 0;
		if ($add != '' && strpos($add, 'user/-/label/') === 0) {
			$categoryName = substr($add, 13);
			$categoryResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
			if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
				foreach ($categoryResponse['content'] as $category) {
					if ($category['title'] == $categoryName) {
						$category_id = $category['id'];
						break;
					}
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
					$feedResponse = self::callTinyTinyRssApi('getFeeds', [], $session_id);
					if ($feedResponse && isset($feedResponse['status']) && $feedResponse['status'] == 0) {
						foreach ($feedResponse['content'] as $feed) {
							if ($feed['feed_url'] == $streamUrl) {
								$feedId = $feed['id'];
								break;
							}
						}
					}
				}

				$title = $titles[$i] ?? '';

				switch ($action) {
					case 'subscribe':
						if ($feedId == 0) {
							$subscribeResponse = self::quickadd($url, $session_id, $category_id);
							if (!$subscribeResponse) {
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
							if ($add != '' && strpos($add, 'user/-/label/') === 0) {
								$categoryName = substr($add, 13);
								if ($category_id == 0) {
									$category_id = -100;
								}
								if (!self::addCategoryFeed($feedId, $uid, $session_id, $category_id, $categoryName)) {
									self::badRequest();
								}
							}
							if ($remove != '' && strpos($remove, 'user/-/label/') === 0) {
								if (!self::removeCategoryFeed($feedId, $uid, $session_id)) {
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

	/** @return never */
	private function quickadd(string $url, string $session_id, int $category_id = 0) {
		try {
			$url = htmlspecialchars($url, ENT_COMPAT, 'UTF-8');
			if (str_starts_with($url, 'feed/')) {
				$url = substr($url, 5);
			}

			// Call Tiny Tiny RSS API to add the feed
			$response = self::callTinyTinyRssApi('subscribeToFeed', [
				'feed_url' => $url,
				'category_id' => $category_id,
			], $session_id);
			
			if ($response && isset($response['status']) && $response['status'] == 0) {
				// Feed added successfully
				$feedId = $response['content']['status']['feed_id'];

				// Fetch the feed details
				$feedResponse = self::callTinyTinyRssApi('getFeeds', [
					'cat_id' => $category_id,
				], $session_id);

				if ($feedResponse && isset($feedResponse['status']) && $feedResponse['status'] == 0) {
					$streamName = '';
					foreach ($feedResponse['content'] as $feed) {
						if ($feed['id'] == $feedId) {
							$streamName = $feed['title'];
							break;
						}
					}
					$feed = $feedResponse['content'][0];
					exit(json_encode([
						'numResults' => 1,
						'query' => $url,
						'streamId' => 'feed/' . $feedId,
						'streamName' => $streamName,
					], JSON_OPTIONS));
				}
			}

			// If we get here, something went wrong
			throw new Exception('Failed to add feed');

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
	    
		// Fetch categories
		$categoriesResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
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
				$categoryTitle = $categories[$counter['id']] ?? $counter['title'];
				$id = 'user/-/label/' . htmlspecialchars_decode($categoryTitle, ENT_QUOTES);
			} else if (isset($counter['title'])) {
				$id = 'feed/' . $counter['id'];
			} else if ($counter['id'] && array_key_exists('description', $counter)) {
				$labelTitle = $labels[$counter['id']] ?? $counter['description']; 
				$id = 'user/-/label/' . htmlspecialchars_decode($labelTitle, ENT_QUOTES);
			} else if ($counter['id'] == 'global-unread') {
				$labelTitle = $labels[$counter['id']] ?? array_key_exists('description', $counter) ? $counter['description'] : null; 
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
	/**
	 * @param 'A'|'c'|'f'|'s' $type
	 * @param string|int $streamId
	 * @phpstan-return array{'A'|'c'|'f'|'s'|'t',int,int}
	 */
	private function streamContentsFilters(string $type, $streamId,
		string $filter_target, string $exclude_target, int $start_time, int $stop_time, string $session_id): array {
		
		$feed_id = -4; // Default to all feeds
		$is_cat = false;
		$view_mode = 'all_articles';
		$search = '';

		switch ($type) {
			case 'f':    //feed
				if ($streamId != '' && is_string($streamId) && !is_numeric($streamId)) {
					$feedResponse = self::callTinyTinyRssApi('getFeeds', [], $session_id);
					if ($feedResponse && isset($feedResponse['status']) && $feedResponse['status'] == 0) {
						foreach ($feedResponse['content'] as $feed) {
							if ($feed['feed_url'] == $streamId) {
								$feed_id = $feed['id'];
								break;
							}
						}
					}
				} else {
					$feed_id = (int)$streamId;
				}
				break;
			case 'c':    //category or label
				$categoryResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
				if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
					foreach ($categoryResponse['content'] as $category) {
						if ($category['title'] == $streamId) {
							$feed_id = $category['id'];
							$is_cat = true;
							break;
						}
					}
				}
				if (!$is_cat) {
					// If not found as category, treat as label
					$type = 't';
					$search = 'ttrss:label:' . $streamId;
				}
				break;
		}

		switch ($filter_target) {
			case 'user/-/state/com.google/read':
				$view_mode = 'all_articles';
				break;
			case 'user/-/state/com.google/unread':
				$view_mode = 'unread';
				break;
			case 'user/-/state/com.google/starred':
				$view_mode = 'marked';
				break;
		}

		if ($exclude_target === 'user/-/state/com.google/read') {
			$view_mode = 'unread';
		}

		$search_params = [];
		if ($start_time > 0) {
			$search_params[] = 'after:' . date('Y-m-d', $start_time);
		}
		if ($stop_time > 0) {
			$search_params[] = 'before:' . date('Y-m-d', $stop_time);
		}
		if (!empty($search_params)) {
			$search .= ' ' . implode(' ', $search_params);
		}

		return [$type, $feed_id, $is_cat, $view_mode, trim($search)];
	}

	private function streamContentsItemsIds($streamId, $start_time, $stop_time, $count, $order, $filter_target, $exclude_target, $continuation, $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		$params = [
			'limit' => $count, // Max articles to send to client
			'skip' => $continuation ? intval($continuation) : 0, //May look at replacing this with since_id
			//'since_id' => $start_time,
			'include_attachments' => false,
			'view_mode' => ($exclude_target == 'user/-/state/com.google/read') ? 'unread' : 'all_articles',
			'feed_id' => -4, //setting to all articles by default
			'order_by' => ($order == 'o') ? 'date_reverse' : 'feed_dates',
		];
		
		$readonly = false;
		if ($exclude_target == 'user/-/state/com.google/unread') {
			$readonly = true;
		}
		// Set feed_id based on streamId
		if (strpos($streamId, 'feed/') === 0) {
			$params['feed_id'] = substr($streamId, 5);
		} elseif (strpos($streamId, 'user/-/label/') === 0) {
			$cat_or_label = self::getCategoryLabelID(substr($streamId, 13), $session_id);
			if ($cat_or_label > -10) { // below -10 are Labels, above are categories
				$params['is_cat'] = true;
			}
			$params['feed_id'] = $cat_or_label; // Remove 'user/-/label/' prefix
		} elseif ($streamId == 'user/-/state/com.google/read') {
			$readonly = true;
		} elseif ($streamId == 'user/-/state/com.google/reading-list') {
			$params['feed_id'] = -4;
		} elseif ($streamId == 'user/-/state/com.google/starred') {
			$params['feed_id'] = -1;
			$params['view_mode'] = 'marked';
		}
		//todo - add read?
		$itemRefs = [];
		$totalFetched = 0;
		$moreAvailable = false;
		$min_date = isset($start_time) ? intval($start_time) : 0;
		while (($totalFetched < $count) && ($totalFetched <= 15000)) { //setting max cap just in case
			$response = self::callTinyTinyRssApi('getHeadlines', $params, $session_id);

			if (!($response && isset($response['status']) && $response['status'] == 0)) {
				self::internalServerError();
			}
	
			$items = $response['content'];
			$itemCount = count($items);

			foreach ($items as $article) {
				if ($readonly && ($article['unread'] == 1)) {
					continue;
				}
				if ($totalFetched < $count) {
					if (intval($article['updated']) > $min_date) {
						$itemRefs[] = [
							'id' => '' . $article['id'],
							//'directStreamIds' => ['feed/' . $article['feed_id']], //Apparently these aren't needed? Will exclude while testing
							//'timestampUsec' => $article['updated'] . '000000',
						];
						$totalFetched++;
					}
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
			'itemRefs' => $itemRefs,
		];
	
		if ($moreAvailable || ($totalFetched == $count)) {
			// There are more items available
			$result['continuation'] = '' . ($continuation ? intval($continuation) : 0) + $totalFetched;
		}
		//error_log(print_r(sizeof($itemRefs), true));
		unset($itemRefs);
		
		self::triggerGarbageCollection();
		echo json_encode($result, JSON_OPTIONS), "\n";
		exit();
	}

	private function getCategoryLabelID($cat_id, $session_id) {
		// First, check if it's a category
		$categoryResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
		$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
		if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
			foreach ($categoryResponse['content'] as $category) {
				if ($category['title'] == $cat_id) {
					return intval($category['id']);
				}
			}
		} 
		// Not a Category, must be a label. Note that if a label and category have the same name, we'll always return the category
		if ($labelsResponse && isset($labelsResponse['status']) && $labelsResponse['status'] == 0) {
			foreach ($labelsResponse['content'] as $label) {
				if ($label['caption'] == $cat_id) {
					return (intval($label['id']));
				}
			}
		}
	}

	private function streamContentsItems(array $e_ids, string $order, string $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
		
		// Fetch categories
		$categoriesResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
		$categoryMap = [];
		if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
			foreach ($categoriesResponse['content'] as $category) {
				$categoryMap[$category['id']] = $category['title'];
			}
		}

		// Fetch feeds to get the category mapping
		$feedsResponse = self::callTinyTinyRssApi('getFeeds', ['cat_id' => -4], $session_id);
		$feedCategoryMap = [];
		if ($feedsResponse && isset($feedsResponse['status']) && $feedsResponse['status'] == 0) {
			foreach ($feedsResponse['content'] as $feed) {
				$feedCategoryMap[$feed['id']] = [
					'category_id' => $feed['cat_id'],
					'category_name' => $categoryMap[$feed['cat_id']] ?? 'Uncategorized',
				];
			}
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

	private function streamContents(string $path, string $include_target, int $start_time, int $stop_time, int $count,
    string $order, string $filter_target, string $exclude_target, string $continuation, string $session_id) {
		header('Content-Type: application/json; charset=UTF-8');
			
		list($type, $feed_id, $is_cat, $view_mode, $search) = self::streamContentsFilters($path, $include_target, $filter_target, $exclude_target, $start_time, $stop_time, $session_id);

		$params = [
			'feed_id' => $feed_id,
			'is_cat' => $is_cat,
			'limit' => $count,
			'skip' => $continuation ? intval($continuation) : 0,
			'view_mode' => $view_mode,
			'order' => ($order === 'o') ? 'date_reverse' : 'feed_dates',
			'search' => $search,
		];

		// Determine the feed_id or category based on the path and include_target
		switch ($path) {
			case 'feed':
				$params['feed_id'] = substr($include_target, 5); // Remove 'feed/' prefix
				break;
			case 'label':
				$params['cat_id'] = $include_target;
				break;
			case 'reading-list':
				$params['feed_id'] = -4; // All articles in TTRSS
				break;
			case 'starred':
				$params['feed_id'] = -1; // Starred articles in TTRSS
				$params['view_mode'] = 'marked';
				break;
			default:
				$params['feed_id'] = -4; // Default to all articles
		}

		// Apply filters
		if ($filter_target === 'user/-/state/com.google/read') {
			$params['view_mode'] = 'all_articles';
		} elseif ($filter_target === 'user/-/state/com.google/reading-list') {
			$params['view_mode'] = 'unread';
		}

		if ($exclude_target === 'user/-/state/com.google/read') {
			$params['view_mode'] = 'unread';
		}

		$response = self::callTinyTinyRssApi('getHeadlines', $params, $session_id);

		if ($response && isset($response['status']) && $response['status'] == 0) {
			$items = [];
			foreach ($response['content'] as $article) {
				$items[] = self::convertTtrssArticleToGreaderFormat($article);
				// Trigger garbage collection every 100 items
				if (count($items) % 100 == 0) {
					self::triggerGarbageCollection();
				}
			}

			$result = [
				'id' => $path === 'feed' ? $include_target : 'user/-/state/com.google/reading-list',
				'updated' => time(),
				'items' => $items,
			];

			if (count($items) >= $count) {
				$result['continuation'] = $params['skip'] + $count;
			}
			unset($items);
            self::triggerGarbageCollection();
			echo json_encode($result, JSON_OPTIONS);
			exit();
		}

		self::internalServerError();
	}

	private function convertTtrssArticleToGreaderFormat($article) {
		$formatted_article = [
			'id' => 'tag:google.com,2005:reader/item/' . dec2hex(strval($article['id'])),
			'crawlTimeMsec' => $article['updated'] . '000', //time() . '000',//strval(dateAdded(true, true)),
			'timestampUsec' => $article['updated'] . '000000', //'' . time() . '000000',//strval(dateAdded(true, true)) . '000', //EasyRSS & Reeder
			'published' => $article['updated'],
			'title' => (array_key_exists('title', $article) && !empty($article['title'])) ? escapeToUnicodeAlternative($article['title'], false) : '',
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
				'title' => (array_key_exists('feed_title', $article) && !empty($article['feed_title'])) ? escapeToUnicodeAlternative($article['feed_title'], false) : '',
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
		exit('OK');
	}

	/** @return never */
	private function renameTag(string $s, string $dest, string $session_id) {
		if ($s != '' && strpos($s, 'user/-/label/') === 0 &&
			$dest != '' && strpos($dest, 'user/-/label/') === 0) {
			$oldName = substr($s, 13);
			$newName = substr($dest, 13);
			$oldName = htmlspecialchars($oldName, ENT_COMPAT, 'UTF-8');
			$newName = htmlspecialchars($newName, ENT_COMPAT, 'UTF-8');
			// First, check if it's a category
			$categoryResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
			$labelsResponse = self::callTinyTinyRssApi('getLabels', [], $session_id);
			if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
				foreach ($categoryResponse['content'] as $category) {
					if ($category['title'] == $oldName) {
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
					if ($label['caption'] == $oldName) {
						// It's a label, so we can rename it
						try {
							$pdo = Db::pdo();
							$sth = $pdo->prepare("UPDATE ttrss_labels2 SET caption = ? WHERE caption = ? AND owner_uid = ?");
							$sth->execute([$newName, $oldName, $_SESSION['uid']]);
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
			$tagName = htmlspecialchars($tagName, ENT_COMPAT, 'UTF-8');

			// First, check if it's a category
			$categoryResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
			if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
				foreach ($categoryResponse['content'] as $category) {
					if ($category['title'] == $tagName) {
						// It's a category, so we need to move all feeds to uncategorized and then delete the category
						$feedsResponse = self::callTinyTinyRssApi('getFeeds', ['cat_id' => $category['id']], $session_id);
						if ($feedsResponse && isset($feedsResponse['status']) && $feedsResponse['status'] == 0) {
							foreach ($feedsResponse['content'] as $feed) {
								if (!self::removeCategoryFeed($feed['id'], $_SESSION['uid'], $session_id)) {
									self::badRequest();
								}
							}
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
					if ($label['caption'] == $tagName) {
						try {
							$pdo = Db::pdo();
							$sth = $pdo->prepare("SELECT id FROM ttrss_labels2 WHERE caption = ? and owner_uid = ?");
							$sth->execute([$tagName, $_SESSION['uid']]);
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
	 * @param numeric-string $olderThanId
	 * @return never
	 */
	private function markAllAsRead(string $streamId, string $olderThanId, string $session_id) {
		$params = [
			'is_cat' => false,
			'article_ids' => '',
		];

		if (strpos($streamId, 'feed/') === 0) {
			$params['feed_id'] = substr($streamId, 5);
		} elseif (strpos($streamId, 'user/-/label/') === 0) {
			$categoryName = substr($streamId, 13);
			$categoryResponse = self::callTinyTinyRssApi('getCategories', ['include_empty' => true], $session_id);
			if ($categoryResponse && isset($categoryResponse['status']) && $categoryResponse['status'] == 0) {
				foreach ($categoryResponse['content'] as $category) {
					if ($category['title'] == $categoryName) {
						$params['feed_id'] = $category['id'];
						$params['is_cat'] = true;
						break;
					}
				}
			}
			if (!$params['is_cat']) {
				// If not found as category, treat as label
				$params['feed_id'] = -4; // All feeds
				$params['is_cat'] = false;
				$params['filter'] = ['type' => 'label', 'label' => $categoryName];
			}
		} elseif ($streamId === 'user/-/state/com.google/reading-list') {
			$params['feed_id'] = -4; // All feeds
		} else {
			self::badRequest();
		}

		if ($olderThanId !== '0') {
			// Convert olderThanId to a timestamp
			$olderThanTimestamp = intval($olderThanId / 1000000); // Convert microseconds to seconds
			$params['article_ids'] = 'FEED:' . $params['feed_id'] . ':' . $olderThanTimestamp;
		}

		$response = self::callTinyTinyRssApi('catchupFeed', $params, $session_id);

		if ($response && isset($response['status']) && $response['status'] == 0) {
			exit('OK');
		} else {
			self::internalServerError();
		}
	}

	/** @return never */
	public function parse() {
        $ORIG_REQUEST = $_REQUEST;
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
		/*
		error_log(print_r('1-PATH_INFO=',true));
        error_log(print_r($pathInfo,true));
		error_log(print_r('2-REQUEST=',true));
        error_log(print_r($_REQUEST,true));
		//error_log(print_r('3-ORIGINAL_INPUT',true));
		//error_log(print_r($ORIGINAL_INPUT,true));
		error_log(print_r(headerVariable('Authorization', 'GoogleLogin_auth'),true));
		error_log(print_r($_SESSION,true));

		//error_log(print_r(substr($_SERVER['HTTP_USER_AGENT'], 0, 11),true));
		error_log(print_r('GET=',true));
        error_log(print_r($_GET,true));
		error_log(print_r('POST=',true));
		error_log(print_r($_POST,true));
		error_log(print_r('SESSION=',true));
		error_log(print_r($_SESSION,true));
		*/
		//error_log(print_r('SERVER=',true));
		//error_log(print_r($_SERVER,true));
		
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
							case 'import':
								if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && $ORIGINAL_INPUT != '') {
									self::subscriptionImport($ORIGINAL_INPUT, $session_id);
								}
								break;
							case 'list':
								$output = $input['output'] ?? '';
								if ($output !== 'json') self::notImplemented();
								self::subscriptionList($session_id);
								// Always exits
							case 'edit':
								if (isset($ORIG_REQUEST['s'], $ORIG_REQUEST['ac'])) {
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
					//$token = isset($input['T']) ? trim($input['T']) : '';
					//self::checkToken($token, $session_id);
					$a = $input['a'] ?? '';	//Add:	user/-/state/com.google/read	user/-/state/com.google/starred
					$r = $input['r'] ?? '';	//Remove:	user/-/state/com.google/read	user/-/state/com.google/starred
					$e_ids = multiplePosts('i');	//item IDs
					self::editTag($e_ids, $a, $r, $session_id);
					// Always exits
					break; //just in case 
				case 'rename-tag':    //https://github.com/theoldreader/api
					if (isset($input['T'])) {
						$token = isset($input['T']) ? trim($input['T']) : '';
					} else {
						self::token($session_id);
					}
					//$token = isset($input['T']) ? trim($input['T']) : '';
					//self::checkToken($token, $session_id);
					$s = $input['s'] ?? '';    //user/-/label/Folder
					$dest = $input['dest'] ?? '';    //user/-/label/NewFolder
					self::renameTag($s, $dest, $session_id);
					// Always exits
					break; //just in case 
				case 'disable-tag':    //https://github.com/theoldreader/api
					//$token = isset($input['T']) ? trim($input['T']) : '';
					//self::checkToken($token, $session_id);
					$s_s = multiplePosts('s');
					foreach ($s_s as $s) {
						self::disableTag($s, $session_id);    //user/-/label/Folder
					}
					// Always exits
					break; //just in case 
				case 'mark-all-as-read':
					//$token = isset($input['T']) ? trim($input['T']) : '';
					//self::checkToken($token, $session_id);
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