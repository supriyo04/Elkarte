<?php

/**
 * This file contains several functions for retrieving and manipulating calendar events, birthdays and holidays.
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
 * Get all birthdays within the given time range.
 *
 * What it does:
 *
 * - finds all the birthdays in the specified range of days.
 * - works with birthdays set for no year, or any other year, and respects month and year boundaries.
 *
 * @param string $low_date inclusive, YYYY-MM-DD
 * @param string $high_date inclusive, YYYY-MM-DD
 * @return mixed[] days, each of which an array of birthday information for the context
 * @package Calendar
 */
function getBirthdayRange($low_date, $high_date)
{
	$db = database();

	// We need to search for any birthday in this range, and whatever year that birthday is on.
	$year_low = (int) substr($low_date, 0, 4);
	$year_high = (int) substr($high_date, 0, 4);

	// Collect all of the birthdays for this month.  I know, it's a painful query.
	$result = $db->fetchQuery('
		SELECT 
			id_member, real_name, YEAR(birthdate) AS birth_year, birthdate
		FROM {db_prefix}members
		WHERE YEAR(birthdate) != {string:year_one}
			AND MONTH(birthdate) != {int:no_month}
			AND DAYOFMONTH(birthdate) != {int:no_day}
			AND YEAR(birthdate) <= {int:max_year}
			AND (
				DATE_FORMAT(birthdate, {string:year_low}) BETWEEN {date:low_date} AND {date:high_date}' . ($year_low == $year_high ? '' : '
				OR DATE_FORMAT(birthdate, {string:year_high}) BETWEEN {date:low_date} AND {date:high_date}') . '
			)
			AND is_activated = {int:is_activated}',
		array(
			'is_activated' => 1,
			'no_month' => 0,
			'no_day' => 0,
			'year_one' => '0001',
			'year_low' => $year_low . '-%m-%d',
			'year_high' => $year_high . '-%m-%d',
			'low_date' => $low_date,
			'high_date' => $high_date,
			'max_year' => $year_high,
		)
	);
	$bday = array();
	while (($row = $result->fetch_assoc()))
	{
		if ($year_low != $year_high)
		{
			$age_year = substr($row['birthdate'], 5) < substr($high_date, 5) ? $year_high : $year_low;
		}
		else
		{
			$age_year = $year_low;
		}

		$bday[$age_year . substr($row['birthdate'], 4)][] = array(
			'id' => $row['id_member'],
			'name' => $row['real_name'],
			'age' => $row['birth_year'] > 4 && $row['birth_year'] <= $age_year ? $age_year - $row['birth_year'] : null,
			'is_last' => false
		);
	}
	$result->free_result();

	// Set is_last, so the themes know when to stop placing separators.
	foreach ($bday as $mday => $array)
	{
		$bday[$mday][count($array) - 1]['is_last'] = true;
	}

	return $bday;
}

/**
 * Get all calendar events within the given time range.
 *
 * What it does:
 *
 * - finds all the posted calendar events within a date range.
 * - both the earliest_date and latest_date should be in the standard YYYY-MM-DD format.
 * - censors the posted event titles.
 * - uses the current user's permissions if use_permissions is true, otherwise it does nothing "permission specific"
 *
 * @param string $low_date
 * @param string $high_date
 * @param bool $use_permissions = true
 * @param int|null $limit
 * @return array contextual information if use_permissions is true, and an array of the data needed to build that otherwise
 * @package Calendar
 */
function getEventRange($low_date, $high_date, $use_permissions = true, $limit = null)
{
	global $modSettings;

	$db = database();

	$low_date_time = sscanf($low_date, '%04d-%02d-%02d');
	$low_date_time = mktime(0, 0, 0, $low_date_time[1], $low_date_time[2], $low_date_time[0]);
	$high_date_time = sscanf($high_date, '%04d-%02d-%02d');
	$high_date_time = mktime(0, 0, 0, $high_date_time[1], $high_date_time[2], $high_date_time[0]);

	// Find all the calendar info...
	$result = $db->query('', '
		SELECT
			cal.id_event, cal.start_date, cal.end_date, cal.title, cal.id_member, cal.id_topic,
			cal.id_board, b.member_groups, t.id_first_msg, t.approved, m.subject, b.id_board
		FROM {db_prefix}calendar AS cal
			LEFT JOIN {db_prefix}boards AS b ON (b.id_board = cal.id_board)
			LEFT JOIN {db_prefix}topics AS t ON (t.id_topic = cal.id_topic)
			LEFT JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
		WHERE cal.start_date <= {date:high_date}
			AND cal.end_date >= {date:low_date}' . ($use_permissions ? '
			AND (cal.id_board = {int:no_board_link} OR {query_wanna_see_board})' : '') . (!empty($limit) ? '
		LIMIT {int:limit}' : ''),
		array(
			'high_date' => $high_date,
			'low_date' => $low_date,
			'no_board_link' => 0,
			'limit' => $limit,
		)
	);
	$events = array();
	while (($row = $result->fetch_assoc()))
	{
		// If the attached topic is not approved then for the moment pretend it doesn't exist
		if (!empty($row['id_first_msg']) && $modSettings['postmod_active'] && !$row['approved'])
		{
			continue;
		}

		// Force a censor of the title - as often these are used by others.
		$row['title'] = censor($row['title'], $use_permissions ? false : true);

		$start_date = sscanf($row['start_date'], '%04d-%02d-%02d');
		$start_date = max(mktime(0, 0, 0, $start_date[1], $start_date[2], $start_date[0]), $low_date_time);
		$end_date = sscanf($row['end_date'], '%04d-%02d-%02d');
		$end_date = min(mktime(0, 0, 0, $end_date[1], $end_date[2], $end_date[0]), $high_date_time);

		$lastDate = '';
		for ($date = $start_date; $date <= $end_date; $date += 86400)
		{
			// Attempt to avoid DST problems.
			// @todo Resolve this properly at some point.
			if (Util::strftime('%Y-%m-%d', $date) == $lastDate)
			{
				$date += 3601;
			}
			$lastDate = Util::strftime('%Y-%m-%d', $date);
			$href = getUrl('topic', ['topic' => $row['id_topic'], 'start' => '0', 'subject' => $row['subject']]);

			// If we're using permissions (calendar pages?) then just output normal contextual style information.
			if ($use_permissions)
			{
				if ($row['id_board'] == 0)
				{
					$modify_href = ['action' => 'calendar', 'sa' => 'post', 'eventid' => $row['id_event'], '{session_data}'];
				}
				else
				{
					$modify_href = ['action' => 'post', 'msg' => $row['id_first_msg'], 'topic' => $row['id_topic'] . '.0', 'calendar', 'eventid' => $row['id_event'], '{session_data}'];
				}

				$events[Util::strftime('%Y-%m-%d', $date)][] = array(
					'id' => $row['id_event'],
					'title' => $row['title'],
					'start_date' => $row['start_date'],
					'end_date' => $row['end_date'],
					'is_last' => false,
					'id_board' => $row['id_board'],
					'id_topic' => $row['id_topic'],
					'href' => $row['id_board'] == 0 ? '' : $href,
					'link' => $row['id_board'] == 0 ? $row['title'] : '<a href="' . $href . '">' . $row['title'] . '</a>',
					'can_edit' => allowedTo('calendar_edit_any') || ($row['id_member'] == User::$info->id && allowedTo('calendar_edit_own')),
					'modify_href' => getUrl('action', $modify_href),
					'can_export' => !empty($modSettings['cal_export']) ? true : false,
					'export_href' => getUrl('action', ['action' => 'calendar', 'sa' => 'ical', 'eventid' => $row['id_event'], '{session_data}']),
				);
			}
			// Otherwise, this is going to be cached and the VIEWER'S permissions should apply... just put together some info.
			else
			{
				$events[Util::strftime('%Y-%m-%d', $date)][] = array(
					'id' => $row['id_event'],
					'title' => $row['title'],
					'start_date' => $row['start_date'],
					'end_date' => $row['end_date'],
					'is_last' => false,
					'id_board' => $row['id_board'],
					'id_topic' => $row['id_topic'],
					'href' => $row['id_topic'] == 0 ? '' : $href,
					'link' => $row['id_topic'] == 0 ? $row['title'] : '<a href="' . $href . '">' . $row['title'] . '</a>',
					'can_edit' => false,
					'can_export' => !empty($modSettings['cal_export']) ? true : false,
					'topic' => $row['id_topic'],
					'msg' => $row['id_first_msg'],
					'poster' => $row['id_member'],
					'allowed_groups' => explode(',', $row['member_groups']),
				);
			}
		}
	}
	$result->free_result();

	// If we're doing normal contextual data, go through and make things clear to the templates ;).
	if ($use_permissions)
	{
		foreach ($events as $mday => $array)
		{
			$events[$mday][count($array) - 1]['is_last'] = true;
		}
	}

	return $events;
}

