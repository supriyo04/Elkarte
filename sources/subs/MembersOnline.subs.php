<?php

/**
 * Function to support online user functions
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
use ElkArte\User;
use ElkArte\Util;

/**
 * Retrieve a list and several other statistics of the users currently online.
 *
 * - Used by the board index and SSI.
 * - Also returns the membergroups of the users that are currently online.
 * - (optionally) hides members that chose to hide their online presence.
 *
 * @param mixed[] $membersOnlineOptions
 * @return array
 * @package Members
 */
function getMembersOnlineStats($membersOnlineOptions)
{
	global $modSettings, $txt;

	$db = database();

	// The list can be sorted in several ways.
	$allowed_sort_options = array(
		'', // No sorting.
		'log_time',
		'real_name',
		'show_online',
		'online_color',
		'group_name',
	);

	// Default the sorting method to 'most recent online members first'.
	if (!isset($membersOnlineOptions['sort']))
	{
		$membersOnlineOptions['sort'] = 'log_time';
		$membersOnlineOptions['reverse_sort'] = true;
	}

	// Not allowed sort method? Bang! Error!
	elseif (!in_array($membersOnlineOptions['sort'], $allowed_sort_options))
	{
		trigger_error('Sort method for getMembersOnlineStats() function is not allowed', E_USER_NOTICE);
	}

	// Get it from the cache and send it back.
	$temp = array();
	$cache = Cache::instance();
	if ($cache->levelHigherThan(1) && $cache->getVar($temp, 'membersOnlineStats-' . $membersOnlineOptions['sort'], 240))
	{
		return filter_members_online($temp, empty($membersOnlineOptions['reverse_sort']) ? 'ksort' : 'krsort');
	}

	// Initialize the array that'll be returned later on.
	$membersOnlineStats = array(
		'users_online' => array(),
		'list_users_online' => array(),
		'online_groups' => array(),
		'num_guests' => 0,
		'num_spiders' => 0,
		'num_buddies' => 0,
		'num_users_hidden' => 0,
		'num_users_online' => 0,
	);

	// Get any spiders if enabled.
	$spiders = array();
	$spider_finds = array();
	if (!empty($modSettings['show_spider_online']) && ($modSettings['show_spider_online'] < 3 || allowedTo('admin_forum')) && !empty($modSettings['spider_name_cache']))
	{
		$spiders = Util::unserialize($modSettings['spider_name_cache']);
	}

	// Load the users online right now.
	$request = $db->query('', '
		SELECT
			lo.id_member, lo.log_time, lo.id_spider, mem.real_name, mem.member_name, mem.show_online,
			mg.online_color, mg.id_group, mg.group_name
		FROM {db_prefix}log_online AS lo
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lo.id_member)
			LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = CASE WHEN mem.id_group = {int:reg_mem_group} THEN mem.id_post_group ELSE mem.id_group END)',
		array(
			'reg_mem_group' => 0,
		)
	);
	while (($row = $request->fetch_assoc()))
	{
		if (empty($row['real_name']))
		{
			// Do we think it's a spider?
			if ($row['id_spider'] && isset($spiders[$row['id_spider']]))
			{
				$spider_finds[$row['id_spider']] = isset($spider_finds[$row['id_spider']]) ? $spider_finds[$row['id_spider']] + 1 : 1;
				$membersOnlineStats['num_spiders']++;
			}
			// Guests are only nice for statistics.
			$membersOnlineStats['num_guests']++;

			continue;
		}
		elseif (empty($row['show_online']) && empty($membersOnlineOptions['show_hidden']))
		{
			// Just increase the stats and don't add this hidden user to any list.
			$membersOnlineStats['num_users_hidden']++;
			continue;
		}

		$href = getUrl('profile', ['action' => 'profile', 'u' => $row['id_member'], 'name' => $row['real_name']]);

		// Some basic color coding...
		if (!empty($row['online_color']))
		{
			$link = '<a href="' . $href . '" style="color: ' . $row['online_color'] . ';">' . $row['real_name'] . '</a>';
		}
		else
		{
			$link = '<a href="' . $href . '">' . $row['real_name'] . '</a>';
		}

		// Buddies get counted and highlighted.
		$is_buddy = in_array($row['id_member'], User::$info->buddies);
		if ($is_buddy)
		{
			$membersOnlineStats['num_buddies']++;
			$link = '<strong>' . $link . '</strong>';
		}

		// A lot of useful information for each member.
		$membersOnlineStats['users_online'][$row[$membersOnlineOptions['sort']] . '_' . $row['member_name']] = array(
			'id' => $row['id_member'],
			'username' => $row['member_name'],
			'name' => $row['real_name'],
			'group' => $row['id_group'],
			'href' => $href,
			'link' => $link,
			'is_buddy' => $is_buddy,
			'hidden' => empty($row['show_online']),
			'is_last' => false,
		);

		// Store all distinct (primary) membergroups that are shown.
		if (!isset($membersOnlineStats['online_groups'][$row['id_group']]))
		{
			$membersOnlineStats['online_groups'][$row['id_group']] = array(
				'id' => $row['id_group'],
				'name' => $row['group_name'],
				'color' => $row['online_color']
			);
		}
	}
	$request->free_result();

	// If there are spiders only and we're showing the detail, add them to the online list - at the bottom.
	if (!empty($spider_finds) && $modSettings['show_spider_online'] > 1)
	{
		$sort = $membersOnlineOptions['sort'] === 'log_time' && $membersOnlineOptions['reverse_sort'] ? 0 : 'zzz_';
		foreach ($spider_finds as $id => $count)
		{
			$link = $spiders[$id] . ($count > 1 ? ' (' . $count . ')' : '');
			$membersOnlineStats['users_online'][$sort . '_' . $spiders[$id]] = array(
				'id' => 0,
				'username' => $spiders[$id],
				'name' => $link,
				'group' => $txt['spiders'],
				'href' => '',
				'link' => $link,
				'is_buddy' => false,
				'hidden' => false,
				'is_last' => false,
			);
			$membersOnlineStats['list_users_online'][$sort . '_' . $spiders[$id]] = $link;
		}
	}

	// Hidden and non-hidden members make up all online members.
	$membersOnlineStats['num_users_online'] = count($membersOnlineStats['users_online']) + $membersOnlineStats['num_users_hidden'] - (isset($modSettings['show_spider_online']) && $modSettings['show_spider_online'] > 1 ? count($spider_finds) : 0);

	Cache::instance()->put('membersOnlineStats-' . $membersOnlineOptions['sort'], $membersOnlineStats, 240);

	return filter_members_online($membersOnlineStats, empty($membersOnlineOptions['reverse_sort']) ? 'ksort' : 'krsort');
}

