<?php

/**
 * This file handles tasks related to personal messages. It performs all
 * the necessary (database updates, statistics updates) to add, delete, mark
 * etc personal messages.
 *
 * The functions in this file do NOT check permissions.
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
use ElkArte\Languages\Txt;
use ElkArte\User;
use ElkArte\Util;

/**
 * Loads information about the users personal message limit.
 *
 * @package PersonalMessage
 */
function loadMessageLimit()
{
	$db = database();

	$message_limit = 0;
	if (User::$info->is_admin)
	{
		$message_limit = 0;
	}
	elseif (!Cache::instance()->getVar($message_limit, 'msgLimit:' . User::$info->id, 360))
	{
		$db->fetchQuery('
			SELECT
				MAX(max_messages) AS top_limit, MIN(max_messages) AS bottom_limit
			FROM {db_prefix}membergroups
			WHERE id_group IN ({array_int:users_groups})',
			array(
				'users_groups' => User::$info->groups,
			)
		)->fetch_callback(
			function ($row) use (&$message_limit) {
				$message_limit = $row['top_limit'] == 0 ? 0 : $row['bottom_limit'];
			}
		);

		// Save us doing it again!
		Cache::instance()->put('msgLimit:' . User::$info->id, $message_limit, 360);
	}

	return $message_limit;
}

/**
 * Loads the count of messages on a per label basis.
 *
 * @param $labels mixed[] array of labels that we are calculating the message count
 *
 * @return mixed[]
 * @package PersonalMessage
 */
function loadPMLabels($labels)
{
	$db = database();

	// Looks like we need to reseek!
	$db->fetchQuery('
		SELECT
			labels, is_read, COUNT(*) AS num
		FROM {db_prefix}pm_recipients
		WHERE id_member = {int:current_member}
			AND deleted = {int:not_deleted}
		GROUP BY labels, is_read',
		array(
			'current_member' => User::$info->id,
			'not_deleted' => 0,
		)
	)->fetch_callback(
		function ($row) use (&$labels) {
			$this_labels = explode(',', $row['labels']);
			foreach ($this_labels as $this_label)
			{
				$labels[(int) $this_label]['messages'] += $row['num'];

				if (!($row['is_read'] & 1))
				{
					$labels[(int) $this_label]['unread_messages'] += $row['num'];
				}
			}
		}
	);

	// Store it please!
	Cache::instance()->put('labelCounts:' . User::$info->id, $labels, 720);

	return $labels;
}

/**
 * Get the number of PMs.
 *
 * @param bool $descending
 * @param int|null $pmID
 * @param string $labelQuery
 * @return int
 * @package PersonalMessage
 */
function getPMCount($descending = false, $pmID = null, $labelQuery = '')
{
	global $context;

	$db = database();

	// Figure out how many messages there are.
	if ($context['folder'] == 'sent')
	{
		$request = $db->fetchQuery('
			SELECT
				COUNT(' . ($context['display_mode'] == 2 ? 'DISTINCT id_pm_head' : '*') . ')
			FROM {db_prefix}personal_messages
			WHERE id_member_from = {int:current_member}
				AND deleted_by_sender = {int:not_deleted}' . ($pmID !== null ? '
				AND id_pm ' . ($descending ? '>' : '<') . ' {int:id_pm}' : ''),
			array(
				'current_member' => User::$info->id,
				'not_deleted' => 0,
				'id_pm' => $pmID,
			)
		);
	}
	else
	{
		$request = $db->fetchQuery('
			SELECT
				COUNT(' . ($context['display_mode'] == 2 ? 'DISTINCT pm.id_pm_head' : '*') . ')
			FROM {db_prefix}pm_recipients AS pmr' . ($context['display_mode'] == 2 ? '
				INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)' : '') . '
			WHERE pmr.id_member = {int:current_member}
				AND pmr.deleted = {int:not_deleted}' . $labelQuery . ($pmID !== null ? '
				AND pmr.id_pm ' . ($descending ? '>' : '<') . ' {int:id_pm}' : ''),
			array(
				'current_member' => User::$info->id,
				'not_deleted' => 0,
				'id_pm' => $pmID,
			)
		);
	}

	list ($count) = $request->fetch_row();
	$request->free_result();

	return (int) $count;
}

/**
 * Delete the specified personal messages.
 *
 * @param int[]|null $personal_messages array of pm ids
 * @param string|null $folder = null
 * @param int|int[]|null $owner = null
 * @package PersonalMessage
 */
function deleteMessages($personal_messages, $folder = null, $owner = null)
{
	$db = database();

	if ($owner === null)
	{
		$owner = array(User::$info->id);
	}
	elseif (empty($owner))
	{
		return;
	}
	elseif (!is_array($owner))
	{
		$owner = array($owner);
	}

	if ($personal_messages !== null)
	{
		if (empty($personal_messages) || !is_array($personal_messages))
		{
			return;
		}

		foreach ($personal_messages as $index => $delete_id)
		{
			$personal_messages[$index] = (int) $delete_id;
		}

		$where = '
				AND id_pm IN ({array_int:pm_list})';
	}
	else
	{
		$where = '';
	}

	if ($folder === 'sent' || $folder === null)
	{
		$db->query('', '
			UPDATE {db_prefix}personal_messages
			SET 
				deleted_by_sender = {int:is_deleted}
			WHERE id_member_from IN ({array_int:member_list})
				AND deleted_by_sender = {int:not_deleted}' . $where,
			array(
				'member_list' => $owner,
				'is_deleted' => 1,
				'not_deleted' => 0,
				'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
			)
		);
	}

	if ($folder !== 'sent')
	{
		require_once(SUBSDIR . '/Members.subs.php');

		// Calculate the number of messages each member's gonna lose...
		$db->fetchQuery('
			SELECT
				id_member, COUNT(*) AS num_deleted_messages, CASE WHEN is_read & 1 >= 1 THEN 1 ELSE 0 END AS is_read
			FROM {db_prefix}pm_recipients
			WHERE id_member IN ({array_int:member_list})
				AND deleted = {int:not_deleted}' . $where . '
			GROUP BY id_member, is_read',
			array(
				'member_list' => $owner,
				'not_deleted' => 0,
				'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
			)
		)->fetch_callback(
			function ($row) use ($where) {
				// ...And update the statistics accordingly - now including unread messages!.
				if ($row['is_read'])
				{
					updateMemberData($row['id_member'], array('personal_messages' => $where == '' ? 0 : 'personal_messages - ' . $row['num_deleted_messages']));
				}
				else
				{
					updateMemberData($row['id_member'], array('personal_messages' => $where == '' ? 0 : 'personal_messages - ' . $row['num_deleted_messages'], 'unread_messages' => $where == '' ? 0 : 'unread_messages - ' . $row['num_deleted_messages']));
				}

				// If this is the current member we need to make their message count correct.
				if (User::$info->id == $row['id_member'])
				{
					User::$info->messages -= $row['num_deleted_messages'];
					if (!($row['is_read']))
					{
						User::$info->unread_messages -= $row['num_deleted_messages'];
					}
				}
			}
		);

		// Do the actual deletion.
		$db->query('', '
			UPDATE {db_prefix}pm_recipients
			SET 
				deleted = {int:is_deleted}
			WHERE id_member IN ({array_int:member_list})
				AND deleted = {int:not_deleted}' . $where,
			array(
				'member_list' => $owner,
				'is_deleted' => 1,
				'not_deleted' => 0,
				'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
			)
		);
	}

	// If sender and recipients all have deleted their message, it can be removed.
	$remove_pms = array();
	$db->fetchQuery('
		SELECT
			pm.id_pm AS sender, pmr.id_pm
		FROM {db_prefix}personal_messages AS pm
			LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm AND pmr.deleted = {int:not_deleted})
		WHERE pm.deleted_by_sender = {int:is_deleted}
			' . str_replace('id_pm', 'pm.id_pm', $where) . '
		GROUP BY sender, pmr.id_pm
		HAVING pmr.id_pm IS null',
		array(
			'not_deleted' => 0,
			'is_deleted' => 1,
			'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
		)
	)->fetch_callback(
		function ($row) use (&$remove_pms) {
			$remove_pms[] = $row['sender'];
		}
	);

	if (!empty($remove_pms))
	{
		$db->query('', '
			DELETE FROM {db_prefix}personal_messages
			WHERE id_pm IN ({array_int:pm_list})',
			array(
				'pm_list' => $remove_pms,
			)
		);

		$db->query('', '
			DELETE FROM {db_prefix}pm_recipients
			WHERE id_pm IN ({array_int:pm_list})',
			array(
				'pm_list' => $remove_pms,
			)
		);
	}

	// Any cached numbers may be wrong now.
	Cache::instance()->put('labelCounts:' . User::$info->id, null, 720);
}

/**
 * Mark the specified personal messages read.
 *
 * @param int[]|int|null $personal_messages null or array of pm ids
 * @param string|null $label = null, if label is set, only marks messages with that label
 * @param int|null $owner = null, if owner is set, marks messages owned by that member id
 * @package PersonalMessage
 */
