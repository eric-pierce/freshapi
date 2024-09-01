<?php

class FreshAPI extends Plugin {

	private $host;

	function about() {
		return array(0.1,
			"FreshRSS API Bridge Plugin",
			"Eric Pierce",
			false,
			"https://www.eric-pierce.com");
	}

	function init($host) {
		$this->host = $host;
	}

    function api_version() {
		return 2;
	}
}

?>