/**
 * Get all holidays within the given time range.
 *
 * @param string $low_date YYYY-MM-DD
 * @param string $high_date YYYY-MM-DD
 * @return array an array of days, which are all arrays of holiday names.
 * @package Calendar
 */
function getHolidayRange($low_date, $high_date)
{
	$db = database();

	// Get the lowest and highest dates for "all years".
	if (substr($low_date, 0, 4) != substr($high_date, 0, 4))
	{
		$allyear_part = 'event_date BETWEEN {date:all_year_low} AND {date:all_year_dec}
			OR event_date BETWEEN {date:all_year_jan} AND {date:all_year_high}';
	}
	else
	{
		$allyear_part = 'event_date BETWEEN {date:all_year_low} AND {date:all_year_high}';
	}

	// Find some holidays... ;).
	$holidays = array();
	$db->fetchQuery('
		SELECT 
			event_date, YEAR(event_date) AS year, title
		FROM {db_prefix}calendar_holidays
		WHERE event_date BETWEEN {date:low_date} AND {date:high_date}
			OR ' . $allyear_part,
		array(
			'low_date' => $low_date,
			'high_date' => $high_date,
			'all_year_low' => '0004' . substr($low_date, 4),
			'all_year_high' => '0004' . substr($high_date, 4),
			'all_year_jan' => '0004-01-01',
			'all_year_dec' => '0004-12-31',
		)
	)->fetch_callback(
		function ($row) use (&$holidays, $low_date, $high_date) {
			if (substr($low_date, 0, 4) != substr($high_date, 0, 4))
			{
				$event_year = substr($row['event_date'], 5) < substr($high_date, 5) ? substr($high_date, 0, 4) : substr($low_date, 0, 4);
			}
			else
			{
				$event_year = substr($low_date, 0, 4);
			}

			$holidays[$event_year . substr($row['event_date'], 4)][] = $row['title'];
		}
	);

	return $holidays;
}

/**
 * Does permission checks to see if an event can be linked to a board/topic.
 *
 * What it does:
 *
 * - checks if the current user can link the current topic to the calendar, permissions et al.
 * - this requires the calendar_post permission, a forum moderator, or a topic starter.
 * - expects the $topic and $board variables to be set.
 * - if the user doesn't have proper permissions, an error will be shown.
 *
 * @package Calendar
 * @todo pass $board, $topic and User::$info->id as arguments with fallback for 1.1
 * @throws \ElkArte\Exceptions\Exception missing_board_id, missing_topic_id
 */
function canLinkEvent()
{
	global $topic, $board;

	// If you can't post, you can't link.
	isAllowedTo('calendar_post');

	// No board?  No topic?!?
	if (empty($board))
	{
		throw new \ElkArte\Exceptions\Exception('missing_board_id', false);
	}

	if (empty($topic))
	{
		throw new \ElkArte\Exceptions\Exception('missing_topic_id', false);
	}

	// Administrator, Moderator, or owner.  Period.
	if (!allowedTo('admin_forum') && !allowedTo('moderate_board'))
	{
		// Not admin or a moderator of this board. You better be the owner - or else.
		$row = topicAttribute($topic, array('id_member_started'));
		if (!empty($row))
		{
			// Not the owner of the topic.
			if ($row['id_member_started'] != User::$info->id)
			{
				throw new \ElkArte\Exceptions\Exception('not_your_topic', 'user');
			}
		}
		// Topic/Board doesn't exist.....
		else
		{
			throw new \ElkArte\Exceptions\Exception('calendar_no_topic', 'general');
		}
	}
}

/**
 * Returns date information about 'today' relative to the users time offset.
 *
 * - returns an array with the current date, day, month, and year.
 * takes the users time offset into account.
 *
 * @package Calendar
 */
