<?php

/**
 * This file contains all the screens that relate to search engines.
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * This file contains code covered by:
 * copyright: 2011 Simple Machines (http://www.simplemachines.org)
 *
 * @version 2.0 dev
 *
 */

use ElkArte\Cache\Cache;
use Elkarte\Util;

/**
 * Do we think the current user is a spider?
 *
 * @return bool
 * @package SearchEngines
 */
function spiderCheck()
{
	global $modSettings;

	$db = database();

	// Feature not enabled, best guess then
	if (empty($modSettings['spider_mode']))
	{
		return spiderQuickCheck();
	}

	// Use the last data if its not stale (5 min)
	if (isset($_SESSION['robot_check']) && $_SESSION['robot_check'] > time() - 300)
	{
		return !empty($_SESSION['id_robot']);
	}

	// Fresh bot search
	unset($_SESSION['id_robot']);
	$_SESSION['robot_check'] = time();

	// We cache the sorted spider data for five minutes.
	$spider_data = array();
	$cache = Cache::instance();
	if (!$cache->getVar($spider_data, 'spider_search', 300))
	{
		$spider_data = $db->fetchQuery('
			SELECT 
				id_spider, user_agent, ip_info
			FROM {db_prefix}spiders
			ORDER BY LENGTH(user_agent) DESC',
			array()
		)->fetch_all();

		// Save it in the cache
		$cache->put('spider_search', $spider_data, 300);
	}

	if (empty($spider_data))
	{
		return false;
	}

	// We need the user agent
	$req = request();

	// Always attempt IPv6 first.
	if (strpos($_SERVER['REMOTE_ADDR'], ':') !== false)
	{
		$ip_parts = convertIPv6toInts($_SERVER['REMOTE_ADDR']);
	}
	// Then xxx.xxx.xxx.xxx next
	else
	{
		preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $_SERVER['REMOTE_ADDR'], $ip_parts);
	}

	foreach ($spider_data as $spider)
	{
		// User agent is easy.
		if (!empty($spider['user_agent']) && stripos($req->user_agent(), strtolower($spider['user_agent'])) !== false)
		{
			$_SESSION['id_robot'] = $spider['id_spider'];
		}
		// IP stuff is harder.
		elseif (!empty($ip_parts))
		{
			$ips = explode(',', $spider['ip_info']);
			foreach ($ips as $ip)
			{
				$ip = ip2range($ip);
				if (!empty($ip))
				{
					foreach ($ip as $key => $value)
					{
						if ($value['low'] > $ip_parts[$key + 1] || $value['high'] < $ip_parts[$key + 1])
						{
							break;
						}
						elseif (($key == 7 && strpos($_SERVER['REMOTE_ADDR'], ':') !== false) || ($key == 3 && strpos($_SERVER['REMOTE_ADDR'], ':') === false))
						{
							$_SESSION['id_robot'] = $spider['id_spider'];
						}
					}
				}
			}
		}

		if (isset($_SESSION['id_robot']))
		{
			break;
		}
	}

	// If this is low server tracking then log the spider here as opposed to the main logging function.
	if (!empty($modSettings['spider_mode']) && $modSettings['spider_mode'] == 1 && !empty($_SESSION['id_robot']))
	{
		logSpider();
	}

	return !empty($_SESSION['id_robot']);
}

/**
 * If we haven't turned on proper spider hunts then have a guess!
 *
 * @return bool
 * @package SearchEngines
 */
function spiderQuickCheck()
{
	// We need the user agent
	$req = request();
	$ci_user_agent = strtolower($req->user_agent());

	return strpos($ci_user_agent, 'mozilla') === false || preg_match('~(googlebot|slurp|msnbot|yandex|bingbot|baidu|duckduckbot|sogou|exabot|facebo|ecosia|ia_archiver|megaindex)~u', $ci_user_agent) == 1;
}

/**
 * Log the spider presence online.
 *
 * @package SearchEngines
 */