function markMessages($personal_messages = null, $label = null, $owner = null)
{
	if ($owner === null)
	{
		$owner = User::$info->id;
	}

	if (!is_null($personal_messages) && !is_array($personal_messages))
	{
		$personal_messages = array($personal_messages);
	}

	$db = database();

	$request = $db->fetchQuery('
		UPDATE {db_prefix}pm_recipients
		SET 
			is_read = is_read | 1
		WHERE id_member = {int:id_member}
			AND NOT (is_read & 1 >= 1)' . ($label === null ? '' : '
			AND FIND_IN_SET({string:label}, labels) != 0') . ($personal_messages !== null ? '
			AND id_pm IN ({array_int:personal_messages})' : ''),
		array(
			'personal_messages' => $personal_messages,
			'id_member' => $owner,
			'label' => $label,
		)
	);

	// If something wasn't marked as read, get the number of unread messages remaining.
	if ($request->affected_rows() > 0)
	{
		updatePMMenuCounts($owner);
	}
}

/**
 * Mark the specified personal messages as unread.
 *
 * @param int|int[] $personal_messages
 * @package PersonalMessage
 */
function markMessagesUnread($personal_messages)
{
	if (empty($personal_messages))
	{
		return;
	}

	if (!is_array($personal_messages))
	{
		$personal_messages = array($personal_messages);
	}

	$db = database();

	$owner = User::$info->id;

	// Flip the "read" bit on this
	$request = $db->fetchQuery('
		UPDATE {db_prefix}pm_recipients
		SET 
			is_read = is_read & 2
		WHERE id_member = {int:id_member}
			AND (is_read & 1 >= 1)
			AND id_pm IN ({array_int:personal_messages})',
		array(
			'personal_messages' => $personal_messages,
			'id_member' => $owner,
		)
	);

	// If something was marked unread, update the number of unread messages remaining.
	if ($request->affected_rows() > 0)
	{
		updatePMMenuCounts($owner);
	}
}

/**
 * Updates the number of unread messages for a user
 *
 * - Updates the per label totals as well as the overall total
 *
 * @param int $owner
 * @package PersonalMessage
 */
function updatePMMenuCounts($owner)
{
	global $context;

	$db = database();

	if ($owner == User::$info->id)
	{
		foreach ($context['labels'] as $label)
		{
			$context['labels'][(int) $label['id']]['unread_messages'] = 0;
		}
	}

	$total_unread = 0;
	$db->fetchQuery('
		SELECT
			labels, COUNT(*) AS num
		FROM {db_prefix}pm_recipients
		WHERE id_member = {int:id_member}
			AND NOT (is_read & 1 >= 1)
			AND deleted = {int:is_not_deleted}
		GROUP BY labels',
		array(
			'id_member' => $owner,
			'is_not_deleted' => 0,
		)
	)->fetch_callback(
		function ($row) use ($context, &$total_unread, $owner) {
			$total_unread += $row['num'];

			if ($owner != User::$info->id)
			{
				return;
			}

			$this_labels = explode(',', $row['labels']);
			foreach ($this_labels as $this_label)
			{
				$context['labels'][(int) $this_label]['unread_messages'] += $row['num'];
			}
		}
	);

	// Need to store all this.
	Cache::instance()->put('labelCounts:' . $owner, $context['labels'], 720);
	require_once(SUBSDIR . '/Members.subs.php');
	updateMemberData($owner, array('unread_messages' => $total_unread));

	// If it was for the current member, reflect this in the User::$info array too.
	if ($owner == User::$info->id)
	{
		User::$info->unread_messages = $total_unread;
	}
}

/**
 * Check if the PM is available to the current user.
 *
 * @param int $pmID
 * @param string $validFor
 * @return bool|null
 * @package PersonalMessage
 */
function isAccessiblePM($pmID, $validFor = 'in_or_outbox')
{
	$db = database();

	$request = $db->fetchQuery('
		SELECT
			pm.id_member_from = {int:id_current_member} AND pm.deleted_by_sender = {int:not_deleted} AS valid_for_outbox,
			pmr.id_pm IS NOT NULL AS valid_for_inbox
		FROM {db_prefix}personal_messages AS pm
			LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm AND pmr.id_member = {int:id_current_member} AND pmr.deleted = {int:not_deleted})
		WHERE pm.id_pm = {int:id_pm}
			AND ((pm.id_member_from = {int:id_current_member} AND pm.deleted_by_sender = {int:not_deleted}) OR pmr.id_pm IS NOT NULL)',
		array(
			'id_pm' => $pmID,
			'id_current_member' => User::$info->id,
			'not_deleted' => 0,
		)
	);
	if ($request->num_rows() === 0)
	{
		$request->free_result();

		return false;
	}
	$validationResult = $request->fetch_assoc();
	$request->free_result();

	switch ($validFor)
	{
		case 'inbox':
			return !empty($validationResult['valid_for_inbox']);

		case 'outbox':
			return !empty($validationResult['valid_for_outbox']);

		case 'in_or_outbox':
			return !empty($validationResult['valid_for_inbox']) || !empty($validationResult['valid_for_outbox']);

		default:
			trigger_error('Undefined validation type given', E_USER_ERROR);
	}
}

/**
 * Sends a personal message from the specified person to the specified people
 * ($from defaults to the user)
 *
 * @param mixed[] $recipients - an array containing the arrays 'to' and 'bcc', both containing id_member's.
 * @param string $subject - should have no slashes and no html entities
 * @param string $message - should have no slashes and no html entities
 * @param bool $store_outbox
 * @param mixed[]|null $from - an array with the id, name, and username of the member.
 * @param int $pm_head - the ID of the chain being replied to - if any.
 * @return mixed[] an array with log entries telling how many recipients were successful and which recipients it failed to send to.
 * @package PersonalMessage
 */