function getTodayInfo()
{
	return array(
		'day' => (int) Util::strftime('%d', forum_time()),
		'month' => (int) Util::strftime('%m', forum_time()),
		'year' => (int) Util::strftime('%Y', forum_time()),
		'date' => Util::strftime('%Y-%m-%d', forum_time()),
	);
}

/**
 * Provides information (link, month, year) about the previous and next month.
 *
 * @param int $month
 * @param int $year
 * @param mixed[] $calendarOptions
 * @return array containing all the information needed to show a calendar grid for the given month
 * @package Calendar
 */
function getCalendarGrid($month, $year, $calendarOptions)
{
	global $modSettings;

	// Eventually this is what we'll be returning.
	$calendarGrid = array(
		'week_days' => array(),
		'weeks' => array(),
		'short_day_titles' => !empty($calendarOptions['short_day_titles']),
		'current_month' => $month,
		'current_year' => $year,
		'show_next_prev' => !empty($calendarOptions['show_next_prev']),
		'show_week_links' => !empty($calendarOptions['show_week_links']),
		'previous_calendar' => array(
			'year' => $month == 1 ? $year - 1 : $year,
			'month' => $month == 1 ? 12 : $month - 1,
			'disabled' => $modSettings['cal_minyear'] > ($month == 1 ? $year - 1 : $year),
		),
		'next_calendar' => array(
			'year' => $month == 12 ? $year + 1 : $year,
			'month' => $month == 12 ? 1 : $month + 1,
			'disabled' => date('Y') + $modSettings['cal_limityear'] < ($month == 12 ? $year + 1 : $year),
		),
		'size' => $calendarOptions['size'] ?? 'large',
	);

	// Get todays date.
	$today = getTodayInfo();

	// Get information about this month.
	$month_info = array(
		'first_day' => array(
			'day_of_week' => (int) Util::strftime('%w', mktime(0, 0, 0, $month, 1, $year)),
			'week_num' => (int) Util::strftime('%U', mktime(0, 0, 0, $month, 1, $year)),
			'date' => Util::strftime('%Y-%m-%d', mktime(0, 0, 0, $month, 1, $year)),
		),
		'last_day' => array(
			'day_of_month' => (int) Util::strftime('%d', mktime(0, 0, 0, $month == 12 ? 1 : $month + 1, 0, $month == 12 ? $year + 1 : $year)),
			'date' => Util::strftime('%Y-%m-%d', mktime(0, 0, 0, $month == 12 ? 1 : $month + 1, 0, $month == 12 ? $year + 1 : $year)),
		),
		'first_day_of_year' => (int) Util::strftime('%w', mktime(0, 0, 0, 1, 1, $year)),
		'first_day_of_next_year' => (int) Util::strftime('%w', mktime(0, 0, 0, 1, 1, $year + 1)),
	);

	// The number of days the first row is shifted to the right for the starting day.
	$nShift = $month_info['first_day']['day_of_week'];

	$calendarOptions['start_day'] = empty($calendarOptions['start_day']) ? 0 : (int) $calendarOptions['start_day'];

	// Starting any day other than Sunday means a shift...
	if (!empty($calendarOptions['start_day']))
	{
		$nShift -= $calendarOptions['start_day'];
		if ($nShift < 0)
		{
			$nShift = 7 + $nShift;
		}
	}

	// Number of rows required to fit the month.
	$nRows = floor(($month_info['last_day']['day_of_month'] + $nShift) / 7);
	if (($month_info['last_day']['day_of_month'] + $nShift) % 7)
	{
		$nRows++;
	}

	// Fetch the arrays for birthdays, posted events, and holidays.
	$bday = $calendarOptions['show_birthdays'] ? getBirthdayRange($month_info['first_day']['date'], $month_info['last_day']['date']) : array();
	$events = $calendarOptions['show_events'] ? getEventRange($month_info['first_day']['date'], $month_info['last_day']['date']) : array();
	$holidays = $calendarOptions['show_holidays'] ? getHolidayRange($month_info['first_day']['date'], $month_info['last_day']['date']) : array();

	// Days of the week taking into consideration that they may want it to start on any day.
	$count = $calendarOptions['start_day'];
	for ($i = 0; $i < 7; $i++)
	{
		$calendarGrid['week_days'][] = $count;
		$count++;
		if ($count == 7)
		{
			$count = 0;
		}
	}

	// An adjustment value to apply to all calculated week numbers.
	if (!empty($calendarOptions['show_week_num']))
	{
		// If the first day of the year is a Sunday, then there is no
		// adjustment to be made. However, if the first day of the year is not
		// a Sunday, then there is a partial week at the start of the year
		// that needs to be accounted for.
		if ($calendarOptions['start_day'] === 0)
		{
			$nWeekAdjust = $month_info['first_day_of_year'] === 0 ? 0 : 1;
		}
		// If we are viewing the weeks, with a starting date other than Sunday,
		// then things get complicated! Basically, as PHP is calculating the
		// weeks with a Sunday starting date, we need to take this into account
		// and offset the whole year dependant on whether the first day in the
		// year is above or below our starting date. Note that we offset by
		// two, as some of this will get undone quite quickly by the statement
		// below.
		else
		{
			$nWeekAdjust = $calendarOptions['start_day'] > $month_info['first_day_of_year'] && $month_info['first_day_of_year'] !== 0 ? 2 : 1;
		}

		// If our week starts on a day greater than the day the month starts
		// on, then our week numbers will be one too high. So we need to
		// reduce it by one - all these thoughts of offsets makes my head
		// hurt...
		if ($month_info['first_day']['day_of_week'] < $calendarOptions['start_day'] || $month_info['first_day_of_year'] > 4)
		{
			$nWeekAdjust--;
		}
	}
	else
	{
		$nWeekAdjust = 0;
	}

	// Iterate through each week.
	$calendarGrid['weeks'] = array();
	for ($nRow = 0; $nRow < $nRows; $nRow++)
	{
		// Start off the week - and don't let it go above 52, since that's the number of weeks in a year.
		$calendarGrid['weeks'][$nRow] = array(
			'days' => array(),
			'number' => $month_info['first_day']['week_num'] + $nRow + $nWeekAdjust
		);

		// Handle the dreaded "week 53", it can happen, but only once in a blue moon ;)
		if ($calendarGrid['weeks'][$nRow]['number'] == 53 && $nShift != 4 && $month_info['first_day_of_next_year'] < 4)
		{
			$calendarGrid['weeks'][$nRow]['number'] = 1;
		}

		// And figure out all the days.
		for ($nCol = 0; $nCol < 7; $nCol++)
		{
			$nDay = ($nRow * 7) + $nCol - $nShift + 1;

			if ($nDay < 1 || $nDay > $month_info['last_day']['day_of_month'])
			{
				$nDay = 0;
			}

			$date = sprintf('%04d-%02d-%02d', $year, $month, $nDay);

			$calendarGrid['weeks'][$nRow]['days'][$nCol] = array(
				'day' => $nDay,
				'date' => $date,
				'is_today' => $date == $today['date'],
				'is_first_day' => !empty($calendarOptions['show_week_num']) && (($month_info['first_day']['day_of_week'] + $nDay - 1) % 7 == $calendarOptions['start_day']),
				'holidays' => !empty($holidays[$date]) ? $holidays[$date] : array(),
				'events' => !empty($events[$date]) ? $events[$date] : array(),
				'birthdays' => !empty($bday[$date]) ? $bday[$date] : array()
			);
		}
	}

	// Set the previous and the next month's links.
	$calendarGrid['previous_calendar']['href'] = getUrl('action', ['action' => 'calendar', 'year' => $calendarGrid['previous_calendar']['year'], 'month' => $calendarGrid['previous_calendar']['month']]);
	$calendarGrid['next_calendar']['href'] = getUrl('action', ['action' => 'calendar', 'year' => $calendarGrid['next_calendar']['year'], 'month' => $calendarGrid['next_calendar']['month']]);

	return $calendarGrid;
}

