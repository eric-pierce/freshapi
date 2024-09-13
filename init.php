<?php

/**
== Description ==
Server-side API for the Tiny Tiny RSS project https://tt-rss.org compatible with FreshRSS and Google Reader API Specs
This plugin will use a rolling release approach, with notable changes mentioned in the Versioning section below

== Credits ==
* Project by Eric Pierce https://eric-pierce.com
* Modeled after Google Reader API implementation for FreshRSS by Alexandre Alapetite https://alexandre.alapetite.fr
    https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php
	Released under GNU AGPL 3 license http://www.gnu.org/licenses/agpl-3.0.html

== Versioning ==
    * 2024-09-13: Initial Release by Eric Pierce
*/

class FreshAPI extends Plugin {
	
	private $host;

	function about() {
		return array(1.0,
			"A FreshRSS / Google Reader API Plugin for Tiny-Tiny RSS",
			"Eric Pierce",
			false,
			"https://github.com/eric-pierce/FreshAPI");
	}

    function api_version() {
		return 2;
	}

	function init($host) {
		$this->host = $host;
	}
}

?>