function logSpider()
{
	global $modSettings, $context;

	$db = database();

	if (empty($modSettings['spider_mode']) || empty($_SESSION['id_robot']))
	{
		return;
	}

	// Attempt to update today's entry.
	if ($modSettings['spider_mode'] == 1)
	{
		$date = Util::strftime('%Y-%m-%d', forum_time(false));
		$result = $db->query('', '
			UPDATE {db_prefix}log_spider_stats
			SET 
				last_seen = {int:current_time}, page_hits = page_hits + 1
			WHERE id_spider = {int:current_spider}
				AND stat_date = {date:current_date}',
			array(
				'current_date' => $date,
				'current_time' => time(),
				'current_spider' => $_SESSION['id_robot'],
			)
		);
		// Nothing updated?
		if ($result->affected_rows() == 0)
		{
			$db->insert('ignore',
				'{db_prefix}log_spider_stats',
				array(
					'id_spider' => 'int', 'last_seen' => 'int', 'stat_date' => 'date', 'page_hits' => 'int',
				),
				array(
					$_SESSION['id_robot'], time(), $date, 1,
				),
				array('id_spider', 'stat_date')
			);
		}
	}
	// If we're tracking better stats than track, better stats - we sort out the today thing later.
	else
	{
		if ($modSettings['spider_mode'] > 2)
		{
			$url = $_GET;
			if (isset($context['session_var']))
			{
				unset($url['sesc'], $url[$context['session_var']]);
			}
			else
			{
				unset($url['sesc']);
			}

			$url = serialize($url);
		}
		else
		{
			$url = '';
		}

		$db->insert('insert',
			'{db_prefix}log_spider_hits',
			array('id_spider' => 'int', 'log_time' => 'int', 'url' => 'string'),
			array($_SESSION['id_robot'], time(), $url),
			array()
		);
	}
}

/**
 * This function takes any unprocessed hits and updates stats accordingly.
 *
 * @package SearchEngines
 */
function consolidateSpiderStats()
{
	$db = database();

	$spider_hits = $db->fetchQuery('
		SELECT 
			id_spider, MAX(log_time) AS last_seen, COUNT(*) AS num_hits
		FROM {db_prefix}log_spider_hits
		WHERE processed = {int:not_processed}
		GROUP BY id_spider',
		array(
			'not_processed' => 0,
		)
	)->fetch_all();

	if (empty($spider_hits))
	{
		return;
	}

	// Attempt to update the master data.
	$stat_inserts = array();
	foreach ($spider_hits as $stat)
	{
		// We assume the max date is within the right day.
		$date = Util::strftime('%Y-%m-%d', $stat['last_seen']);
		$result = $db->query('', '
			UPDATE {db_prefix}log_spider_stats
			SET 
				page_hits = page_hits + ' . $stat['num_hits'] . ',
				last_seen = CASE WHEN last_seen > {int:last_seen} THEN last_seen ELSE {int:last_seen} END
			WHERE id_spider = {int:current_spider}
				AND stat_date = {date:last_seen_date}',
			array(
				'last_seen_date' => $date,
				'last_seen' => $stat['last_seen'],
				'current_spider' => $stat['id_spider'],
			)
		);
		if ($result->affected_rows() == 0)
		{
			$stat_inserts[] = array($date, $stat['id_spider'], $stat['num_hits'], $stat['last_seen']);
		}
	}

	// New stats?
	if (!empty($stat_inserts))
	{
		$db->insert('ignore',
			'{db_prefix}log_spider_stats',
			array('stat_date' => 'date', 'id_spider' => 'int', 'page_hits' => 'int', 'last_seen' => 'int'),
			$stat_inserts,
			array('stat_date', 'id_spider')
		);
	}

	// All processed.
	$db->query('', '
		UPDATE {db_prefix}log_spider_hits
		SET 
			processed = {int:is_processed}
		WHERE processed = {int:not_processed}',
		array(
			'is_processed' => 1,
			'not_processed' => 0,
		)
	);
}

/**
 * Re cache spider names.
 *
 * @package SearchEngines
 */
function recacheSpiderNames()
{
	$db = database();

	$spiders = array();
	$db->fetchQuery('
		SELECT 
			id_spider, spider_name
		FROM {db_prefix}spiders',
		array()
	)->fetch_callback(
		function ($row) use (&$spiders) {
			$spiders[$row['id_spider']] = $row['spider_name'];
		}
	);

	updateSettings(array('spider_name_cache' => serialize($spiders)));
}

/**
 * Return spiders, within the limits specified by parameters
 * (used by createList() callbacks)
 *
 * @param int $start The item to start with (for pagination purposes)
 * @param int $items_per_page The number of items to show per page
 * @param string $sort A string indicating how to sort the results
 *
 * @return array
 * @package SearchEngines
 *
 */