function sendpm($recipients, $subject, $message, $store_outbox = true, $from = null, $pm_head = 0)
{
	global $scripturl, $txt, $language, $modSettings, $webmaster_email;

	$db = database();

	// Make sure the PM language file is loaded, we might need something out of it.
	Txt::load('PersonalMessage');

	// Needed for our email and post functions
	require_once(SUBSDIR . '/Mail.subs.php');
	require_once(SUBSDIR . '/Post.subs.php');

	// Initialize log array.
	$log = array(
		'failed' => array(),
		'sent' => array()
	);

	if ($from === null)
	{
		$from = array(
			'id' => User::$info->id,
			'name' => User::$info->name,
			'username' => User::$info->username
		);
	}
	// Probably not needed.  /me something should be of the typer.
	else
	{
		User::$info->name = $from['name'];
	}

	// Integrated PMs
	call_integration_hook('integrate_personal_message', array(&$recipients, &$from, &$subject, &$message));

	// This is the one that will go in their inbox.
	$htmlmessage = Util::htmlspecialchars($message, ENT_QUOTES, 'UTF-8', true);
	preparsecode($htmlmessage);
	$htmlsubject = strtr(Util::htmlspecialchars($subject), array("\r" => '', "\n" => '', "\t" => ''));
	if (Util::strlen($htmlsubject) > 100)
	{
		$htmlsubject = Util::substr($htmlsubject, 0, 100);
	}

	// Make sure is an array
	if (!is_array($recipients))
	{
		$recipients = array($recipients);
	}

	// Get a list of usernames and convert them to IDs.
	$usernames = array();
	foreach ($recipients as $rec_type => $rec)
	{
		foreach ($rec as $id => $member)
		{
			if (!is_numeric($recipients[$rec_type][$id]))
			{
				$recipients[$rec_type][$id] = Util::strtolower(trim(preg_replace('/[<>&"\'=\\\]/', '', $recipients[$rec_type][$id])));
				$usernames[$recipients[$rec_type][$id]] = 0;
			}
		}
	}

	if (!empty($usernames))
	{
		$request = $db->fetchQuery('
			SELECT
				id_member, member_name
			FROM {db_prefix}members
			WHERE {column_case_insensitive:member_name} IN ({array_string_case_insensitive:usernames})',
			array(
				'usernames' => array_keys($usernames),
			)
		);
		while (($row = $request->fetch_assoc()))
		{
			if (isset($usernames[Util::strtolower($row['member_name'])]))
			{
				$usernames[Util::strtolower($row['member_name'])] = $row['id_member'];
			}
		}
		$request->free_result();

		// Replace the usernames with IDs. Drop usernames that couldn't be found.
		foreach ($recipients as $rec_type => $rec)
		{
			foreach ($rec as $id => $member)
			{
				if (is_numeric($recipients[$rec_type][$id]))
				{
					continue;
				}

				if (!empty($usernames[$member]))
				{
					$recipients[$rec_type][$id] = $usernames[$member];
				}
				else
				{
					$log['failed'][$id] = sprintf($txt['pm_error_user_not_found'], $recipients[$rec_type][$id]);
					unset($recipients[$rec_type][$id]);
				}
			}
		}
	}

	// Make sure there are no duplicate 'to' members.
	$recipients['to'] = array_unique($recipients['to']);

	// Only 'bcc' members that aren't already in 'to'.
	$recipients['bcc'] = array_diff(array_unique($recipients['bcc']), $recipients['to']);

	// Combine 'to' and 'bcc' recipients.
	$all_to = array_merge($recipients['to'], $recipients['bcc']);

	// Check no-one will want it deleted right away!
	$deletes = array();
	$db->fetchQuery('
		SELECT
			id_member, criteria, is_or
		FROM {db_prefix}pm_rules
		WHERE id_member IN ({array_int:to_members})
			AND delete_pm = {int:delete_pm}',
		array(
			'to_members' => $all_to,
			'delete_pm' => 1,
		)
	)->fetch_callback(
		function ($row) use (&$deletes, $from, $subject, $message) {
			// Check whether we have to apply anything...
			$criteria = Util::unserialize($row['criteria']);

			// Note we don't check the buddy status, cause deletion from buddy = madness!
			$delete = false;
			foreach ($criteria as $criterium)
			{
				if (($criterium['t'] === 'mid' && $criterium['v'] == $from['id'])
					|| ($criterium['t'] === 'gid' && in_array($criterium['v'], User::$info->groups))
					|| ($criterium['t'] === 'sub' && strpos($subject, $criterium['v']) !== false)
					|| ($criterium['t'] === 'msg' && strpos($message, $criterium['v']) !== false))
				{
					$delete = true;
				}
				// If we're adding and one criteria don't match then we stop!
				elseif (!$row['is_or'])
				{
					$delete = false;
					break;
				}
			}
			if ($delete)
			{
				$deletes[$row['id_member']] = 1;
			}
		}
	);

	// Load the membergroup message limits.
	static $message_limit_cache = array();
	if (!allowedTo('moderate_forum') && empty($message_limit_cache))
	{
		$db->fetchQuery('
			SELECT
				id_group, max_messages
			FROM {db_prefix}membergroups',
			array()
		)->fetch_callback(
			function ($row) use (&$message_limit_cache) {
				$message_limit_cache[$row['id_group']] = $row['max_messages'];
			}
		);
	}

	// Load the groups that are allowed to read PMs.
	// @todo move into a separate function on $permission.
	$allowed_groups = array();
	$disallowed_groups = array();
	$db->fetchQuery('
		SELECT
			id_group, add_deny
		FROM {db_prefix}permissions
		WHERE permission = {string:read_permission}',
		array(
			'read_permission' => 'pm_read',
		)
	)->fetch_callback(
		function ($row) use (&$disallowed_groups, &$allowed_groups) {
			if (empty($row['add_deny']))
			{
				$disallowed_groups[] = $row['id_group'];
			}
			else
			{
				$allowed_groups[] = $row['id_group'];
			}
		}
	);

	if (empty($modSettings['permission_enable_deny']))
	{
		$disallowed_groups = array();
	}

	$request = $db->fetchQuery('
		SELECT
			member_name, real_name, id_member, email_address, lngfile,
			pm_email_notify, personal_messages,' . (allowedTo('moderate_forum') ? ' 0' : '
			(receive_from = {int:admins_only}' . (empty($modSettings['enable_buddylist']) ? '' : ' OR
			(receive_from = {int:buddies_only} AND FIND_IN_SET({string:from_id}, buddy_list) = 0) OR
			(receive_from = {int:not_on_ignore_list} AND FIND_IN_SET({string:from_id}, pm_ignore_list) != 0)') . ')') . ' AS ignored,
			FIND_IN_SET({string:from_id}, buddy_list) != 0 AS is_buddy, is_activated,
			additional_groups, id_group, id_post_group
		FROM {db_prefix}members
		WHERE id_member IN ({array_int:recipients})
		ORDER BY lngfile
		LIMIT {int:count_recipients}',
		array(
			'not_on_ignore_list' => 1,
			'buddies_only' => 2,
			'admins_only' => 3,
			'recipients' => $all_to,
			'count_recipients' => count($all_to),
			'from_id' => $from['id'],
		)
	);
	$notifications = array();
	while (($row = $request->fetch_assoc()))
	{
		// Don't do anything for members to be deleted!
		if (isset($deletes[$row['id_member']]))
		{
			continue;
		}

		// We need to know this members groups.
		$groups = explode(',', $row['additional_groups']);
		$groups[] = $row['id_group'];
		$groups[] = $row['id_post_group'];

		$message_limit = -1;

		// For each group see whether they've gone over their limit - assuming they're not an admin.
		if (!in_array(1, $groups))
		{
			foreach ($groups as $id)
			{
				if (isset($message_limit_cache[$id]) && $message_limit != 0 && $message_limit < $message_limit_cache[$id])
				{
					$message_limit = $message_limit_cache[$id];
				}
			}

			if ($message_limit > 0 && $message_limit <= $row['personal_messages'])
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_data_limit_reached'], $row['real_name']);
				unset($all_to[array_search($row['id_member'], $all_to)]);
				continue;
			}

			// Do they have any of the allowed groups?
			if (count(array_intersect($allowed_groups, $groups)) == 0 || count(array_intersect($disallowed_groups, $groups)) != 0)
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
				unset($all_to[array_search($row['id_member'], $all_to)]);
				continue;
			}
		}

		// Note that PostgreSQL can return a lowercase t/f for FIND_IN_SET
		if (!empty($row['ignored']) && $row['ignored'] != 'f' && $row['id_member'] != $from['id'])
		{
			$log['failed'][$row['id_member']] = sprintf($txt['pm_error_ignored_by_user'], $row['real_name']);
			unset($all_to[array_search($row['id_member'], $all_to)]);
			continue;
		}

		// If the receiving account is banned (>=10) or pending deletion (4), refuse to send the PM.
		if ($row['is_activated'] >= 10 || ($row['is_activated'] == 4 && User::$info->is_admin === false))
		{
			$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
			unset($all_to[array_search($row['id_member'], $all_to)]);
			continue;
		}

		// Send a notification, if enabled - taking the buddy list into account.
		if (!empty($row['email_address']) && ($row['pm_email_notify'] == 1 || ($row['pm_email_notify'] > 1 && (!empty($modSettings['enable_buddylist']) && $row['is_buddy']))) && $row['is_activated'] == 1)
		{
			$notifications[empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile']][] = $row['email_address'];
		}

		$log['sent'][$row['id_member']] = sprintf($txt['pm_successfully_sent'] ?? '', $row['real_name']);
	}
	$request->free_result();

	// Only 'send' the message if there are any recipients left.
	if (empty($all_to))
	{
		return $log;
	}

	// Track the pm count for our stats
	if (!empty($modSettings['trackStats']))
	{
		trackStats(array('pm' => '+'));
	}

	// Insert the message itself and then grab the last insert id.
	$db->insert('',
		'{db_prefix}personal_messages',
		array(
			'id_pm_head' => 'int', 'id_member_from' => 'int', 'deleted_by_sender' => 'int',
			'from_name' => 'string-255', 'msgtime' => 'int', 'subject' => 'string-255', 'body' => 'string-65534',
		),
		array(
			$pm_head, $from['id'], ($store_outbox ? 0 : 1),
			$from['username'], time(), $htmlsubject, $htmlmessage,
		),
		array('id_pm')
	);
	$id_pm = $db->insert_id('{db_prefix}personal_messages');

	// Add the recipients.
	$to_list = array();
	if (!empty($id_pm))
	{
		// If this is new we need to set it part of it's own conversation.
		if (empty($pm_head))
		{
			$db->query('', '
				UPDATE {db_prefix}personal_messages
				SET 
					id_pm_head = {int:id_pm_head}
				WHERE id_pm = {int:id_pm_head}',
				array(
					'id_pm_head' => $id_pm,
				)
			);
		}

		// Some people think manually deleting personal_messages is fun... it's not. We protect against it though :)
		$db->query('', '
			DELETE FROM {db_prefix}pm_recipients
			WHERE id_pm = {int:id_pm}',
			array(
				'id_pm' => $id_pm,
			)
		);

		$insertRows = array();
		foreach ($all_to as $to)
		{
			$insertRows[] = array($id_pm, $to, in_array($to, $recipients['bcc']) ? 1 : 0, isset($deletes[$to]) ? 1 : 0, 1);
			if (!in_array($to, $recipients['bcc']))
			{
				$to_list[] = $to;
			}
		}

		$db->insert('insert',
			'{db_prefix}pm_recipients',
			array(
				'id_pm' => 'int', 'id_member' => 'int', 'bcc' => 'int', 'deleted' => 'int', 'is_new' => 'int'
			),
			$insertRows,
			array('id_pm', 'id_member')
		);
	}

	$maillist = !empty($modSettings['maillist_enabled']) && !empty($modSettings['pbe_pm_enabled']);

	// If they have post by email enabled, override disallow_sendBody
	if (!$maillist && !empty($modSettings['disallow_sendBody']))
	{
		$message = '';
		$subject = censor($subject);
	}
	else
	{
		require_once(SUBSDIR . '/Emailpost.subs.php');
		pbe_prepare_text($message, $subject);
	}

	$to_names = array();
	if (count($to_list) > 1)
	{
		require_once(SUBSDIR . '/Members.subs.php');
		$result = getBasicMemberData($to_list);
		foreach ($result as $row)
		{
			$to_names[] = un_htmlspecialchars($row['real_name']);
		}
	}

	$replacements = array(
		'SUBJECT' => $subject,
		'MESSAGE' => $message,
		'SENDER' => un_htmlspecialchars($from['name']),
		'READLINK' => $scripturl . '?action=pm;pmsg=' . $id_pm . '#msg' . $id_pm,
		'REPLYLINK' => $scripturl . '?action=pm;sa=send;f=inbox;pmsg=' . $id_pm . ';quote;u=' . $from['id'],
		'TOLIST' => implode(', ', $to_names),
	);

	// Select the right template
	$email_template = ($maillist && empty($modSettings['disallow_sendBody']) ? 'pbe_' : '') . 'new_pm' . (empty($modSettings['disallow_sendBody']) ? '_body' : '') . (!empty($to_names) ? '_tolist' : '');

	foreach ($notifications as $lang => $notification_list)
	{
		// Using maillist functionality
		if ($maillist)
		{
			$sender_details = query_sender_wrapper($from['id']);
			$from_wrapper = !empty($modSettings['maillist_mail_from']) ? $modSettings['maillist_mail_from'] : (empty($modSettings['maillist_sitename_address']) ? $webmaster_email : $modSettings['maillist_sitename_address']);

			// Add in the signature
			$replacements['SIGNATURE'] = $sender_details['signature'];

			// And off it goes, looking a bit more personal
			$mail = loadEmailTemplate($email_template, $replacements, $lang);
			$reference = !empty($pm_head) ? $pm_head : null;
			sendmail($notification_list, $mail['subject'], $mail['body'], $from['name'], 'p' . $id_pm, false, 2, null, true, $from_wrapper, $reference);
		}
		else
		{
			// Off the notification email goes!
			$mail = loadEmailTemplate($email_template, $replacements, $lang);
			sendmail($notification_list, $mail['subject'], $mail['body'], null, 'p' . $id_pm, false, 2, null, true);
		}
	}

	// Integrated After PMs
	call_integration_hook('integrate_personal_message_after', array(&$id_pm, &$log, &$recipients, &$from, &$subject, &$message));

	// Back to what we were on before!
	Txt::load('index+PersonalMessage');

	// Add one to their unread and read message counts.
	foreach ($all_to as $k => $id)
	{
		if (isset($deletes[$id]))
		{
			unset($all_to[$k]);
		}
	}

	if (!empty($all_to))
	{
		require_once(SUBSDIR . '/Members.subs.php');
		updateMemberData($all_to, array('personal_messages' => '+', 'unread_messages' => '+', 'new_pm' => 1));
	}

	return $log;
}

