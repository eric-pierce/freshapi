<?php

class FreshAPI extends Plugin {

	private $host;
	private $dbh;

	function about() {
		return array(1.0,
			"FreshRSS API Bridge Plugin",
			"Eric Pierce",
			true,
			"https://github.com/eric-pierce/freshapi");
	}

	function init($host) {
		$this->host = $host;
		$this->host->add_api_method("addLabel", $this);
		$this->host->add_api_method("removeLabel", $this);
		$this->host->add_api_method("renameLabel", $this);
		$this->host->add_api_method("addCategory", $this);
		$this->host->add_api_method("removeCategory", $this);
		$this->host->add_api_method("renameCategory", $this);
		$this->host->add_api_method("moveCategory", $this);
		$this->host->add_api_method("renameFeed", $this);
		$this->host->add_api_method("moveFeed", $this);	}

	function removeLabel()
	{
		$label_id = (int)$_REQUEST["label_id"];
		if($label_id != "")
		{
			Labels::remove(Labels::feed_to_label_id($label_id), $_SESSION["uid"]);
			return array(API::STATUS_OK);
		}
		else
		{
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		}
	}

	function addLabel()
	{
		$caption = $_REQUEST["caption"];
		if($caption != "")
		{
			Labels::create($caption);
			$id = Labels::find_id($caption, $_SESSION["uid"]);
			return array(API::STATUS_OK, Labels::label_to_feed_id($id));
		}
		else
		{
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		}
	}

	function renameLabel()
	{
		$caption = $_REQUEST["caption"];
		$label_id = Labels::feed_to_label_id((int)$_REQUEST["label_id"]);

		if($label_id != "" && $caption != "")
		{
			$sth = $this->dbh->prepare("UPDATE ttrss_labels2 SET caption = ? WHERE id = ? AND owner_uid = ?");
			$sth->execute([$caption, $label_id, $_SESSION["uid"]]);
			return array(API::STATUS_OK);
		}
		else
		{
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		}
	}

	function removeCategory()
	{
		$category_id = (int)$_REQUEST["category_id"];
		if($category_id != "")
		{
			$sth = $this->dbh->prepare("DELETE FROM ttrss_feed_categories WHERE id = ? AND owner_uid = ?");
			$sth->execute([$category_id, $_SESSION["uid"]]);
			ccache_remove($category_id, $_SESSION["uid"], true);
			return array(API::STATUS_OK);
		}
		else
		{
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		}
	}

	function moveCategory()
	{
		$category_id = (int)db_escape_string($_REQUEST["category_id"]);
		$parent_id = (int)db_escape_string($_REQUEST["parent_id"]);

		if($category_id != "")
		{
			if($parent_id == "")
			{
				$parent_id = null;
			}

			$sth = $this->dbh->prepare("UPDATE ttrss_feed_categories SET parent_cat = ? WHERE id = ? AND owner_uid = ?");
			$sth->execute([$parent_id, $category_id, $_SESSION["uid"]]);
			return array(API::STATUS_OK);
		}
		else
		{
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		}
	}

	function addCategory()
	{
		$caption = $_REQUEST["caption"];
		$parent_id = (int)$_REQUEST["parent_id"];
		if($caption != "")
		{
			$query = "SELECT id FROM ttrss_feed_categories WHERE title = ? AND owner_uid = ?";
			$params = [$caption, $_SESSION["uid"]];
			if($parent_id != "")
			{
				add_feed_category($caption, $parent_id);
				$query = $query . " AND parent_cat = ?";
				array_push($params, $parent_id);
			}
			else
			{
				add_feed_category($caption);
				$query = $query . "AND parent_cat IS NULL";
			}
			$sth = $this->dbh->prepare($query);
			$sth->execute($params);
			$id = $sth->fetchColumn();
			return array(API::STATUS_OK, $id);
		}
		else
		{
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		}
	}

	function renameCategory() {
		$cat_id = (int)$_REQUEST["category_id"];
		$caption = $_REQUEST["caption"];

		if($caption != "")
		{
			$sth = $this->dbh->prepare("UPDATE ttrss_feed_categories SET title = ? WHERE id = ? AND owner_uid = ?");
			$sth->execute([$caption, $cat_id, $_SESSION["uid"]]);
			return array("status" => 0, "message" => "OK");
		}
		else
		{
			return array("status" => 1, "message" => "Feed not found");
		}
	}

	function renameFeed() {
		$feed_id = (int) clean($_REQUEST["feed_id"]);
		$feed = ORM::for_table('ttrss_feeds')->find_one($feed_id);
		$session_id = clean($_REQUEST["session_id"]);

		if ($feed) {
			if (isset($_REQUEST["category_id"])) {
				$feed->cat_id = (int) clean($_REQUEST["category_id"]);
			}
			if (isset($_REQUEST["feed_title"])) {
				$feed->title = clean($_REQUEST["feed_title"]);
			}
			$feed->save();
			$sth = $this->dbh->prepare("UPDATE ttrss_feeds SET title = ? WHERE id = ? AND owner_uid = ?");
			$sth->execute([$title, $feed_id, $session_id]);

			return array(API::STATUS_OK, array("status" => 0, "message" => "OK"));
		} else {
			return array(API::STATUS_ERR, array("status" => 1, "message" => "Feed not found"));
		}
	}

	function moveFeed() {
		$feed_id = (int)$_REQUEST["feed_id"];
		$cat_id = (int)$_REQUEST["category_id"];

		if($feed_id != "" && $cat_id != "")
		{
			$sth = $this->dbh->prepare("UPDATE ttrss_feeds SET cat_id = ? WHERE id = ? AND owner_uid = ?");
			$sth->execute([$cat_id, $feed_id, $_SESSION["uid"]]);
			return array(API::STATUS_OK);
		}
		else
		{
			return array(API::STATUS_ERR, array("error" => 'INCORRECT_USAGE'));
		}
	}

    function api_version() {
		return 2;
	}
}

?>