/**
 * Returns the information needed to show a calendar for the given week.
 *
 * @param int $month
 * @param int $year
 * @param int $day
 * @param mixed[] $calendarOptions
 * @return array
 * @package Calendar
 */
function getCalendarWeek($month, $year, $day, $calendarOptions)
{
	global $modSettings;

	// Get todays date.
	$today = getTodayInfo();

	// What is the actual "start date" for the passed day.
	$calendarOptions['start_day'] = empty($calendarOptions['start_day']) ? 0 : (int) $calendarOptions['start_day'];
	$day_of_week = (int) Util::strftime('%w', mktime(0, 0, 0, $month, $day, $year));
	if ($day_of_week != $calendarOptions['start_day'])
	{
		// Here we offset accordingly to get things to the real start of a week.
		$date_diff = $day_of_week - $calendarOptions['start_day'];
		if ($date_diff < 0)
		{
			$date_diff += 7;
		}
		$new_timestamp = mktime(0, 0, 0, $month, $day, $year) - $date_diff * 86400;
		$day = (int) Util::strftime('%d', $new_timestamp);
		$month = (int) Util::strftime('%m', $new_timestamp);
		$year = (int) Util::strftime('%Y', $new_timestamp);
	}

	// Now start filling in the calendar grid.
	$calendarGrid = array(
		'show_next_prev' => !empty($calendarOptions['show_next_prev']),
		// Previous week is easy - just step back one day.
		'previous_week' => array(
			'year' => $day == 1 ? ($month == 1 ? $year - 1 : $year) : $year,
			'month' => $day == 1 ? ($month == 1 ? 12 : $month - 1) : $month,
			'day' => $day == 1 ? 28 : $day - 1,
			'disabled' => $day < 7 && $modSettings['cal_minyear'] > ($month == 1 ? $year - 1 : $year),
		),
		'next_week' => array(
			'disabled' => $day > 25 && date('Y') + $modSettings['cal_limityear'] < ($month == 12 ? $year + 1 : $year),
		),
	);

	// The next week calculation requires a bit more work.
	$curTimestamp = mktime(0, 0, 0, $month, $day, $year);
	$nextWeekTimestamp = $curTimestamp + 604800;
	$calendarGrid['next_week']['day'] = (int) Util::strftime('%d', $nextWeekTimestamp);
	$calendarGrid['next_week']['month'] = (int) Util::strftime('%m', $nextWeekTimestamp);
	$calendarGrid['next_week']['year'] = (int) Util::strftime('%Y', $nextWeekTimestamp);

	// Fetch the arrays for birthdays, posted events, and holidays.
	$startDate = Util::strftime('%Y-%m-%d', $curTimestamp);
	$endDate = Util::strftime('%Y-%m-%d', $nextWeekTimestamp);
	$bday = $calendarOptions['show_birthdays'] ? getBirthdayRange($startDate, $endDate) : array();
	$events = $calendarOptions['show_events'] ? getEventRange($startDate, $endDate) : array();
	$holidays = $calendarOptions['show_holidays'] ? getHolidayRange($startDate, $endDate) : array();

	// An adjustment value to apply to all calculated week numbers.
	if (!empty($calendarOptions['show_week_num']))
	{
		$first_day_of_year = (int) Util::strftime('%w', mktime(0, 0, 0, 1, 1, $year));
		$first_day_of_next_year = (int) Util::strftime('%w', mktime(0, 0, 0, 1, 1, $year + 1));
		// this one is not used in its scope
		// $last_day_of_last_year = (int) Util::strftime('%w', mktime(0, 0, 0, 12, 31, $year - 1));

		// All this is as getCalendarGrid.
		if ($calendarOptions['start_day'] === 0)
		{
			$nWeekAdjust = $first_day_of_year === 0 && $first_day_of_year > 3 ? 0 : 1;
		}
		else
		{
			$nWeekAdjust = $calendarOptions['start_day'] > $first_day_of_year && $first_day_of_year !== 0 ? 2 : 1;
		}

		$calendarGrid['week_number'] = (int) Util::strftime('%U', mktime(0, 0, 0, $month, $day, $year)) + $nWeekAdjust;

		// If this crosses a year boundary and includes january it should be week one.
		if ((int) Util::strftime('%Y', $curTimestamp + 518400) != $year && $calendarGrid['week_number'] > 53 && $first_day_of_next_year < 5)
		{
			$calendarGrid['week_number'] = 1;
		}
	}

	// This holds all the main data - there is at least one month!
	$calendarGrid['months'] = array();
	$lastDay = 99;
	$curDay = $day;
	$curDayOfWeek = $calendarOptions['start_day'];
	for ($i = 0; $i < 7; $i++)
	{
		// Have we gone into a new month (Always happens first cycle too)
		if ($lastDay > $curDay)
		{
			$curMonth = $lastDay == 99 ? $month : ($month == 12 ? 1 : $month + 1);
			$curYear = $lastDay == 99 ? $year : ($curMonth == 1 && $month == 12 ? $year + 1 : $year);
			$calendarGrid['months'][$curMonth] = array(
				'current_month' => $curMonth,
				'current_year' => $curYear,
				'days' => array(),
			);
		}

		// Add todays information to the pile!
		$date = sprintf('%04d-%02d-%02d', $curYear, $curMonth, $curDay);

		$calendarGrid['months'][$curMonth]['days'][$curDay] = array(
			'day' => $curDay,
			'day_of_week' => $curDayOfWeek,
			'date' => $date,
			'is_today' => $date == $today['date'],
			'holidays' => !empty($holidays[$date]) ? $holidays[$date] : array(),
			'events' => !empty($events[$date]) ? $events[$date] : array(),
			'birthdays' => !empty($bday[$date]) ? $bday[$date] : array()
		);

		// Make the last day what the current day is and work out what the next day is.
		$lastDay = $curDay;
		$curTimestamp += 86400;
		$curDay = (int) Util::strftime('%d', $curTimestamp);

		// Also increment the current day of the week.
		$curDayOfWeek = $curDayOfWeek >= 6 ? 0 : ++$curDayOfWeek;
	}

	// Set the previous and the next week's links.
	$calendarGrid['previous_week']['href'] = getUrl('action', ['action' => 'calendar', 'viewweek', 'year' => $calendarGrid['previous_week']['year'], 'month' => $calendarGrid['previous_week']['month'], 'day' => $calendarGrid['previous_week']['day']]);
	$calendarGrid['next_week']['href'] = getUrl('action', ['action' => 'calendar', 'viewweek', 'year' => $calendarGrid['next_week']['year'], 'month' => $calendarGrid['next_week']['month'], 'day' => $calendarGrid['next_week']['day']]);

	return $calendarGrid;
}