function getSpiders($start, $items_per_page, $sort)
{
	$db = database();

	$spiders = array();
	$db->fetchQuery('
		SELECT 
			id_spider, spider_name, user_agent, ip_info
		FROM {db_prefix}spiders
		ORDER BY {raw:sort}
		LIMIT {int:limit} OFFSET {int:start} ',
		array(
			'sort' => $sort,
			'start' => $start,
			'limit' => $items_per_page,
		)
	)->fetch_callback(
		function ($row) use (&$spiders) {
			$spiders[$row['id_spider']] = $row;
		}
	);

	return $spiders;
}

/**
 * Return details of one spider from its ID
 *
 * @param int $spider_id id of a spider
 * @return mixed[]
 * @package SearchEngines
 */
function getSpiderDetails($spider_id)
{
	$db = database();

	$request = $db->query('', '
		SELECT 
			id_spider as id, spider_name as name, user_agent as agent, ip_info
		FROM {db_prefix}spiders
		WHERE id_spider = {int:current_spider}',
		array(
			'current_spider' => $spider_id,
		)
	);
	$spider = $request->fetch_assoc();
	$request->free_result();

	return $spider;
}

/**
 * Return the registered spiders count.
 * (used by createList() callbacks)
 *
 * @return int
 * @package SearchEngines
 */
function getNumSpiders()
{
	$db = database();

	$request = $db->query('', '
		SELECT 
			COUNT(*) AS num_spiders
		FROM {db_prefix}spiders',
		array()
	);
	list ($numSpiders) = $request->fetch_row();
	$request->free_result();

	return $numSpiders;
}

/**
 * Retrieve spider logs within the specified limits.
 *
 * - (used by createList() callbacks)
 *
 * @param int $start The item to start with (for pagination purposes)
 * @param int $items_per_page The number of items to show per page
 * @param string $sort A string indicating how to sort the results
 * @return array An array of spider hits
 * @package SearchEngines
 */
function getSpiderLogs($start, $items_per_page, $sort)
{
	$db = database();

	return $db->fetchQuery('
		SELECT 
			sl.id_spider, sl.url, sl.log_time, s.spider_name
		FROM {db_prefix}log_spider_hits AS sl
			INNER JOIN {db_prefix}spiders AS s ON (s.id_spider = sl.id_spider)
		ORDER BY ' . $sort . '
		LIMIT ' . $items_per_page . '  OFFSET ' . $start,
		array()
	)->fetch_all();
}

/**
 * Returns the count of spider logs.
 * (used by createList() callbacks)
 *
 * @return int The number of rows in the log_spider_hits table
 * @package SearchEngines
 */
function getNumSpiderLogs()
{
	$db = database();

	$request = $db->query('', '
		SELECT 
			COUNT(*) AS num_logs
		FROM {db_prefix}log_spider_hits',
		array()
	);
	list ($numLogs) = $request->fetch_row();
	$request->free_result();

	return $numLogs;
}

/**
 * Get a list of spider stats from the log_spider table within the specified
 * limits.
 * (used by createList() callbacks)
 *
 * @param int $start The item to start with (for pagination purposes)
 * @param int $items_per_page The number of items to show per page
 * @param string $sort A string indicating how to sort the results
 *
 * @return array
 * @package SearchEngines
 *
 */
function getSpiderStats($start, $items_per_page, $sort)
{
	$db = database();

	return $db->query('', '
		SELECT 
			ss.id_spider, ss.stat_date, ss.page_hits, s.spider_name
		FROM {db_prefix}log_spider_stats AS ss
			INNER JOIN {db_prefix}spiders AS s ON (s.id_spider = ss.id_spider)
		ORDER BY ' . $sort . '
		LIMIT ' . $items_per_page . '  OFFSET ' . $start,
		array()
	)->fetch_all();
}

/**
 * Get the number of spider stat rows from the log spider stats table
 * (used by createList() callbacks)
 *
 * @param int|null $time (optional) if specified counts only the entries before that date
 * @return int The number of rows in the log_spider_stats table
 * @package SearchEngines
 */
function getNumSpiderStats($time = null)
{
	$db = database();

	$request = $db->fetchQuery('
		SELECT 
			COUNT(*)
		FROM {db_prefix}log_spider_stats' . ($time === null ? '' : '
		WHERE stat_date < {date:date_being_viewed}'),
		array(
			'date_being_viewed' => $time,
		)
	);
	list ($numStats) = $request->fetch_row();
	$request->free_result();

	return $numStats;
}

/**
 * Remove spider logs older than the passed time
 *
 * @param int $time a time value
 * @package SearchEngines
 */
