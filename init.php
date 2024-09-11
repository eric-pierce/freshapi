<?php

/**
== Description ==
Server-side API compatible with FreshRSS API for the Tiny Tiny RSS project https://tt-rss.org

== Credits ==
* Adapted and implemented by Eric Pierce https://eric-pierce.com
* Structured after the Google Reader API implementation for FreshRSS by Alexandre Alapetite https://alexandre.alapetite.fr
    https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php
	Released under GNU AGPL 3 license http://www.gnu.org/licenses/agpl-3.0.html

== Versioning ==
    * 2024-09-10: Initial Release by Eric Pierce
*/

class FreshAPI extends Plugin {
	
	private $host;

	function about() {
		return array(1.0,
			"FreshRSS API Bridge Plugin",
			"Eric Pierce",
			false,
			"https://github.com/eric-pierce/freshapi");
	}

    function api_version() {
		return 2;
	}

	function init($host) {
		$this->host = $host;
	}
}

?>