/**
 * Load personal messages.
 *
 * This function loads messages considering the options given, an array of:
 * - 'display_mode' - the PMs display mode (i.e. conversation, all)
 * - 'is_postgres' - (temporary) boolean to allow choice of PostgreSQL-specific sorting query
 * - 'sort_by_query' - query to sort by
 * - 'descending' - whether to sort descending
 * - 'sort_by' - field to sort by
 * - 'pmgs' - personal message id (if any). Note: it may not be set.
 * - 'label_query' - query by labels
 * - 'start' - start id, if any
 *
 * @param mixed[] $pm_options options for loading
 * @param int $id_member id member
 *
 * @return array
 * @package PersonalMessage
 */
function loadPMs($pm_options, $id_member)
{
	global $options;

	$db = database();

	// First work out what messages we need to see - if grouped is a little trickier...
	// Conversation mode
	if ($pm_options['display_mode'] == 2)
	{
		// On a non-default sort, when using PostgreSQL we have to do a harder sort.
		if ($db->title() === 'PostgreSQL' && $pm_options['sort_by_query'] !== 'pm.id_pm')
		{
			$sub_pms = array();
			$db->fetchQuery('
				SELECT
					MAX({raw:sort}) AS sort_param, pm.id_pm_head
				FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
					LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
					INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
						AND pmr.id_member = {int:current_member}
						AND pmr.deleted = {int:not_deleted}
						' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})') : '') . '
				WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {int:current_member}
					AND pm.deleted_by_sender = {int:not_deleted}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
					AND pm.id_pm = {int:id_pm}') . '
				GROUP BY pm.id_pm_head
				ORDER BY sort_param' . ($pm_options['descending'] ? ' DESC' : ' ASC') . (empty($pm_options['pmsg']) ? '
				LIMIT ' . $pm_options['limit'] . ' OFFSET ' . $pm_options['start'] : ''),
				array(
					'current_member' => $id_member,
					'not_deleted' => 0,
					'id_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
					'id_pm' => $pm_options['pmsg'] ?? '0',
					'sort' => $pm_options['sort_by_query'],
				)
			)->fetch_callback(
				function ($row) use (&$sub_pms) {
					$sub_pms[$row['id_pm_head']] = $row['sort_param'];
				}
			);

			// Now we use those results in the next query
			$request = $db->query('', '
				SELECT
					pm.id_pm AS id_pm, pm.id_pm_head
				FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
					LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
					INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
						AND pmr.id_member = {int:current_member}
						AND pmr.deleted = {int:not_deleted}
						' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})') : '') . '
				WHERE ' . (empty($sub_pms) ? '0=1' : 'pm.id_pm IN ({array_int:pm_list})') . '
				ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (empty($pm_options['pmsg']) ? '
				LIMIT ' . $pm_options['limit'] . ' OFFSET ' . $pm_options['start'] : ''),
				array(
					'current_member' => $id_member,
					'pm_list' => array_keys($sub_pms),
					'not_deleted' => 0,
					'sort' => $pm_options['sort_by_query'],
					'id_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
				)
			);
		}
		// Otherwise we can just use the the pm_conversation_list option
		else
		{
			$request = $db->query('pm_conversation_list', '
				SELECT
					MAX(pm.id_pm) AS id_pm, pm.id_pm_head
				FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
					LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
					INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
						AND pmr.id_member = {int:current_member}
						AND pmr.deleted = {int:deleted_by}
						' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:pm_member})') : '') . '
				WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {int:current_member}
					AND pm.deleted_by_sender = {int:deleted_by}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
					AND pm.id_pm = {int:pmsg}') . '
				GROUP BY pm.id_pm_head
				ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (isset($pm_options['pmsg']) ? '
				LIMIT ' . $pm_options['limit'] . ' OFFSET ' . $pm_options['start'] : ''),
				array(
					'current_member' => $id_member,
					'deleted_by' => 0,
					'sort' => $pm_options['sort_by_query'],
					'pm_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
					'pmsg' => isset($pm_options['pmsg']) ? (int) $pm_options['pmsg'] : 0,
				)
			);
		}
	}
	// If not in conversation view, then this is kinda simple!
	else
	{
		// @todo SLOW This query uses a filesort. (inbox only.)
		$request = $db->query('', '
			SELECT
				pm.id_pm, pm.id_pm_head, pm.id_member_from
			FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? '' . ($pm_options['sort_by'] == 'name' ? '
				LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
					AND pmr.id_member = {int:current_member}
					AND pmr.deleted = {int:is_deleted}
					' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:pm_member})') : '') . '
			WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {raw:current_member}
				AND pm.deleted_by_sender = {int:is_deleted}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
				AND pm.id_pm = {int:pmsg}') . '
			ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'pmr.id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (isset($pm_options['pmsg']) ? '
			LIMIT ' . $pm_options['limit'] . ' OFFSET ' . $pm_options['start'] : ''),
			array(
				'current_member' => $id_member,
				'is_deleted' => 0,
				'sort' => $pm_options['sort_by_query'],
				'pm_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
				'pmsg' => isset($pm_options['pmsg']) ? (int) $pm_options['pmsg'] : 0,
			)
		);
	}
	// Load the id_pms and initialize recipients.
	$pms = array();
	$lastData = array();
	$posters = $pm_options['folder'] == 'sent' ? array($id_member) : array();
	$recipients = array();
	while (($row = $request->fetch_assoc()))
	{
		if (!isset($recipients[$row['id_pm']]))
		{
			if (isset($row['id_member_from']))
			{
				$posters[$row['id_pm']] = $row['id_member_from'];
			}

			$pms[$row['id_pm']] = $row['id_pm'];

			$recipients[$row['id_pm']] = array(
				'to' => array(),
				'bcc' => array()
			);
		}

		// Keep track of the last message so we know what the head is without another query!
		if ((empty($pm_options['pmid']) && (empty($options['view_newest_pm_first']) || !isset($lastData))) || empty($lastData) || (!empty($pm_options['pmid']) && $pm_options['pmid'] == $row['id_pm']))
		{
			$lastData = array(
				'id' => $row['id_pm'],
				'head' => $row['id_pm_head'],
			);
		}
	}
	$request->free_result();

	return array($pms, $posters, $recipients, $lastData);
}