/**
 * Post-retrieval processing.
 * Needed mainly for when the cache is enabled and online users have to be
 * filtered out based on permissions.
 *
 * @param mixed[] $membersOnlineStats
 * @param string $sortFunction
 * @return mixed[]
 * @package Members
 */
function filter_members_online($membersOnlineStats, $sortFunction)
{
	foreach ($membersOnlineStats['users_online'] as $key => $row)
	{
		if (allowedTo('moderate_forum') === false && $row['hidden'])
		{
			continue;
		}
		// This is the compact version, simply implode it to show.
		$membersOnlineStats['list_users_online'][$key] = $row['hidden'] ? '<em>' . $row['link'] . '</em>' : $row['link'];
	}

	// Time to sort the list a bit.
	if (!empty($membersOnlineStats['users_online']))
	{
		// Sort the two lists.
		$sortFunction($membersOnlineStats['users_online']);
		$sortFunction($membersOnlineStats['list_users_online']);

		// Mark the last list item as 'is_last'.
		$userKeys = array_keys($membersOnlineStats['users_online']);
		$membersOnlineStats['users_online'][end($userKeys)]['is_last'] = true;
	}

	// Also sort the membergroups.
	ksort($membersOnlineStats['online_groups']);

	return $membersOnlineStats;
}

/**
 * Check if the number of users online is a record and store it.
 *
 * @param int $total_users_online
 * @package Members
 */
function trackStatsUsersOnline($total_users_online)
{
	global $modSettings;

	$db = database();

	$settingsToUpdate = array();

	// More members on now than ever were?  Update it!
	if (!isset($modSettings['mostOnline']) || $total_users_online >= $modSettings['mostOnline'])
	{
		$settingsToUpdate = array(
			'mostOnline' => $total_users_online,
			'mostDate' => time()
		);
	}

	$date = Util::strftime('%Y-%m-%d', forum_time(false));

	// No entry exists for today yet?
	if (!isset($modSettings['mostOnlineUpdated']) || $modSettings['mostOnlineUpdated'] != $date)
	{
		$request = $db->query('', '
			SELECT 
				most_on
			FROM {db_prefix}log_activity
			WHERE date = {date:date}
			LIMIT 1',
			array(
				'date' => $date,
			)
		);

		// The log_activity hasn't got an entry for today?
		if ($request->num_rows() === 0)
		{
			$db->insert('ignore',
				'{db_prefix}log_activity',
				array('date' => 'date', 'most_on' => 'int'),
				array($date, $total_users_online),
				array('date')
			);
		}
		// There's an entry in log_activity on today...
		else
		{
			list ($modSettings['mostOnlineToday']) = $request->fetch_row();

			if ($total_users_online > $modSettings['mostOnlineToday'])
			{
				trackStats(array('most_on' => $total_users_online));
			}

			$total_users_online = max($total_users_online, $modSettings['mostOnlineToday']);
		}
		$request->free_result();

		$settingsToUpdate['mostOnlineUpdated'] = $date;
		$settingsToUpdate['mostOnlineToday'] = $total_users_online;
	}
	// Highest number of users online today?
	elseif ($total_users_online > $modSettings['mostOnlineToday'])
	{
		trackStats(array('most_on' => $total_users_online));
		$settingsToUpdate['mostOnlineToday'] = $total_users_online;
	}

	if (!empty($settingsToUpdate))
	{
		updateSettings($settingsToUpdate);
	}
}