/**
 * Retrieve all events for the given days, independently of the users offset.
 *
 * What it does:
 *
 * - cache callback function used to retrieve the birthdays, holidays, and events between now and now + days_to_index.
 * - widens the search range by an extra 24 hours to support time offset shifts.
 * - used by the cache_getRecentEvents function to get the information needed to calculate the events taking the users time offset into account.
 *
 * @param int $days_to_index
 * @return array
 * @package Calendar
 */
function cache_getOffsetIndependentEvents($days_to_index)
{
	$low_date = Util::strftime('%Y-%m-%d', forum_time(false) - 24 * 3600);
	$high_date = Util::strftime('%Y-%m-%d', forum_time(false) + $days_to_index * 24 * 3600);

	return array(
		'data' => array(
			'holidays' => getHolidayRange($low_date, $high_date),
			'birthdays' => getBirthdayRange($low_date, $high_date),
			'events' => getEventRange($low_date, $high_date, false),
		),
		'refresh_eval' => 'return \'' . Util::strftime('%Y%m%d', forum_time(false)) . '\' != \\ElkArte\\Util::strftime(\'%Y%m%d\', forum_time(false)) || (!empty($modSettings[\'calendar_updated\']) && ' . time() . ' < $modSettings[\'calendar_updated\']);',
		'expires' => time() + 3600,
	);
}

/**
 * cache callback function used to retrieve the upcoming birthdays, holidays, and events
 * within the given period, taking into account the users time offset.
 *
 * - Called from the BoardIndex to display the current day's events on the board index
 * - used by the board index and SSI to show the upcoming events.
 *
 * @param mixed[] $eventOptions
 * @return array
 * @package Calendar
 */