/**
 * How many PMs have you sent lately?
 *
 * @param int $id_member id member
 * @param int $time time interval (in seconds)
 *
 * @return mixed
 * @package PersonalMessage
 */
function pmCount($id_member, $time)
{
	$db = database();

	$db->fetchQuery('
		SELECT
			COUNT(*) AS post_count
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pr ON (pr.id_pm = pm.id_pm)
		WHERE pm.id_member_from = {int:current_member}
			AND pm.msgtime > {int:msgtime}',
		array(
			'current_member' => $id_member,
			'msgtime' => time() - $time,
		)
	)->fetch_callback(
		function ($row) use (&$pmCount) {
			$pmCount = $row['post_count'];
		}
	);

	return $pmCount;
}

/**
 * This will apply rules to all unread messages.
 *
 * - If all_messages is set will, clearly, do it to all!
 *
 * @param bool $all_messages = false
 * @package PersonalMessage
 */
function applyRules($all_messages = false)
{
	global $context, $options;

	$db = database();

	// Want this - duh!
	loadRules();

	// No rules?
	if (empty($context['rules']))
	{
		return;
	}

	// Just unread ones?
	$ruleQuery = $all_messages ? '' : ' AND pmr.is_new = 1';

	// @todo Apply all should have timeout protection!
	// Get all the messages that match this.
	$actions = array();
	$db->fetchQuery('
		SELECT
			pmr.id_pm, pm.id_member_from, pm.subject, pm.body, mem.id_group, pmr.labels
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
		WHERE pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}
			' . $ruleQuery,
		array(
			'current_member' => User::$info->id,
			'not_deleted' => 0,
		)
	)->fetch_callback(
		function ($row) use ($context, &$actions) {
			foreach ($context['rules'] as $rule)
			{
				$match = false;

				// Loop through all the criteria hoping to make a match.
				foreach ($rule['criteria'] as $criterium)
				{
					if (($criterium['t'] === 'mid' && $criterium['v'] == $row['id_member_from'])
						|| ($criterium['t'] === 'gid' && $criterium['v'] == $row['id_group'])
						|| ($criterium['t'] === 'sub' && strpos($row['subject'], $criterium['v']) !== false)
						|| ($criterium['t'] === 'msg' && strpos($row['body'], $criterium['v']) !== false))
					{
						$match = true;
					}
					// If we're adding and one criteria don't match then we stop!
					elseif ($rule['logic'] === 'and')
					{
						$match = false;
						break;
					}
				}

				// If we have a match the rule must be true - act!
				if ($match)
				{
					if ($rule['delete'])
					{
						$actions['deletes'][] = $row['id_pm'];
					}
					else
					{
						foreach ($rule['actions'] as $ruleAction)
						{
							if ($ruleAction['t'] === 'lab')
							{
								// Get a basic pot started!
								if (!isset($actions['labels'][$row['id_pm']]))
								{
									$actions['labels'][$row['id_pm']] = empty($row['labels'])
										? array()
										: explode(',', $row['labels']);
								}

								$actions['labels'][$row['id_pm']][] = $ruleAction['v'];
							}
						}
					}
				}
			}
		}
	);

	// Deletes are easy!
	if (!empty($actions['deletes']))
	{
		deleteMessages($actions['deletes']);
	}

	// Re-label?
	if (!empty($actions['labels']))
	{
		foreach ($actions['labels'] as $pm => $labels)
		{
			// Quickly check each label is valid!
			$realLabels = array();
			foreach ($context['labels'] as $label)
			{
				if (in_array($label['id'], $labels) && ($label['id'] != -1 || empty($options['pm_remove_inbox_label'])))
				{
					$realLabels[] = $label['id'];
				}
			}

			$db->query('', '
				UPDATE {db_prefix}pm_recipients
				SET 
					labels = {string:new_labels}
				WHERE id_pm = {int:id_pm}
					AND id_member = {int:current_member}',
				array(
					'current_member' => User::$info->id,
					'id_pm' => $pm,
					'new_labels' => empty($realLabels) ? '' : implode(',', $realLabels),
				)
			);
		}
	}
}

/**
 * Load up all the rules for the current user.
 *
 * @param bool $reload = false
 * @package PersonalMessage
 */
function loadRules($reload = false)
{
	global $context;

	$db = database();

	if (isset($context['rules']) && !$reload)
	{
		return;
	}

	// This is just a simple list of "all" known rules
	$context['known_rules'] = array(
		// member_id == "Sender Name"
		'mid',
		// group_id == "Sender's Groups"
		'gid',
		// subject == "Message Subject Contains"
		'sub',
		// message == "Message Body Contains"
		'msg',
		// buddy == "Sender is Buddy"
		'bud',
	);

	$context['rules'] = array();
	$db->fetchQuery('
		SELECT
			id_rule, rule_name, criteria, actions, delete_pm, is_or
		FROM {db_prefix}pm_rules
		WHERE id_member = {int:current_member}',
		array(
			'current_member' => User::$info->id,
		)
	)->fetch_callback(
		function ($row) use (&$context) {
			$context['rules'][$row['id_rule']] = array(
				'id' => $row['id_rule'],
				'name' => $row['rule_name'],
				'criteria' => Util::unserialize($row['criteria']),
				'actions' => Util::unserialize($row['actions']),
				'delete' => $row['delete_pm'],
				'logic' => $row['is_or'] ? 'or' : 'and',
			);

			if ($row['delete_pm'])
			{
				$context['rules'][$row['id_rule']]['actions'][] = array('t' => 'del', 'v' => 1);
			}
		}
	);
}

/**
 * Update PM recipient when they receive or read a new PM
 *
 * @param int $id_member
 * @param bool $new = false
 * @package PersonalMessage
 */
function toggleNewPM($id_member, $new = false)
{
	$db = database();

	$db->fetchQuery('
		UPDATE {db_prefix}pm_recipients
		SET 
			is_new = ' . ($new ? '{int:new}' : '{int:not_new}') . '
		WHERE id_member = {int:current_member}',
		array(
			'current_member' => $id_member,
			'new' => 1,
			'not_new' => 0
		)
	);
}

/**
 * Load the PM limits for each group or for a specified group
 *
 * @param int|bool $id_group (optional) the id of a membergroup
 *
 * @return array
 * @package PersonalMessage
 */
function loadPMLimits($id_group = false)
{
	$db = database();

	$groups = array();
	$db->fetchQuery('
		SELECT
			id_group, group_name, max_messages
		FROM {db_prefix}membergroups' . ($id_group ? '
		WHERE id_group = {int:id_group}' : '
		ORDER BY min_posts, CASE WHEN id_group < {int:newbie_group} THEN id_group ELSE 4 END, group_name'),
		array(
			'id_group' => $id_group,
			'newbie_group' => 4,
		)
	)->fetch_callback(
		function ($row) use (&$groups) {
			if ($row['id_group'] != 1)
			{
				$groups[$row['id_group']] = $row;
			}
		}
	);

	return $groups;
}

/**
 * Retrieve the discussion one or more PMs belong to
 *
 * @param int[] $id_pms
 *
 * @return array
 * @package PersonalMessage
 */
function getDiscussions($id_pms)
{
	$db = database();

	$pm_heads = array();
	$db->fetchQuery('
		SELECT
			id_pm_head, id_pm
		FROM {db_prefix}personal_messages
		WHERE id_pm IN ({array_int:id_pms})',
		array(
			'id_pms' => $id_pms,
		)
	)->fetch_callback(
		function ($row) use (&$pm_heads) {
			$pm_heads[$row['id_pm_head']] = $row['id_pm'];
		}
	);

	return $pm_heads;
}

/**
 * Return all the PMs belonging to one or more discussions
 *
 * @param int[] $pm_heads array of pm id head nodes
 *
 * @return array
 * @package PersonalMessage
 */
function getPmsFromDiscussion($pm_heads)
{
	$db = database();

	$pms = array();
	$db->fetchQuery('
		SELECT
			id_pm, id_pm_head
		FROM {db_prefix}personal_messages
		WHERE id_pm_head IN ({array_int:pm_heads})',
		array(
			'pm_heads' => $pm_heads,
		)
	)->fetch_callback(
		function ($row) use (&$pms) {
			// Copy the action from the single to PM to the others.
			$pms[$row['id_pm']] = $row['id_pm_head'];
		}
	);

	return $pms;
}

/**
 * Determines the PMs which need an updated label.
 *
 * @param mixed[] $to_label
 * @param string[] $label_type
 * @param int $user_id
 * @return int|null
 * @package PersonalMessage
 */
