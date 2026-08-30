<?php
/*
 * PROJECT:     ReactOS Testman
 * LICENSE:     GPL-2.0-or-later (https://spdx.org/licenses/GPL-2.0-or-later)
 * PURPOSE:     JSON endpoint for browsing test runs by date, source, platform and revision
 * COPYRIGHT:   Copyright 2026 Mark Jansen (mark.jansen@reactos.org)
 */

	require_once("config.inc.php");
	require_once(ROOT_PATH . "../www.reactos.org_config/testman-connect.php");
	require_once("../utils.inc.php");

	/**
	 * Reads a GET parameter and checks it against $pattern.
	 *
	 * @return
	 * The trimmed value, or NULL if the parameter was absent or empty.
	 */
	function get_param($name, $pattern)
	{
		if (!array_key_exists($name, $_GET))
			return NULL;

		$value = trim($_GET[$name]);
		if ($value === "")
			return NULL;

		if (!preg_match($pattern, $value))
			throw new InvalidArgumentException("Invalid value for '$name'");

		return $value;
	}

	function get_int_param($name, $min)
	{
		$value = get_param($name, "#^[0-9]+$#");
		if ($value === NULL)
			return NULL;

		if ((int)$value < $min)
			throw new InvalidArgumentException("'$name' must be at least $min");

		return (int)$value;
	}

	/**
	 * Accepts "YYYY-MM-DD" and "YYYY-MM-DD HH:MM[:SS]" (with a space or a "T") and
	 * returns it in the form MySQL compares against a TIMESTAMP column.
	 *
	 * @param bool $exclusive_end
	 * Set for the end of a range. "to=2026-08-15" reads as "up to and including the
	 * 15th", so a value without a time of day then covers the whole day. The comparison
	 * in the query stays exclusive either way.
	 */
	function get_date_param($name, $exclusive_end = FALSE)
	{
		$value = get_param($name, "#^[0-9]{4}-[0-9]{2}-[0-9]{2}([ T][0-9]{2}:[0-9]{2}(:[0-9]{2})?)?$#");
		if ($value === NULL)
			return NULL;

		$date = date_create(str_replace("T", " ", $value));
		if ($date === FALSE)
			throw new InvalidArgumentException("'$name' is not a valid date");

		if ($exclusive_end && strlen($value) == 10)
			$date->modify("+1 day");

		return $date->format("Y-m-d H:i:s");
	}

	header("Content-Type: application/json; charset=utf-8");

	try
	{
		// Every filter is optional and independent, so adding one is a block here and
		// nothing else.
		$where = array("r.finished = 1");
		$params = array();

		$from = get_date_param("from");
		if ($from !== NULL)
		{
			$where[] = "r.timestamp >= :from";
			$params[":from"] = $from;
		}

		$to = get_date_param("to", TRUE);
		if ($to !== NULL)
		{
			$where[] = "r.timestamp < :to";
			$params[":to"] = $to;
		}

		// A prefix of the commit hash. KEY (revision) serves this at the full 40 chars,
		// and unlike the revision range of ajax-search.php it does not consult gitinfo,
		// so it also finds runs whose commit gitinfo never saw.
		$rev = get_param("rev", "#^[0-9a-fA-F]{4,40}$#");
		if ($rev !== NULL)
		{
			$where[] = "r.revision LIKE :rev";
			$params[":rev"] = strtolower($rev) . "%";
		}

		// sources.id, not a LIKE on the display name.
		$source = get_int_param("source", 1);
		if ($source !== NULL)
		{
			$where[] = "r.source_id = :source";
			$params[":source"] = $source;
		}

		$platform = get_param("platform", "#^[A-Za-z0-9._-]{1,24}$#");
		if ($platform !== NULL)
		{
			$where[] = "r.platform LIKE :platform";
			$params[":platform"] = $platform . "%";
		}

		$min_failures = get_int_param("min_failures", 0);
		if ($min_failures !== NULL)
		{
			$where[] = "r.failures >= :min_failures";
			$params[":min_failures"] = $min_failures;
		}

		// Keyset paging. "older" continues below the last id of the previous page,
		// "newer" continues above its first id. No offsets, and no COUNT(*): the one
		// extra row fetched below is what tells us whether a further page exists.
		$newer = (get_param("dir", "#^(older|newer)$#") === "newer");
		$cursor = get_int_param("cursor", 1);
		if ($cursor !== NULL)
		{
			$where[] = $newer ? "r.id > :cursor" : "r.id < :cursor";
			$params[":cursor"] = $cursor;
		}

		$limit = get_int_param("limit", 1);
		if ($limit === NULL)
			$limit = API_PAGE_SIZE;

		$limit = min($limit, API_MAX_PAGE_SIZE);

		$dbh = new PDO("mysql:host=" . TESTMAN_DB_HOST . ";dbname=" . TESTMAN_DB_NAME, TESTMAN_DB_USER, TESTMAN_DB_PASS);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// a date filter leaves a filesort inside the ix_runs_ts range, which is
		// cheap for windows up to about a year. If wider windows ever get slow, resolve
		// from/to into an id range first - timestamp is monotonic with id, because both
		// are assigned when the run is inserted - and page on the primary key alone.
		$stmt = $dbh->prepare(
			"SELECT r.id, UNIX_TIMESTAMP(r.timestamp) AS timestamp, r.source_id, src.name AS source, " .
			"r.revision, r.platform, r.comment, r.count, r.failures " .
			"FROM winetest_runs r " .
			"JOIN sources src ON r.source_id = src.id " .
			"WHERE " . implode(" AND ", $where) . " " .
			"ORDER BY r.id " . ($newer ? "ASC" : "DESC") . " " .
			"LIMIT " . ($limit + 1)
		);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// The extra row is only a probe, it never gets rendered.
		$has_more = (count($rows) > $limit);
		if ($has_more)
			array_pop($rows);

		// Always hand out the page newest first, whichever direction it was read in.
		if ($newer)
			$rows = array_reverse($rows);

		$runs = array();

		foreach ($rows as $row)
		{
			$runs[] = array(
				"id" => (int)$row["id"],
				"timestamp" => (int)$row["timestamp"],
				// Preformatted here so that the client agrees with every page that renders
				// a date server-side, whatever timezone the browser happens to be in.
				"date" => GetDateString($row["timestamp"]),
				"source_id" => (int)$row["source_id"],
				"source" => $row["source"],
				"revision" => $row["revision"],
				"revision_short" => substr($row["revision"], 0, 7),
				"platform" => $row["platform"],
				"platform_name" => GetPlatformString($row["platform"]),
				"comment" => $row["comment"],
				"count" => (int)$row["count"],
				"failures" => (int)$row["failures"],
			);
		}

		echo json_encode(array(
			"runs" => $runs,
			// Whether a further page exists in the direction that was asked for.
			"has_more" => $has_more,
			// Cursors for the two directions: "newer" from first_id, "older" from last_id.
			"first_id" => count($runs) ? $runs[0]["id"] : NULL,
			"last_id" => count($runs) ? $runs[count($runs) - 1]["id"] : NULL,
		));
	}
	catch (InvalidArgumentException $e)
	{
		http_response_code(400);
		echo json_encode(array("error" => $e->getMessage()));
	}
	catch (Exception $e)
	{
		error_log("testman api/runs.php: " . $e->getFile() . ":" . $e->getLine() . " - " . $e->getMessage());
		http_response_code(500);
		echo json_encode(array("error" => "Internal error"));
	}