function cache_getRecentEvents($eventOptions)
{
	// With the 'static' cached data we can calculate the user-specific data.
	$cached_data = Cache::instance()->quick_get('calendar_index', 'subs/Calendar.subs.php', 'cache_getOffsetIndependentEvents', array($eventOptions['num_days_shown']));

	// Get the information about today (from user perspective).
	$today = getTodayInfo();

	$return_data = array(
		'calendar_holidays' => array(),
		'calendar_birthdays' => array(),
		'calendar_events' => array(),
	);

	// Set the event span to be shown in seconds.
	$days_for_index = $eventOptions['num_days_shown'] * 86400;

	// Get the current member time/date.
	$now = forum_time();

	// Holidays between now and now + days.
	for ($i = $now; $i < $now + $days_for_index; $i += 86400)
	{
		if (isset($cached_data['holidays'][Util::strftime('%Y-%m-%d', $i)]))
		{
			$return_data['calendar_holidays'] = array_merge($return_data['calendar_holidays'], $cached_data['holidays'][Util::strftime('%Y-%m-%d', $i)]);
		}
	}

	// Happy Birthday, guys and gals!
	for ($i = $now; $i < $now + $days_for_index; $i += 86400)
	{
		$loop_date = Util::strftime('%Y-%m-%d', $i);
		if (isset($cached_data['birthdays'][$loop_date]))
		{
			foreach ($cached_data['birthdays'][$loop_date] as $index => $dummy)
			{
				$cached_data['birthdays'][Util::strftime('%Y-%m-%d', $i)][$index]['is_today'] = $loop_date === $today['date'];
			}
			$return_data['calendar_birthdays'] = array_merge($return_data['calendar_birthdays'], $cached_data['birthdays'][$loop_date]);
		}
	}

	$duplicates = array();
	for ($i = $now; $i < $now + $days_for_index; $i += 86400)
	{
		// Determine the date of the current loop step.
		$loop_date = Util::strftime('%Y-%m-%d', $i);

		// No events today? Check the next day.
		if (empty($cached_data['events'][$loop_date]))
		{
			continue;
		}

		// Loop through all events to add a few last-minute values.
		foreach ($cached_data['events'][$loop_date] as $ev => $event)
		{
			// Create a shortcut variable for easier access.
			$this_event = &$cached_data['events'][$loop_date][$ev];

			// Skip duplicates.
			if (isset($duplicates[$this_event['topic'] . $this_event['title']]))
			{
				unset($cached_data['events'][$loop_date][$ev]);
				continue;
			}
			else
			{
				$duplicates[$this_event['topic'] . $this_event['title']] = true;
			}

			// Might be set to true afterwards, depending on the permissions.
			$this_event['can_edit'] = false;
			$this_event['is_today'] = $loop_date === $today['date'];
			$this_event['date'] = $loop_date;
		}

		if (!empty($cached_data['events'][$loop_date]))
		{
			$return_data['calendar_events'] = array_merge($return_data['calendar_events'], $cached_data['events'][$loop_date]);
		}
	}

	// Mark the last item so that a list separator can be used in the template.
	for ($i = 0, $n = count($return_data['calendar_birthdays']); $i < $n; $i++)
	{
		$return_data['calendar_birthdays'][$i]['is_last'] = !isset($return_data['calendar_birthdays'][$i + 1]);
	}
	for ($i = 0, $n = count($return_data['calendar_events']); $i < $n; $i++)
	{
		$return_data['calendar_events'][$i]['is_last'] = !isset($return_data['calendar_events'][$i + 1]);
	}

	return array(
		'data' => $return_data,
		'expires' => time() + 3600,
		'refresh_eval' => 'return \'' . Util::strftime('%Y%m%d', forum_time(false)) . '\' != \\ElkArte\\Util::strftime(\'%Y%m%d\', forum_time(false)) || (!empty($modSettings[\'calendar_updated\']) && ' . time() . ' < $modSettings[\'calendar_updated\']);',
		'post_retri_eval' => '
			require_once(SUBSDIR . \'/Calendar.subs.php\');
			return cache_getRecentEvents_post_retri_eval($cache_block, $params);',
	);
}

/**
 * Refines the data retrieved from the cache for the cache_getRecentEvents function.
 *
 * @param mixed[] $cache_block
 * @param mixed[] $params
 * @package Calendar
 */
function cache_getRecentEvents_post_retri_eval(&$cache_block, $params)
{
	foreach ($cache_block['data']['calendar_events'] as $k => $event)
	{
		// Remove events that the user may not see or wants to ignore.
		if ((count(array_intersect(User::$info->groups, $event['allowed_groups'])) === 0 && !allowedTo('admin_forum') && !empty($event['id_board'])) || in_array($event['id_board'], User::$info->ignoreboards))
		{
			unset($cache_block['data']['calendar_events'][$k]);
		}
		else
		{
			// Whether the event can be edited depends on the permissions.
			$cache_block['data']['calendar_events'][$k]['can_edit'] = allowedTo('calendar_edit_any') || ($event['poster'] == User::$info->id && allowedTo('calendar_edit_own'));

			if ($event['topic'] == 0)
			{
				$modify_href = ['action' => 'calendar', 'sa' => 'post', 'eventid' => $event['id'], '{session_data}'];
			}
			else
			{
				$modify_href = [
					'action' => 'post',
					'msg' => $event['msg'],
					'topic' => $event['topic'] . '.0',
					'calendar',
					'eventid' => $event['id'],
					'{session_data}'
				];
			}
			// The added session code makes this URL not cachable.
			$cache_block['data']['calendar_events'][$k]['modify_href'] = getUrl('action', $modify_href);
		}
	}

	if (empty($params[0]['include_holidays']))
	{
		$cache_block['data']['calendar_holidays'] = array();
	}

	if (empty($params[0]['include_birthdays']))
	{
		$cache_block['data']['calendar_birthdays'] = array();
	}

	if (empty($params[0]['include_events']))
	{
		$cache_block['data']['calendar_events'] = array();
	}

	$cache_block['data']['show_calendar'] = !empty($cache_block['data']['calendar_holidays']) || !empty($cache_block['data']['calendar_birthdays']) || !empty($cache_block['data']['calendar_events']);
}

/**
 * Get the event's poster.
 *
 * @param int $event_id
 * @return int|bool the id of the poster or false if the event was not found
 * @package Calendar
 */
function getEventPoster($event_id)
{
	$db = database();

	// A simple database query, how hard can that be?
	$request = $db->query('', '
		SELECT 
			id_member
		FROM {db_prefix}calendar
		WHERE id_event = {int:id_event}
		LIMIT 1',
		array(
			'id_event' => $event_id,
		)
	);

	// No results, return false.
	if ($request->num_rows() === 0)
	{
		return false;
	}

	// Grab the results and return.
	list ($poster) = $request->fetch_row();
	$request->free_result();

	return (int) $poster;
}

/**
 * Inserts events in to the calendar
 *
 * What it does:
 *
 * - Consolidating the various INSERT statements into this function.
 * - inserts the passed event information into the calendar table.
 * - allows to either set a time span (in days) or an end_date.
 * - does not check any permissions of any sort.
 *
 * @param mixed[] $eventOptions
 * @package Calendar
 */
function insertEvent(&$eventOptions)
{
	$db = database();

	// Add special chars to the title.
	$eventOptions['title'] = Util::htmlspecialchars($eventOptions['title'], ENT_QUOTES);

	// Add some sanity checking to the span.
	$eventOptions['span'] = isset($eventOptions['span']) && $eventOptions['span'] > 0 ? (int) $eventOptions['span'] : 0;

	// Make sure the start date is in ISO order.
	$year = '';
	$month = '';
	$day = '';
	if (($num_results = sscanf($eventOptions['start_date'], '%d-%d-%d', $year, $month, $day)) !== 3)
	{
		trigger_error('insertEvent(): invalid start date format given', E_USER_ERROR);
	}

	// Set the end date (if not yet given)
	if (!isset($eventOptions['end_date']))
	{
		$eventOptions['end_date'] = Util::strftime('%Y-%m-%d', mktime(0, 0, 0, $month, $day, $year) + $eventOptions['span'] * 86400);
	}

	// If no topic and board are given, they are not linked to a topic.
	$eventOptions['id_board'] = isset($eventOptions['id_board']) ? (int) $eventOptions['id_board'] : 0;
	$eventOptions['id_topic'] = isset($eventOptions['id_topic']) ? (int) $eventOptions['id_topic'] : 0;

	$event_columns = array(
		'id_board' => 'int', 'id_topic' => 'int', 'title' => 'string-60', 'id_member' => 'int',
		'start_date' => 'date', 'end_date' => 'date',
	);
	$event_parameters = array(
		$eventOptions['id_board'], $eventOptions['id_topic'], $eventOptions['title'], $eventOptions['member'],
		$eventOptions['start_date'], $eventOptions['end_date'],
	);

	call_integration_hook('integrate_create_event', array(&$eventOptions, &$event_columns, &$event_parameters));

	// Insert the event!
	$db->insert('',
		'{db_prefix}calendar',
		$event_columns,
		$event_parameters,
		array('id_event')
	);

	// Store the just inserted id_event for future reference.
	$eventOptions['id'] = $db->insert_id('{db_prefix}calendar');

	// Update the settings to show something calendarish was updated.
	updateSettings(array(
		'calendar_updated' => time(),
	));
}

/**
 * Modifies an event.
 *
 * - allows to either set a time span (in days) or an end_date.
 * - does not check any permissions of any sort.
 *
 * @param int $event_id
 * @param mixed[] $eventOptions
 * @package Calendar
 */
function modifyEvent($event_id, &$eventOptions)
{
	$db = database();

	// Properly sanitize the title.
	$eventOptions['title'] = Util::htmlspecialchars($eventOptions['title'], ENT_QUOTES);

	// Scan the start date for validity and get its components.
	$year = '';
	$month = '';
	$day = '';
	if (($num_results = sscanf($eventOptions['start_date'], '%d-%d-%d', $year, $month, $day)) !== 3)
	{
		trigger_error('modifyEvent(): invalid start date format given', E_USER_ERROR);
	}

	// Default span to 0 days.
	$eventOptions['span'] = isset($eventOptions['span']) ? (int) $eventOptions['span'] : 0;

	// Set the end date to the start date + span (if the end date wasn't already given).
	if (!isset($eventOptions['end_date']))
	{
		$eventOptions['end_date'] = Util::strftime('%Y-%m-%d', mktime(0, 0, 0, $month, $day, $year) + $eventOptions['span'] * 86400);
	}

	$event_columns = array(
		'start_date' => 'start_date = {date:start_date}',
		'end_date' => 'end_date = {date:end_date}',
		'title' => 'title = SUBSTRING({string:title}, 1, 60)',
		'id_board' => 'id_board = {int:id_board}',
		'id_topic' => 'id_topic = {int:id_topic}'
	);

	call_integration_hook('integrate_modify_event', array($event_id, &$eventOptions, &$event_columns));

	$eventOptions['id_event'] = $event_id;

	$to_update = array();
	foreach ($event_columns as $key => $value)
	{
		if (isset($eventOptions[$key]))
		{
			$to_update[] = $value;
		}
	}

	if (empty($to_update))
	{
		return;
	}

	$db->query('', '
		UPDATE {db_prefix}calendar
		SET
			' . implode(', ', $to_update) . '
		WHERE id_event = {int:id_event}',
		$eventOptions
	);

	updateSettings(array(
		'calendar_updated' => time(),
	));
}

/**
 * Remove an event
 *
 * - does no permission checks.
 *
 * @param int $event_id
 * @package Calendar
 */
function removeEvent($event_id)
{
	$db = database();

	$db->query('', '
		DELETE FROM {db_prefix}calendar
		WHERE id_event = {int:id_event}',
		array(
			'id_event' => $event_id,
		)
	);

	call_integration_hook('integrate_remove_event', array($event_id));

	updateSettings(array(
		'calendar_updated' => time(),
	));
}

/**
 * Gets all the events properties
 *
 * @param int $event_id
 * @param bool $calendar_only
 * @return mixed[]|bool
 * @package Calendar
 */
function getEventProperties($event_id, $calendar_only = false)
{
	$db = database();

	$request = $db->query('', '
		SELECT
			c.id_event, c.id_board, c.id_topic, MONTH(c.start_date) AS month,
			DAYOFMONTH(c.start_date) AS day, YEAR(c.start_date) AS year,
			(TO_DAYS(c.end_date) - TO_DAYS(c.start_date)) AS span, c.id_member, c.title' . ($calendar_only ? '' : ',
			t.id_first_msg, t.id_member_started,
			mb.real_name, m.modified_time') . '
		FROM {db_prefix}calendar AS c' . ($calendar_only ? '' : '
			LEFT JOIN {db_prefix}topics AS t ON (t.id_topic = c.id_topic)
			LEFT JOIN {db_prefix}members AS mb ON (mb.id_member = t.id_member_started)
			LEFT JOIN {db_prefix}messages AS m ON (m.id_msg  = t.id_first_msg)') . '
		WHERE c.id_event = {int:id_event}
		LIMIT 1',
		array(
			'id_event' => $event_id,
		)
	);
	// If nothing returned, we are in poo, poo.
	if ($request->num_rows() === 0)
	{
		return false;
	}
	$row = $request->fetch_assoc();
	$request->free_result();

	if ($calendar_only)
	{
		$return_value = $row;
	}
	else
	{
		$return_value = array(
			'boards' => array(),
			'board' => $row['id_board'],
			'new' => 0,
			'eventid' => $event_id,
			'year' => $row['year'],
			'month' => $row['month'],
			'day' => $row['day'],
			'title' => $row['title'],
			'span' => 1 + $row['span'],
			'member' => $row['id_member'],
			'realname' => $row['real_name'],
			'sequence' => $row['modified_time'],
			'topic' => array(
				'id' => $row['id_topic'],
				'member_started' => $row['id_member_started'],
				'first_msg' => $row['id_first_msg'],
			),
		);

		$return_value['last_day'] = (int) Util::strftime('%d', mktime(0, 0, 0, $return_value['month'] == 12 ? 1 : $return_value['month'] + 1, 0, $return_value['month'] == 12 ? $return_value['year'] + 1 : $return_value['year']));
	}

	return $return_value;
}

/**
 * Fetch and event that may be linked to a topic
 *
 * @param int $id_topic
 *
 * @return array
 * @package Calendar
 *
 */
function eventInfoForTopic($id_topic)
{
	$db = database();

	// Get event for this topic. If we have one.
	return $db->fetchQuery('
		SELECT 
			cal.id_event, cal.start_date, cal.end_date, cal.title, cal.id_member, mem.real_name
		FROM {db_prefix}calendar AS cal
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = cal.id_member)
		WHERE cal.id_topic = {int:current_topic}
		ORDER BY start_date',
		array(
			'current_topic' => $id_topic,
		)
	)->fetch_all();
}

