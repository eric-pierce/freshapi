<?php

/**
== Description ==
Server-side API for the Tiny Tiny RSS project https://github.com/tt-rss/tt-rss compatible with FreshRSS and Google Reader API Specs
This plugin will use a rolling release approach, with notable changes mentioned in the section below

== Credits ==
* Project by Eric Pierce https://eric-pierce.com
* Modeled after Google Reader API implementation for FreshRSS by Alexandre Alapetite https://alexandre.alapetite.fr
    https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php
	Released under GNU AGPL 3 license http://www.gnu.org/licenses/agpl-3.0.html

== Notable Changes ==
    * 2024-09-13: Initial Release by Eric Pierce
    * 2024-09-18: Resolved several issus related to labels and syncing for NetNewsWire
    * 2024-09-19: Updated authentication logic to support more clients like Fiery Feeds
    * 2024-09-23: Significant speed improvements when syncing
*/

class FreshAPI extends Plugin {
	
	private $host;

	function about() {
		return array(1.2,
			"A FreshRSS / Google Reader API Plugin for Tiny-Tiny RSS",
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