function removeSpiderOldLogs($time)
{
	$db = database();

	// Delete the entries.
	$db->query('', '
		DELETE FROM {db_prefix}log_spider_hits
		WHERE log_time < {int:delete_period}',
		array(
			'delete_period' => $time,
		)
	);
}

/**
 * Remove spider logs older than the passed time
 *
 * @param int $time a time value
 * @package SearchEngines
 */
function removeSpiderOldStats($time)
{
	$db = database();

	// Delete the entries.
	$db->query('', '
		DELETE FROM {db_prefix}log_spider_stats
		WHERE last_seen < {int:delete_period}',
		array(
			'delete_period' => $time,
		)
	);
}

/**
 * Remove all the entries connected to a certain spider (description, entries, stats)
 *
 * @param int[] $spiders_id an array of spider ids
 * @package SearchEngines
 */
function removeSpiders($spiders_id)
{
	$db = database();

	$db->query('', '
		DELETE FROM {db_prefix}spiders
		WHERE id_spider IN ({array_int:remove_list})',
		array(
			'remove_list' => $spiders_id,
		)
	);
	$db->query('', '
		DELETE FROM {db_prefix}log_spider_hits
		WHERE id_spider IN ({array_int:remove_list})',
		array(
			'remove_list' => $spiders_id,
		)
	);
	$db->query('', '
		DELETE FROM {db_prefix}log_spider_stats
		WHERE id_spider IN ({array_int:remove_list})',
		array(
			'remove_list' => $spiders_id,
		)
	);
}

/**
 * Returns the last time any spider was seen around
 *
 * @package SearchEngines
 */
function spidersLastSeen()
{
	$db = database();

	$spider_last_seen = array();
	$db->query('', '
		SELECT 
			id_spider, MAX(last_seen) AS last_seen_time
		FROM {db_prefix}log_spider_stats
		GROUP BY id_spider',
		array()
	)->fetch_callback(
		function ($row) use (&$spider_last_seen) {
			$spider_last_seen[$row['id_spider']] = $row['last_seen_time'];
		}
	);

	return $spider_last_seen;
}

/**
 * Returns an array of dates ranging from the first appearance of a spider and the last
 *
 * @package SearchEngines
 */
function spidersStatsDates()
{
	global $txt;

	$db = database();

	// Get the earliest and latest dates.
	$request = $db->fetchQuery('
		SELECT 
			MIN(stat_date) AS first_date, MAX(stat_date) AS last_date
		FROM {db_prefix}log_spider_stats',
		array()
	);
	list ($min_date, $max_date) = $request->fetch_row();
	$request->free_result();

	$min_year = (int) substr($min_date, 0, 4);
	$max_year = (int) substr($max_date, 0, 4);
	$min_month = (int) substr($min_date, 5, 2);
	$max_month = (int) substr($max_date, 5, 2);

	// Prepare the dates for the drop down.
	$date_choices = array();
	for ($y = $min_year; $y <= $max_year; $y++)
	{
		for ($m = 1; $m <= 12; $m++)
		{
			// This doesn't count?
			if ($y === $min_year && $m < $min_month)
			{
				continue;
			}

			if ($y === $max_year && $m > $max_month)
			{
				break;
			}

			$date_choices[$y . $m] = $txt['months_short'][$m] . ' ' . $y;
		}
	}

	return $date_choices;
}

/**
 * Update an existing or inserts a new spider entry
 *
 * @param int $id
 * @param string $name spider name
 * @param string $agent ua of the spider
 * @param string $info_ip
 * @package SearchEngines
 */
function updateSpider($id = 0, $name = '', $agent = '', $info_ip = '')
{
	$db = database();

	// New spider, insert
	if (empty($id))
	{
		$db->insert('insert',
			'{db_prefix}spiders',
			array(
				'spider_name' => 'string', 'user_agent' => 'string', 'ip_info' => 'string',
			),
			array(
				$name, $agent, $info_ip,
			),
			array('id_spider')
		);
	}
	// Existing spider update
	else
	{
		$db->query('', '
			UPDATE {db_prefix}spiders
			SET 
				spider_name = {string:spider_name}, user_agent = {string:spider_agent},
				ip_info = {string:ip_info}
			WHERE id_spider = {int:current_spider}',
			array(
				'current_spider' => $id,
				'spider_name' => $name,
				'spider_agent' => $agent,
				'ip_info' => $info_ip,
			)
		);
	}
}