/**
 * Gets all of the holidays for the listing
 *
 * @param int $start The item to start with (for pagination purposes)
 * @param int $items_per_page The number of items to show per page
 * @param string $sort A string indicating how to sort the results
 * @return array
 * @package Calendar
 */
function list_getHolidays($start, $items_per_page, $sort)
{
	$db = database();

	return $db->fetchQuery('
		SELECT 
			id_holiday, YEAR(event_date) AS year, MONTH(event_date) AS month, DAYOFMONTH(event_date) AS day, title
		FROM {db_prefix}calendar_holidays
		ORDER BY {raw:sort}
		LIMIT ' . $items_per_page . ' OFFSET ' . $start,
		array(
			'sort' => $sort,
		)
	)->fetch_all();
}

/**
 * Helper function to get the total number of holidays
 *
 * @return int
 * @package Calendar
 */
function list_getNumHolidays()
{
	$db = database();

	$request = $db->query('', '
		SELECT 
			COUNT(*)
		FROM {db_prefix}calendar_holidays',
		array()
	);
	list ($num_items) = $request->fetch_row();
	$request->free_result();

	return (int) $num_items;
}

/**
 * Remove a holiday from the calendar.
 *
 * @param int|int[] $holiday_ids An array of ids for holidays.
 * @package Calendar
 */