function changePMLabels($to_label, $label_type, $user_id)
{
	global $options;

	$db = database();

	$to_update = array();

	// Get information about each message...
	$db->fetchQuery('
		SELECT
			id_pm, labels
		FROM {db_prefix}pm_recipients
		WHERE id_member = {int:current_member}
			AND id_pm IN ({array_int:to_label})
		LIMIT ' . count($to_label),
		array(
			'current_member' => $user_id,
			'to_label' => array_keys($to_label),
		)
	)->fetch_callback(
		function ($row) use ($options, &$to_update, &$to_label, &$label_type) {
			$labels = $row['labels'] == '' ? array('-1') : explode(',', trim($row['labels']));

			// Already exists?  Then... unset it!
			$id_label = array_search($to_label[$row['id_pm']], $labels);

			if ($id_label !== false && $label_type[$row['id_pm']] !== 'add')
			{
				unset($labels[$id_label]);
			}
			elseif ($label_type[$row['id_pm']] !== 'rem')
			{
				$labels[] = $to_label[$row['id_pm']];
			}

			if (!empty($options['pm_remove_inbox_label'])
				&& $to_label[$row['id_pm']] != '-1'
				&& ($key = array_search('-1', $labels)) !== false)
			{
				unset($labels[$key]);
			}

			$set = implode(',', array_unique($labels));
			if ($set == '')
			{
				$set = '-1';
			}

			$to_update[$row['id_pm']] = $set;
		}
	);

	if (!empty($to_update))
	{
		return updatePMLabels($to_update, $user_id);
	}
}

/**
 * Detects personal messages which need a new label.
 *
 * @param mixed[] $searchArray
 * @param mixed[] $new_labels
 * @param int $user_id
 * @return int|null
 * @package PersonalMessage
 */
function updateLabelsToPM($searchArray, $new_labels, $user_id)
{
	$db = database();

	$to_update = array();

	// Now find the messages to change.
	$db->fetchQuery('
		SELECT
			id_pm, labels
		FROM {db_prefix}pm_recipients
		WHERE FIND_IN_SET({raw:find_label_implode}, labels) != 0
			AND id_member = {int:current_member}',
		array(
			'current_member' => $user_id,
			'find_label_implode' => '\'' . implode('\', labels) != 0 OR FIND_IN_SET(\'', $searchArray) . '\'',
		)
	)->fetch_callback(
		function ($row) use (&$to_update, $searchArray, $new_labels) {
			// Do the long task of updating them...
			$toChange = explode(',', $row['labels']);

			foreach ($toChange as $key => $value)
			{
				if (in_array($value, $searchArray))
				{
					if (isset($searchArray[$value]))
					{
						$toChange[$key] = $new_labels[$value];
					}
					else
					{
						unset($toChange[$key]);
					}
				}
			}

			if (empty($toChange))
			{
				$toChange[] = '-1';
			}

			$to_update[$row['id_pm']] = implode(',', array_unique($toChange));
		}
	);

	if (!empty($to_update))
	{
		return updatePMLabels($to_update, $user_id);
	}
}

/**
 * Updates PMs with their new label.
 *
 * @param mixed[] $to_update
 * @param int $user_id
 * @return int
 * @package PersonalMessage
 */
function updatePMLabels($to_update, $user_id)
{
	$db = database();

	$updateErrors = 0;

	foreach ($to_update as $id_pm => $set)
	{
		// Check that this string isn't going to be too large for the database.
		if (strlen($set) > 60)
		{
			$updateErrors++;

			// Make the string as long as possible and update anyway
			$set = substr($set, 0, 60);
			$set = substr($set, 0, strrpos($set, ','));
		}

		$db->query('', '
			UPDATE {db_prefix}pm_recipients
			SET 
				labels = {string:labels}
			WHERE id_pm = {int:id_pm}
				AND id_member = {int:current_member}',
			array(
				'current_member' => $user_id,
				'id_pm' => $id_pm,
				'labels' => $set,
			)
		);
	}

	return $updateErrors;
}

/**
 * Gets PMs older than a specific date.
 *
 * @param int $user_id the user's id.
 * @param int $time timestamp with a specific date
 * @return array
 * @package PersonalMessage
 */
function getPMsOlderThan($user_id, $time)
{
	$db = database();

	// Array to store the IDs in.
	$pm_ids = array();

	// Select all the messages they have sent older than $time.
	$db->fetchQuery('
		SELECT
			id_pm
		FROM {db_prefix}personal_messages
		WHERE deleted_by_sender = {int:not_deleted}
			AND id_member_from = {int:current_member}
			AND msgtime < {int:msgtime}',
		array(
			'current_member' => $user_id,
			'not_deleted' => 0,
			'msgtime' => $time,
		)
	)->fetch_callback(
		function ($row) use (&$pm_ids) {
			$pm_ids[] = $row['id_pm'];
		}
	);

	// This is the inbox
	$db->fetchQuery('
		SELECT
			pmr.id_pm
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
		WHERE pmr.deleted = {int:not_deleted}
			AND pmr.id_member = {int:current_member}
			AND pm.msgtime < {int:msgtime}',
		array(
			'current_member' => $user_id,
			'not_deleted' => 0,
			'msgtime' => $time,
		)
	)->fetch_callback(
		function ($row) use (&$pm_ids) {
			$pm_ids[] = $row['id_pm'];
		}
	);

	return $pm_ids;
}

/**
 * Used to delete PM rules from the given member.
 *
 * @param int $id_member
 * @param int[] $rule_changes
 * @package PersonalMessage
 */
function deletePMRules($id_member, $rule_changes)
{
	$db = database();

	$db->query('', '
		DELETE FROM {db_prefix}pm_rules
		WHERE id_rule IN ({array_int:rule_list})
		AND id_member = {int:current_member}',
		array(
			'current_member' => $id_member,
			'rule_list' => $rule_changes,
		)
	);
}

/**
 * Updates a personal messaging rule action for the given member.
 *
 * @param int $id_rule
 * @param int $id_member
 * @param mixed[] $actions
 * @package PersonalMessage
 */
function updatePMRuleAction($id_rule, $id_member, $actions)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}pm_rules
		SET 
			actions = {string:actions}
		WHERE id_rule = {int:id_rule}
			AND id_member = {int:current_member}',
		array(
			'current_member' => $id_member,
			'id_rule' => $id_rule,
			'actions' => serialize($actions),
		)
	);
}

/**
 * Add a new PM rule to the database.
 *
 * @param int $id_member
 * @param string $ruleName
 * @param string $criteria
 * @param string $actions
 * @param int $doDelete
 * @param int $isOr
 * @package PersonalMessage
 */
function addPMRule($id_member, $ruleName, $criteria, $actions, $doDelete, $isOr)
{
	$db = database();

	$db->insert('',
		'{db_prefix}pm_rules',
		array(
			'id_member' => 'int', 'rule_name' => 'string', 'criteria' => 'string', 'actions' => 'string',
			'delete_pm' => 'int', 'is_or' => 'int',
		),
		array(
			$id_member, $ruleName, $criteria, $actions, $doDelete, $isOr,
		),
		array('id_rule')
	);
}

/**
 * Updates a personal messaging rule for the given member.
 *
 * @param int $id_member
 * @param int $id_rule
 * @param string $ruleName
 * @param string $criteria
 * @param string $actions
 * @param int $doDelete
 * @param int $isOr
 * @package PersonalMessage
 */
function updatePMRule($id_member, $id_rule, $ruleName, $criteria, $actions, $doDelete, $isOr)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}pm_rules
		SET 
			rule_name = {string:rule_name}, criteria = {string:criteria}, actions = {string:actions},
			delete_pm = {int:delete_pm}, is_or = {int:is_or}
		WHERE id_rule = {int:id_rule}
			AND id_member = {int:current_member}',
		array(
			'current_member' => $id_member,
			'delete_pm' => $doDelete,
			'is_or' => $isOr,
			'id_rule' => $id_rule,
			'rule_name' => $ruleName,
			'criteria' => $criteria,
			'actions' => $actions,
		)
	);
}

/**
 * Used to set a replied status for a given PM.
 *
 * @param int $id_member
 * @param int $replied_to
 * @package PersonalMessage
 */
function setPMRepliedStatus($id_member, $replied_to)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}pm_recipients
		SET 
			is_read = is_read | 2
		WHERE id_pm = {int:replied_to}
			AND id_member = {int:current_member}',
		array(
			'current_member' => $id_member,
			'replied_to' => $replied_to,
		)
	);
}

/**
 * Given the head PM, loads all other PM's that share the same head node
 *
 * - Used to load the conversation view of a PM
 *
 * @param int $head id of the head pm of the conversation
 * @param mixed[] $recipients
 * @param string $folder the current folder we are working in
 *
 * @return array
 * @package PersonalMessage
 */
function loadConversationList($head, &$recipients, $folder = '')
{
	$db = database();

	$display_pms = array();
	$posters = array();
	$db->fetchQuery('
		SELECT
			pm.id_pm, pm.id_member_from, pm.deleted_by_sender, pmr.id_member, pmr.deleted
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
		WHERE pm.id_pm_head = {int:id_pm_head}
			AND ((pm.id_member_from = {int:current_member} AND pm.deleted_by_sender = {int:not_deleted})
				OR (pmr.id_member = {int:current_member} AND pmr.deleted = {int:not_deleted}))
		ORDER BY pm.id_pm',
		array(
			'current_member' => User::$info->id,
			'id_pm_head' => $head,
			'not_deleted' => 0,
		)
	)->fetch_callback(
		function ($row) use ($folder, &$recipients, &$display_pms, &$posters) {
			// This is, frankly, a joke.
			// We will put in a workaround for people sending to themselves - yawn!
			if ($folder === 'sent' && $row['id_member_from'] == User::$info->id && $row['deleted_by_sender'] == 1)
			{
				return;
			}
			elseif (($row['id_member'] == User::$info->id) && $row['deleted'] == 1)
			{
				return;
			}

			if (!isset($recipients[$row['id_pm']]))
			{
				$recipients[$row['id_pm']] = array(
					'to' => array(),
					'bcc' => array()
				);
			}

			$display_pms[] = $row['id_pm'];
			$posters[$row['id_pm']] = $row['id_member_from'];
		}
	);

	return array($display_pms, $posters);
}

