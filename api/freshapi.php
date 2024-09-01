<?php

#class FreshAPI_Class extends Handler {

    // Function to perform login and get session ID
    function ttrssLogin($username, $password) {
        $response = callTinyTinyRssApi('login', [
            'user' => $username,
            'password' => $password
        ]);
    
        if ($response && isset($response['status']) && $response['status'] == 0) {
            return $response['content']['session_id'];
        } else {
            http_response_code(401);
            die('Login failed');
        }
    }

    // Function to make API requests with session management
    function callTinyTinyRssApi($operation, $params = [], $session_id = null) {
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
    function isSessionValid($session_id) {
        $response = callTinyTinyRssApi('isLoggedIn', [], $session_id);

        return $response && isset($response['status']) && $response['status'] == 0 && $response['content']['status'] === true;
    }
    
    // Function to handle re-authentication if the session is invalid
    function handleInvalidSession($session_id, $operation, $params = []) {
        //if (!isSessionValid($session_id)) {
        //    $new_session_id = ttrssLogin($username, $password);
        //    return callTinyTinyRssApi($operation, $params, $new_session_id);
        //}
        return callTinyTinyRssApi($operation, $params, $session_id);
    }
    
    // Function to map userInfo API with session handling
    function mapUserInfo($session_id) {
        $response = isSessionValid($session_id);
        error_log(print_r($response, true));
        if ($response) {
            return json_encode([
                'userId' => $_SESSION['name'],
                'userName' => $_SESSION['name'],
                'userProfileId' => $_SESSION['name'],
                'userEmail' => ""
            ]);
        } else {
            http_response_code(500);
            die('Failed to retrieve user info');
        }
    }

    // Function to get unread article count
    function mapUnreadCount($session_id) {
        $response = callTinyTinyRssApi('isLoggedIn', [], $session_id);

        return $response && isset($response['status']) && $response['status'] == 0 && $response['content']['status'] === true;
    }

    // Function to map tagList API with session handling
    function mapTagList($session_id) {
        $categories = handleInvalidSession($session_id, 'getCategories');
        $tags = handleInvalidSession($session_id, 'getTags');
        
        $mappedTags[] = [
            'id' => 'user/-/state/com.google/starred',
            'label' => 'Starred',
            'type' => 'special'
        ];
        if ($categories && isset($categories['status']) && $categories['status'] == 0) {
            foreach ($categories['content'] as $cat) {
                $mappedTags[] = [
                    'id' => 'user/-/label/' . $cat['title'],
                    'type' => 'folder'
                ];
            }
        }
    
        if ($tags && isset($tags['status']) && $tags['status'] == 0) {
            foreach ($tags['content'] as $tag) {
                $mappedTags[] = [
                    'id' => 'user/-/label/' . $tag['name'],
                    'type' => 'tag',
                    'unread_count' => $tag['unread']
                ];
            }
        }
    
        return json_encode(['tags' => $mappedTags]);
    }
    
    // Function to map subscriptionExport API with session handling
    function mapSubscriptionExport($session_id) {
        $feeds = handleInvalidSession($session_id, 'getFeeds');
        if ($feeds && isset($feeds['status']) && $feeds['status'] == 0) {
            $opmlContent = convertFeedsToOpml($feeds['content']);
            return [
                'filename' => 'subscriptions.opml',
                'content' => $opmlContent
            ];
        } else {
            http_response_code(500);
            die('Failed to export subscriptions');
        }
    }
    
    // Function to map subscriptionImport API with session handling
    function mapSubscriptionImport($opml, $session_id) {
        $feeds = parseOpml($opml);
        foreach ($feeds as $feedUrl) {
            $response = handleInvalidSession($session_id, 'subscribeToFeed', ['feed_url' => $feedUrl]);
            if (!$response || isset($response['status']) && $response['status'] != 0) {
                http_response_code(500);
                die('Failed to import subscription');
            }
        }
        return 'OK';
    }

    // Function to map subscriptionList API with session handling
    function mapSubscriptionList($session_id) {
        // Fetch the categories first to map category IDs to titles
        $categoriesResponse = handleInvalidSession($session_id, 'getCategories');
    
        $categoryMap = [];
    
        // Build a map of category IDs to titles
        if ($categoriesResponse && isset($categoriesResponse['status']) && $categoriesResponse['status'] == 0) {
            foreach ($categoriesResponse['content'] as $category) {
                $categoryMap[$category['id']] = $category['title'];
            }
        }
    
        $subscriptions = [];
    
        // Fetch feeds for each category
        foreach ($categoryMap as $cat_id => $cat_title) {
            if ($cat_id > -1) {
                    $feedsResponse = handleInvalidSession($session_id, 'getFeeds', [
                        'cat_id' => $cat_id
                    ]);
        
                if ($feedsResponse && isset($feedsResponse['status']) && $feedsResponse['status'] == 0) {
                    foreach ($feedsResponse['content'] as $feed) {
                        $subscriptions[] = [
                            'id' => 'feed/' . $feed['id'],
                            'title' => $feed['title'],
                            'url' => $feed['feed_url'],
                            'iconUrl' => '', // Optional: Tiny Tiny RSS does not provide this directly
                            'htmlUrl' => $feed['feed_url'],
                            'categories' => [['id' => 'user/-/label/' . $cat_id, 'label' => $cat_title]],
                        ];
                    }
                } else {
                    http_response_code(500);
                    die('Failed to retrieve subscriptions');
                }
            }
        }
        return json_encode(['subscriptions' => $subscriptions]);
    }
    

    // Function to handle stream/items/ids requests
    function mapStreamItemsIds($session_id, $params) {
        // Extract parameters
        $exclude_tag = $params['xt'] ?? ''; // Exclude items with specific tags
        $stream_id = $params['s'] ?? 'user/-/state/com.google/reading-list'; // Stream ID
        $limit = $params['n'] ?? 100; // Number of items to return

        // Determine if we need to show only unread items
        $showUnreadOnly = (strpos($exclude_tag, 'user/-/state/com.google/read') !== false);

        // Fetch the headlines using Tiny Tiny RSS API
        $response = handleInvalidSession($session_id, 'getHeadlines', [
            'feed_id' => '-4', // Use '-4' to indicate all articles
            'limit' => $limit,
            'is_cat' => false,
            'view_mode' => $showUnreadOnly ? 'unread' : 'all_articles'
        ]);

        if ($response && isset($response['status']) && $response['status'] == 0) {
            $item_ids = [];

            // Extract IDs from the list of articles
            foreach ($response['content'] as $article) {
                // Construct the ID format expected by the FreshRSS client
                $item_ids[] = 'tag:google.com,2005:reader/item/' . $article['id'];
                //$item_ids[] = $article['id'];
            }

            // Return the formatted JSON response with item IDs
            return json_encode(['itemRefs' => array_map(fn($id) => ['id' => $id], $item_ids)]);
        } else {
            http_response_code(500);
            die('Failed to retrieve item IDs');
        }
    }

    // Function to handle stream/items/contents requests
    function mapStreamItemsContents($session_id, $params) {
        // Extract parameters
        $item_ids = $params['i'] ?? ''; // List of item IDs to retrieve
        $item_ids_array = explode(',', $item_ids); // Convert to array

        // Initialize array to hold articles
        $articles = [];

        // Fetch each article using Tiny Tiny RSS API
        foreach ($item_ids_array as $item_id) {
            $response = handleInvalidSession($session_id, 'getArticle', [
                'article_id' => $item_id
            ]);

            if ($response && isset($response['status']) && $response['status'] == 0) {
                // Extract article details
                foreach ($response['content'] as $article) {
                    $articles[] = [
                        'id' => 'tag:google.com,2005:reader/item/' . $article['id'],
                        'title' => $article['title'],
                        'published' => date('c', $article['updated']),
                        'updated' => date('c', $article['updated']),
                        'canonical' => [['href' => $article['link']]],
                        'summary' => ['content' => $article['content']],
                        'author' => $article['author'],
                        'categories' => $article['labels'] ?? [],
                    ];
                }
            } else {
                http_response_code(500);
                die('Failed to retrieve article content');
            }
        }

        // Return the formatted JSON response with article contents
        return json_encode(['items' => $articles]);
    }

    function mapToken($session_id) {   
        // Generate a secure token using a hash function
        // Use a combination of the session ID, a server-side secret, and a timestamp to make it unique
        //$secret_key = 'your_secret_key'; // Replace with a securely stored secret key
        //$token = sha1($session_id . $secret_key . time());
        error_log(print_r('session_id=' . $session_id, true));
        $token = str_pad($session_id, 57, 'Z');
        // Return the token
        return $token;
    }

    // Utility function to convert feeds to OPML
    function convertFeedsToOpml($feeds) {
        $opml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<opml version=\"1.0\">\n\t<head>\n\t\t<title>Subscriptions</title>\n\t</head>\n\t<body>\n";
        foreach ($feeds as $feed) {
            $opml .= "\t\t<outline text=\"{$feed['title']}\" type=\"rss\" xmlUrl=\"{$feed['feed_url']}\"/>\n";
        }
        $opml .= "\t</body>\n</opml>";
        return $opml;
    }
    
    // Utility function to parse OPML to feed URLs
    function parseOpml($opml) {
        $feeds = [];
        $xml = simplexml_load_string($opml);
        if ($xml && isset($xml->body)) {
            foreach ($xml->body->outline as $outline) {
                $feedUrl = (string) $outline['xmlUrl'];
                if ($feedUrl) {
                    $feeds[] = $feedUrl;
                }
            }
        }
        return $feeds;
    }
#}
?>