function removeHolidays($holiday_ids)
{
	$db = database();

	if (!is_array($holiday_ids))
	{
		$holiday_ids = array($holiday_ids);
	}

	$db->query('', '
		DELETE FROM {db_prefix}calendar_holidays
		WHERE id_holiday IN ({array_int:id_holiday})',
		array(
			'id_holiday' => $holiday_ids,
		)
	);

	updateSettings(array(
		'calendar_updated' => time(),
	));
}

/**
 * Updates a calendar holiday
 *
 * @param int $holiday
 * @param int $date
 * @param string $title
 * @package Calendar
 */
function editHoliday($holiday, $date, $title)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}calendar_holidays
		SET 
			event_date = {date:holiday_date}, title = {string:holiday_title}
		WHERE id_holiday = {int:selected_holiday}',
		array(
			'holiday_date' => $date,
			'selected_holiday' => $holiday,
			'holiday_title' => $title,
		)
	);

	updateSettings(array(
		'calendar_updated' => time(),
	));
}

/**
 * Insert a new holiday
 *
 * @param int $date
 * @param string $title
 * @package Calendar
 */
function insertHoliday($date, $title)
{
	$db = database();

	$db->insert('',
		'{db_prefix}calendar_holidays',
		array(
			'event_date' => 'date', 'title' => 'string-60',
		),
		array(
			$date, $title,
		),
		array('id_holiday')
	);

	updateSettings(array(
		'calendar_updated' => time(),
	));
}

/**
 * Get a specific holiday
 *
 * @param int $id_holiday
 * @return array
 * @package Calendar
 */
function getHoliday($id_holiday)
{
	$db = database();

	$db->fetchQuery('
		SELECT 
			id_holiday, YEAR(event_date) AS year, MONTH(event_date) AS month, DAYOFMONTH(event_date) AS day, title
		FROM {db_prefix}calendar_holidays
		WHERE id_holiday = {int:selected_holiday}
		LIMIT 1',
		array(
			'selected_holiday' => $id_holiday,
		)
	)->fetch_callback(
		function ($row) use (&$holiday) {
			$holiday = array(
				'id' => $row['id_holiday'],
				'day' => $row['day'],
				'month' => $row['month'],
				'year' => $row['year'] <= 4 ? 0 : $row['year'],
				'title' => $row['title']
			);
		}
	);

	return $holiday;
}

/**
 * Puts together the content of an ical thing
 *
 * @param mixed[] $event - An array holding event details like:
 *                  - long
 *                  - year
 *                  - month
 *                  - day
 *                  - span
 *                  - realname
 *                  - sequence
 *                  - eventid
 *
 * @return string
 */
function build_ical_content($event)
{
	global $webmaster_email, $mbname;

	// Check the title isn't too long - iCal requires some formatting if so.
	$title = str_split($event['title'], 30);
	foreach ($title as $id => $line)
	{
		if ($id != 0)
		{
			$title[$id] = ' ' . $title[$id];
		}
		$title[$id] .= "\n";
	}

	// Format the dates.
	$datestamp = date('Ymd\THis\Z', time());
	$datestart = $event['year'] . ($event['month'] < 10 ? '0' . $event['month'] : $event['month']) . ($event['day'] < 10 ? '0' . $event['day'] : $event['day']);

	// Do we have a event that spans several days?
	if ($event['span'] > 1)
	{
		$dateend = strtotime($event['year'] . '-' . ($event['month'] < 10 ? '0' . $event['month'] : $event['month']) . '-' . ($event['day'] < 10 ? '0' . $event['day'] : $event['day']));
		$dateend += ($event['span'] - 1) * 86400;
		$dateend = date('Ymd', $dateend);
	}

	// This is what we will be sending later
	$filecontents = '';
	$filecontents .= 'BEGIN:VCALENDAR' . "\n";
	$filecontents .= 'METHOD:PUBLISH' . "\n";
	$filecontents .= 'PRODID:-//ElkArteCommunity//ElkArte ' . (!defined('FORUM_VERSION') ? 2.0 : strtr(FORUM_VERSION, array('ElkArte ' => ''))) . '//EN' . "\n";
	$filecontents .= 'VERSION:2.0' . "\n";
	$filecontents .= 'BEGIN:VEVENT' . "\n";
	$filecontents .= 'ORGANIZER;CN="' . $event['realname'] . '":MAILTO:' . $webmaster_email . "\n";
	$filecontents .= 'DTSTAMP:' . $datestamp . "\n";
	$filecontents .= 'DTSTART;VALUE=DATE:' . $datestart . "\n";

	// more than one day
	if ($event['span'] > 1)
	{
		$filecontents .= 'DTEND;VALUE=DATE:' . $dateend . "\n";
	}

	// event has changed? advance the sequence for this UID
	if ($event['sequence'] > 0)
	{
		$filecontents .= 'SEQUENCE:' . $event['sequence'] . "\n";
	}

	$filecontents .= 'SUMMARY:' . implode('', $title);
	$filecontents .= 'UID:' . $event['eventid'] . '@' . str_replace(' ', '-', $mbname) . "\n";
	$filecontents .= 'END:VEVENT' . "\n";
	$filecontents .= 'END:VCALENDAR';

	return $filecontents;
}