/**
 * Used to determine if any message in a conversation thread is unread
 *
 * - Returns array of keys with the head id and value details of the newest
 * unread message.
 *
 * @param int[] $pms array of pm ids to search
 *
 * @return array
 * @package PersonalMessage
 */
function loadConversationUnreadStatus($pms)
{
	$db = database();

	// Make it an array if its not
	if (!is_array($pms))
	{
		$pms = array($pms);
	}

	// Find the heads for this group of PM's
	$head_pms = array();
	$db->fetchQuery('
		SELECT
			id_pm_head, id_pm
		FROM {db_prefix}personal_messages
		WHERE id_pm IN ({array_int:id_pm})',
		array(
			'id_pm' => $pms,
		)
	)->fetch_callback(
		function ($row) use (&$head_pms) {
			$head_pms[$row['id_pm_head']] = $row['id_pm'];
		}
	);

	// Find any unread PM's this member has under these head pm id's
	$unread_pms = array();
	$db->fetchQuery('
		SELECT
			MAX(pm.id_pm) AS id_pm, pm.id_pm_head
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
		WHERE pm.id_pm_head IN ({array_int:id_pm_head})
			AND (pmr.id_member = {int:current_member} AND pmr.deleted = {int:not_deleted})
			AND (pmr.is_read & 1 = 0)
		GROUP BY pm.id_pm_head',
		array(
			'current_member' => User::$info->id,
			'id_pm_head' => array_keys($head_pms),
			'not_deleted' => 0,
		)
	)->fetch_callback(
		function ($row) use ($head_pms, &$unread_pms) {
			// Return the results under the original index since that's what we are
			// displaying in the subject list
			$index = $head_pms[$row['id_pm_head']];
			$unread_pms[$index] = $row;
		}
	);

	return $unread_pms;
}

/**
 * Get all recipients for a given group of PM's, loads some basic member information for each
 *
 * - Will not include bcc-recipients for an inbox
 * - Keeps track if a message has been replied / read
 * - Tracks any message labels in use
 * - If optional search parameter is set to true will return message first label, useful for linking
 *
 * @param int[] $all_pms
 * @param mixed[] $recipients
 * @param string $folder
 * @param bool $search
 *
 * @return array
 * @package PersonalMessage
 *
 */
function loadPMRecipientInfo($all_pms, &$recipients, $folder = '', $search = false)
{
	global $txt, $scripturl, $context;

	$db = database();

	$message_labels = array();
	foreach ($all_pms as $pmid)
	{
		$message_labels[$pmid] = array();
	}
	$message_replied = array();
	$message_unread = array();
	$message_first_label = array();

	// Get the recipients for all these PM's
	$request = $db->fetchQuery('
		SELECT
			pmr.id_pm, pmr.bcc, pmr.labels, pmr.is_read,
			mem_to.id_member AS id_member_to, mem_to.real_name AS to_name
		FROM {db_prefix}pm_recipients AS pmr
			LEFT JOIN {db_prefix}members AS mem_to ON (mem_to.id_member = pmr.id_member)
		WHERE pmr.id_pm IN ({array_int:pm_list})',
		array(
			'pm_list' => $all_pms,
		)
	);
	while (($row = $request->fetch_assoc()))
	{
		// Sent folder recipients
		if ($folder === 'sent' || empty($row['bcc']))
		{
			$recipients[$row['id_pm']][empty($row['bcc']) ? 'to' : 'bcc'][] = empty($row['id_member_to']) ? $txt['guest_title'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member_to'] . '">' . $row['to_name'] . '</a>';
		}

		// Don't include bcc-recipients if its your inbox, you're not supposed to know :P
		if ($row['id_member_to'] == User::$info->id && $folder !== 'sent')
		{
			// Read and replied to status for this message
			$message_replied[$row['id_pm']] = $row['is_read'] & 2;
			$message_unread[$row['id_pm']] = $row['is_read'] == 0;
			$message_labels[$row['id_pm']] = array();

			$row['labels'] = $row['labels'] == '' ? array() : explode(',', $row['labels']);
			foreach ($row['labels'] as $v)
			{
				if (isset($context['labels'][(int) $v]))
				{
					$message_labels[$row['id_pm']][(int) $v] = array('id' => $v, 'name' => $context['labels'][(int) $v]['name']);
				}

				// Here we find the first label on a message - used for linking to posts
				if ($search && (!isset($message_first_label[$row['id_pm']]) && !in_array('-1', $row['labels'])))
				{
					$message_first_label[$row['id_pm']] = (int) $v;
				}
			}
		}
	}
	$request->free_result();

	return array($message_labels, $message_replied, $message_unread, ($search ? $message_first_label : ''));
}

/**
 * This is used by preparePMContext_callback.
 *
 * - That function uses these query results and handles the free_result action as well.
 *
 * @param int[] $pms array of PM ids to fetch
 * @param string[] $orderBy raw query defining how to order the results
 * @return bool|resource
 * @package PersonalMessage
 */
function loadPMSubjectRequest($pms, $orderBy)
{
	$db = database();

	// Separate query for these bits!
	return $db->query('', '
		SELECT
			pm.id_pm, pm.subject, pm.id_member_from, pm.msgtime, COALESCE(mem.real_name, pm.from_name) AS from_name,
			COALESCE(mem.id_member, 0) AS not_guest,
			{string:empty} as body, {int:smileys_enabled} as smileys_enabled
		FROM {db_prefix}personal_messages AS pm
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
		WHERE pm.id_pm IN ({array_int:pm_list})
		ORDER BY ' . implode(', ', $orderBy) . '
		LIMIT ' . count($pms),
		array(
			'pm_list' => $pms,
			'empty' => '',
			'smileys_enabled' => 1,
			'from_time' => 0,
		)
	);
}

/**
 * Similar to loadSubjectRequest, this is used by preparePMContext_callback.
 *
 * - That function uses these query results and handles the free_result action as well.
 *
 * @param int[] $display_pms list of PM's to fetch
 * @param string $sort_by_query raw query used in the sorting option
 * @param string $sort_by used to signal when addition joins are needed
 * @param bool $descending if true descending order of display
 * @param int|string $display_mode how are they being viewed, all, conversation, etc
 * @param string $folder current pm folder
 * @return bool|\ElkArte\Database\AbstractResult
 * @package PersonalMessage
 */
function loadPMMessageRequest($display_pms, $sort_by_query, $sort_by, $descending, $display_mode = '', $folder = '')
{
	$db = database();

	return $db->query('', '
		SELECT
			pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name,
			{int:smileys_enabled} as smileys_enabled
		FROM {db_prefix}personal_messages AS pm' . ($folder == 'sent' ? '
			LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') . ($sort_by == 'name' ? '
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})' : '') . '
		WHERE pm.id_pm IN ({array_int:display_pms})' . ($folder == 'sent' ? '
		GROUP BY pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name' : '') . '
		ORDER BY ' . ($display_mode == 2 ? 'pm.id_pm' : $sort_by_query) . ($descending ? ' DESC' : ' ASC') . '
		LIMIT ' . count($display_pms),
		array(
			'display_pms' => $display_pms,
			'id_member' => $folder == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
			'smileys_enabled' => 1,
		)
	);
}

/**
 * Simple function to validate that a PM was sent to the current user
 *
 * @param int $pmsg id of the pm we are checking
 *
 * @return bool
 * @package PersonalMessage
 */
function checkPMReceived($pmsg)
{
	$db = database();

	$request = $db->fetchQuery('
		SELECT
			id_pm
		FROM {db_prefix}pm_recipients
		WHERE id_pm = {int:id_pm}
			AND id_member = {int:current_member}
		LIMIT 1',
		array(
			'current_member' => User::$info->id,
			'id_pm' => $pmsg,
		)
	);
	$isReceived = $request->num_rows() !== 0;
	$request->free_result();

	return $isReceived;
}

/**
 * Loads a pm by ID for use as a quoted pm in a new message
 *
 * @param int $pmsg
 * @param bool $isReceived
 *
 * @return bool
 * @package PersonalMessage
 */
function loadPMQuote($pmsg, $isReceived)
{
	$db = database();

	// Get the quoted message (and make sure you're allowed to see this quote!).
	$request = $db->fetchQuery('
		SELECT
			pm.id_pm, CASE WHEN pm.id_pm_head = {int:id_pm_head_empty} THEN pm.id_pm ELSE pm.id_pm_head END AS pm_head,
			pm.body, pm.subject, pm.msgtime,
			mem.member_name, COALESCE(mem.id_member, 0) AS id_member, COALESCE(mem.real_name, pm.from_name) AS real_name
		FROM {db_prefix}personal_messages AS pm' . (!$isReceived ? '' : '
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = {int:id_pm})') . '
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
		WHERE pm.id_pm = {int:id_pm}' . (!$isReceived ? '
			AND pm.id_member_from = {int:current_member}' : '
			AND pmr.id_member = {int:current_member}') . '
		LIMIT 1',
		array(
			'current_member' => User::$info->id,
			'id_pm_head_empty' => 0,
			'id_pm' => $pmsg,
		)
	);
	$row_quoted = $request->fetch_assoc();
	$request->free_result();

	return empty($row_quoted) ? false : $row_quoted;
}

/**
 * For a given PM ID, loads all "other" recipients, (excludes the current member)
 *
 * - Will optionally count the number of bcc recipients and return that count
 *
 * @param int $pmsg
 * @param bool $bcc_count
 *
 * @return array
 * @package PersonalMessage
 */
function loadPMRecipientsAll($pmsg, $bcc_count = false)
{
	global $scripturl, $txt;

	$db = database();

	$recipients = array();
	$hidden_recipients = 0;
	$db->fetchQuery('
		SELECT
			mem.id_member, mem.real_name, pmr.bcc
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = pmr.id_member)
		WHERE pmr.id_pm = {int:id_pm}
			AND pmr.id_member != {int:current_member}' . ($bcc_count === true ? '' : '
			AND pmr.bcc = {int:not_bcc}'),
		array(
			'current_member' => User::$info->id,
			'id_pm' => $pmsg,
			'not_bcc' => 0,
		)
	)->fetch_callback(
		function ($row) use (&$recipients, &$hidden_recipients, $bcc_count, $scripturl) {
			// If it's hidden we still don't reveal their names
			if ($bcc_count && $row['bcc'])
			{
				$hidden_recipients++;
			}

			$recipients[] = array(
				'id' => $row['id_member'],
				'name' => htmlspecialchars($row['real_name'], ENT_COMPAT, 'UTF-8'),
				'link' => '[url=' . $scripturl . '?action=profile;u=' . $row['id_member'] . ']' . $row['real_name'] . '[/url]',
			);
		}
	);

	// If bcc count was requested, we return the number of bcc members, but not the names
	if ($bcc_count)
	{
		$recipients[] = array(
			'id' => 'bcc',
			'name' => sprintf($txt['pm_report_pm_hidden'], $hidden_recipients),
			'link' => sprintf($txt['pm_report_pm_hidden'], $hidden_recipients)
		);
	}

	return $recipients;
}

/**
 * Simply loads a personal message by ID
 *
 * - Supplied ID must have been sent to the user id requesting it and it must not have been deleted
 *
 * @param int $pm_id
 *
 * @return
 * @throws \ElkArte\Exceptions\Exception no_access
 * @package PersonalMessage
 *
 */
function loadPersonalMessage($pm_id)
{
	$db = database();

	// First, pull out the message contents, and verify it actually went to them!
	$request = $db->fetchQuery('
		SELECT
			pm.subject, pm.body, pm.msgtime, pm.id_member_from,
			COALESCE(m.real_name, pm.from_name) AS sender_name,
			pm.from_name AS poster_name, msgtime
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
			LEFT JOIN {db_prefix}members AS m ON (m.id_member = pm.id_member_from)
		WHERE pm.id_pm = {int:id_pm}
			AND pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}
		LIMIT 1',
		array(
			'current_member' => User::$info->id,
			'id_pm' => $pm_id,
			'not_deleted' => 0,
		)
	);
	// Can only be a hacker here!
	if ($request->num_rows() === 0)
	{
		throw new \ElkArte\Exceptions\Exception('no_access', false);
	}
	$pm_details = $request->fetch_row();
	$request->free_result();

	return $pm_details;
}

/**
 * Finds the number of results that a search would produce
 *
 * @param string $userQuery raw query, used if we are searching for specific users
 * @param string $labelQuery raw query, used if we are searching only specific labels
 * @param string $timeQuery raw query, used if we are limiting results to time periods
 * @param string $searchQuery raw query, the actual thing you are searching for in the subject and/or body
 * @param mixed[] $searchq_parameters value parameters used in the above query
 * @return int
 * @package PersonalMessage
 */
function numPMSeachResults($userQuery, $labelQuery, $timeQuery, $searchQuery, $searchq_parameters)
{
	global $context;

	$db = database();

	// Get the amount of results.
	$request = $db->fetchQuery('
		SELECT
			COUNT(*)
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
		WHERE ' . ($context['folder'] == 'inbox' ? '
			pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}' : '
			pm.id_member_from = {int:current_member}
			AND pm.deleted_by_sender = {int:not_deleted}') . '
			' . $userQuery . $labelQuery . $timeQuery . '
			AND (' . $searchQuery . ')',
		array_merge($searchq_parameters, array(
			'current_member' => User::$info->id,
			'not_deleted' => 0,
		))
	);
	list ($numResults) = $request->fetch_row();
	$request->free_result();

	return $numResults;
}

/**
 * Gets all the matching message ids, senders and head pm nodes, using standard search only (No caching and the like!)
 *
 * @param string $userQuery raw query, used if we are searching for specific users
 * @param string $labelQuery raw query, used if we are searching only specific labels
 * @param string $timeQuery raw query, used if we are limiting results to time periods
 * @param string $searchQuery raw query, the actual thing you are searching for in the subject and/or body
 * @param mixed[] $searchq_parameters value parameters used in the above query
 * @param mixed[] $search_params additional search parameters, like sort and direction
 *
 * @return array
 * @package PersonalMessage
 */
function loadPMSearchMessages($userQuery, $labelQuery, $timeQuery, $searchQuery, $searchq_parameters, $search_params)
{
	global $context, $modSettings;

	$db = database();

	$foundMessages = array();
	$posters = array();
	$head_pms = array();
	$db->fetchQuery('
		SELECT
			pm.id_pm, pm.id_pm_head, pm.id_member_from
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
		WHERE ' . ($context['folder'] == 'inbox' ? '
			pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}' : '
			pm.id_member_from = {int:current_member}
			AND pm.deleted_by_sender = {int:not_deleted}') . '
			' . $userQuery . $labelQuery . $timeQuery . '
			AND (' . $searchQuery . ')
		ORDER BY ' . $search_params['sort'] . ' ' . $search_params['sort_dir'] . '
		LIMIT ' . $context['start'] . ', ' . $modSettings['search_results_per_page'],
		array_merge($searchq_parameters, array(
			'current_member' => User::$info->id,
			'not_deleted' => 0,
		))
	)->fetch_callback(
		function ($row) use (&$foundMessages, &$posters, &$head_pms)
		{
			$foundMessages[] = $row['id_pm'];
			$posters[] = $row['id_member_from'];
			$head_pms[$row['id_pm']] = $row['id_pm_head'];
		}
	);

	return array($foundMessages, $posters, $head_pms);
}

/**
 * When we are in conversation view, we need to find the base head pm of the
 * conversation.  This will set the root head id to each of the node heads
 *
 * @param int[] $head_pms array of pm ids that were found in the id_pm_head col
 * during the initial search
 *
 * @return array
 * @package PersonalMessage
 */
function loadPMSearchHeads($head_pms)
{
	$db = database();

	$real_pm_ids = array();
	$db->fetchQuery('
		SELECT
			MAX(pm.id_pm) AS id_pm, pm.id_pm_head
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
		WHERE pm.id_pm_head IN ({array_int:head_pms})
			AND pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}
		GROUP BY pm.id_pm_head
		LIMIT {int:limit}',
		array(
			'head_pms' => array_unique($head_pms),
			'current_member' => User::$info->id,
			'not_deleted' => 0,
			'limit' => count($head_pms),
		)
	)->fetch_callback(
		function ($row) use (&$real_pm_ids)
		{
			$real_pm_ids[$row['id_pm_head']] = $row['id_pm'];
		}
	);

	return $real_pm_ids;
}

/**
 * Loads the actual details of the PM's that were found during the search stage
 *
 * @param int[] $foundMessages array of found message id's
 * @param mixed[] $search_params as specified in the form, here used for sorting
 *
 * @return array
 * @package PersonalMessage
 */
function loadPMSearchResults($foundMessages, $search_params)
{
	$db = database();

	// Prepare the query for the callback
	$search_results = array();
	$db->fetchQuery('
		SELECT
			pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name
		FROM {db_prefix}personal_messages AS pm
		WHERE pm.id_pm IN ({array_int:message_list})
		ORDER BY ' . $search_params['sort'] . ' ' . $search_params['sort_dir'] . '
		LIMIT ' . count($foundMessages),
		array(
			'message_list' => $foundMessages,
		)
	)->fetch_callback(
		function ($row) use (&$search_results) {
			$search_results[] = $row;
		}
	);

	return $search_results;
}
