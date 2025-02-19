<?php

/**
 * This file contains functions regarding manipulation of and information about membergroups.
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
 * Delete one of more membergroups.
 *
 * - Requires the manage_membergroups permission.
 * - Returns true on success or false on failure.
 * - Has protection against deletion of protected membergroups.
 * - Deletes the permissions linked to the membergroup.
 * - Takes members out of the deleted membergroups.
 *
 * @param int[]|int $groups
 * @return bool
 * @package Membergroups
 */
function deleteMembergroups($groups)
{
	global $modSettings;

	$db = database();

	// Make sure it's an array.
	if (!is_array($groups))
	{
		$groups = array((int) $groups);
	}
	else
	{
		$groups = array_unique($groups);

		// Make sure all groups are integer.
		foreach ($groups as $key => $value)
		{
			$groups[$key] = (int) $value;
		}
	}

	// Some groups are protected (guests, administrators, moderators, newbies).
	$protected_groups = array(-1, 0, 1, 3, 4);

	// There maybe some others as well.
	if (!allowedTo('admin_forum'))
	{
		$db->fetchQuery('
			SELECT 
				id_group
			FROM {db_prefix}membergroups
			WHERE group_type = {int:is_protected}',
			array(
				'is_protected' => 1,
			)
		)->fetch_callback(
			function ($row) use (&$protected_groups) {
				$protected_groups[] = $row['id_group'];
			}
		);
	}

	// Make sure they don't delete protected groups!
	$groups = array_diff($groups, array_unique($protected_groups));
	if (empty($groups))
	{
		return false;
	}

	// Log the deletion.
	$groups_to_log = membergroupsById($groups, 0);
	foreach ($groups_to_log as $key => $row)
	{
		logAction('delete_group', array('group' => $row['group_name']), 'admin');
	}

	call_integration_hook('integrate_delete_membergroups', array($groups));

	// Remove the membergroups themselves.
	$db->query('', '
		DELETE FROM {db_prefix}membergroups
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);

	// Remove the permissions of the membergroups.
	$db->query('', '
		DELETE FROM {db_prefix}permissions
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);
	$db->query('', '
		DELETE FROM {db_prefix}board_permissions
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);
	$db->query('', '
		DELETE FROM {db_prefix}group_moderators
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);

	// Delete any outstanding requests.
	$db->query('', '
		DELETE FROM {db_prefix}log_group_requests
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);

	// Update the primary groups of members.
	$db->query('', '
		UPDATE {db_prefix}members
		SET id_group = {int:regular_group}
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
			'regular_group' => 0,
		)
	);

	// Update any inherited groups (Lose inheritance).
	$db->query('', '
		UPDATE {db_prefix}membergroups
		SET id_parent = {int:uninherited}
		WHERE id_parent IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
			'uninherited' => -2,
		)
	);

	// Update the additional groups of members.
	$updates = array();
	$db->fetchQuery('
		SELECT 
			id_member, additional_groups
		FROM {db_prefix}members
		WHERE FIND_IN_SET({raw:additional_groups_explode}, additional_groups) != 0',
		array(
			'additional_groups_explode' => implode(', additional_groups) != 0 OR FIND_IN_SET(', $groups),
		)
	)->fetch_callback(
		function ($row) use (&$updates) {
			// Update each member information.
			$updates[$row['additional_groups']][] = $row['id_member'];
		}
	);

	require_once(SUBSDIR . '/Members.subs.php');
	foreach ($updates as $additional_groups => $memberArray)
	{
		updateMemberData($memberArray, array('additional_groups' => implode(',', array_diff(explode(',', $additional_groups), $groups))));
	}

	// No boards can provide access to these membergroups anymore.
	$updates = array();
	$db->fetchQuery('
		SELECT 
			id_board, member_groups
		FROM {db_prefix}boards
		WHERE FIND_IN_SET({raw:member_groups_explode}, member_groups) != 0',
		array(
			'member_groups_explode' => implode(', member_groups) != 0 OR FIND_IN_SET(', $groups),
		)
	)->fetch_callback(
		function ($row) use (&$updates) {
			$updates[$row['member_groups']][] = $row['id_board'];
		}
	);

	foreach ($updates as $member_groups => $boardArray)
	{
		$db->query('', '
			UPDATE {db_prefix}boards
			SET member_groups = {string:member_groups}
			WHERE id_board IN ({array_int:board_lists})',
			array(
				'board_lists' => $boardArray,
				'member_groups' => implode(',', array_diff(explode(',', $member_groups), $groups)),
			)
		);
	}

	// Recalculate the post groups, as they likely changed.
	updatePostGroupStats();

	// Make a note of the fact that the cache may be wrong.
	$settings_update = array('settings_updated' => time());

	// Have we deleted the spider group?
	// @memo we are lucky that the group 1 and 0 cannot be deleted
	// $modSettings['spider_group'] is set to 1 (admin) for regular members (that usually is group 0)
	if (isset($modSettings['spider_group']) && in_array($modSettings['spider_group'], $groups))
	{
		$settings_update['spider_group'] = 0;
	}

	updateSettings($settings_update);

	// It was a success.
	return true;
}

/**
 * Remove one or more members from one or more membergroups.
 *
 * - Requires the manage_membergroups permission.
 * - Function includes a protection against removing from implicit groups.
 * - Non-admins are not able to remove members from the admin group.
 *
 * @param int[]|int $members
 * @param int|null $groups
 * @param bool $permissionCheckDone = false
 *
 * @return bool
 * @package Membergroups
 */
function removeMembersFromGroups($members, $groups = null, $permissionCheckDone = false)
{
	global $modSettings;

	$db = database();

	// You're getting nowhere without this permission, unless of course you are the group's moderator.
	if (!$permissionCheckDone)
	{
		isAllowedTo('manage_membergroups');
	}

	// Assume something will happen.
	updateSettings(array('settings_updated' => time()));

	// Cleaning the input.
	if (!is_array($members))
	{
		$members = array((int) $members);
	}
	else
	{
		$members = array_unique($members);

		// Cast the members to integer.
		foreach ($members as $key => $value)
		{
			$members[$key] = (int) $value;
		}
	}

	// Before we get started, let's check we won't leave the admin group empty!
	if ($groups === null || $groups == 1 || (is_array($groups) && in_array(1, $groups)))
	{
		$admins = array();
		listMembergroupMembers_Href($admins, 1);

		// Remove any admins if there are too many.
		$non_changing_admins = array_diff(array_keys($admins), $members);

		if (empty($non_changing_admins))
		{
			$members = array_diff($members, array_keys($admins));
		}
	}

	// Just in case.
	if (empty($members))
	{
		return false;
	}

	// Wanna remove all groups from these members? That's easy.
	if ($groups === null)
	{
		$db->query('', '
			UPDATE {db_prefix}members
			SET
				id_group = {int:regular_member},
				additional_groups = {string:blank_string}
			WHERE id_member IN ({array_int:member_list})' . (allowedTo('admin_forum') ? '' : '
				AND id_group != {int:admin_group}
				AND FIND_IN_SET({int:admin_group}, additional_groups) = 0'),
			array(
				'member_list' => $members,
				'regular_member' => 0,
				'admin_group' => 1,
				'blank_string' => '',
			)
		);

		updatePostGroupStats($members);

		// Log what just happened.
		foreach ($members as $member)
		{
			logAction('removed_all_groups', array('member' => $member), 'admin');
		}

		return true;
	}
	elseif (!is_array($groups))
	{
		$groups = array((int) $groups);
	}
	// Make sure all groups are integer.
	else
	{
		$groups = array_unique(array_map('intval', $groups));
	}

	// Fetch a list of groups members cannot be assigned to explicitly, and the group names of the ones we want.
	$implicitGroups = array(-1, 0, 3);
	$group_names = array();
	$group_details = membergroupsById($groups, 0, true);
	foreach ($group_details as $key => $row)
	{
		if ($row['min_posts'] != -1)
		{
			$implicitGroups[] = $row['id_group'];
		}
		else
		{
			$group_names[$row['id_group']] = $row['group_name'];
		}
	}

	// Now get rid of those groups.
	$groups = array_diff($groups, $implicitGroups);

	// Don't forget the protected groups.
	if (!allowedTo('admin_forum'))
	{
		$protected_groups = array(1);
		$db->fetchQuery('
			SELECT 
				id_group
			FROM {db_prefix}membergroups
			WHERE group_type = {int:is_protected}',
			array(
				'is_protected' => 1,
			)
		)->fetch_callback(
			function ($row) use (&$protected_groups) {
				$protected_groups[] = $row['id_group'];
			}
		);

		// If you're not an admin yourself, you can't touch protected groups!
		$groups = array_diff($groups, array_unique($protected_groups));
	}

	// Only continue if there are still groups and members left.
	if (empty($groups) || empty($members))
	{
		return false;
	}

	// First, reset those who have this as their primary group - this is the easy one.
	$log_inserts = $db->fetchQuery('
		SELECT 
			id_member, id_group
		FROM {db_prefix}members AS members
		WHERE id_group IN ({array_int:group_list})
			AND id_member IN ({array_int:member_list})',
		array(
			'group_list' => $groups,
			'member_list' => $members,
		)
	)->fetch_callback(
		function ($row) use ($group_names) {
			return array('group' => $group_names[$row['id_group']], 'member' => $row['id_member']);
		}
	);

	$db->query('', '
		UPDATE {db_prefix}members
		SET id_group = {int:regular_member}
		WHERE id_group IN ({array_int:group_list})
			AND id_member IN ({array_int:member_list})',
		array(
			'group_list' => $groups,
			'member_list' => $members,
			'regular_member' => 0,
		)
	);

	// Those who have it as part of their additional group must be updated the long way... sadly.
	$updates = array();
	$db->fetchQuery('
		SELECT 
			id_member, additional_groups
		FROM {db_prefix}members
		WHERE (FIND_IN_SET({raw:additional_groups_implode}, additional_groups) != 0)
			AND id_member IN ({array_int:member_list})
		LIMIT ' . count($members),
		array(
			'member_list' => $members,
			'additional_groups_implode' => implode(', additional_groups) != 0 OR FIND_IN_SET(', $groups),
		)
	)->fetch_callback(
		function ($row) use (&$updates, $groups, $group_names) {
			// What log entries must we make for this one, eh?
			foreach (explode(',', $row['additional_groups']) as $group)
			{
				if (in_array($group, $groups))
				{
					$log_inserts[] = array(
						'group' => $group_names[$group],
						'member' => $row['id_member']
					);
				}
			}

			$updates[$row['additional_groups']][] = $row['id_member'];
		}
	);

	require_once(SUBSDIR . '/Members.subs.php');
	foreach ($updates as $additional_groups => $memberArray)
	{
		updateMemberData($memberArray, array('additional_groups' => implode(',', array_diff(explode(',', $additional_groups), $groups))));
	}

	// Their post groups may have changed now...
	updatePostGroupStats($members);

	// Do the log.
	if (!empty($log_inserts) && !empty($modSettings['modlog_enabled']))
	{
		foreach ($log_inserts as $extra)
		{
			logAction('removed_from_group', $extra, 'admin');
		}
	}

	// Mission successful.
	return true;
}

/**
 * Add one or more members to a membergroup.
 *
 * - Requires the manage_membergroups permission.
 * - Function has protection against adding members to implicit groups.
 * - Non-admins cannot add members to the admin group, or protected groups.
 *
 * @param int|int[] $members
 * @param int $group
 * @param string $type = 'auto' specifies whether the group is added as primary or as additional group.
 * Supported types:
 * - only_primary    - Assigns a membergroup as primary membergroup, but only
 *                     if a member has not yet a primary membergroup assigned,
 *                     unless the member is already part of the membergroup.
 * - only_additional - Assigns a membergroup to the additional membergroups,
 *                     unless the member is already part of the membergroup.
 * - force_primary   - Assigns a membergroup as primary membergroup no matter
 *                     what the previous primary membergroup was.
 * - auto            - Assigns a membergroup to the primary group if it's still
 *                     available. If not, assign it to the additional group.
 * @param bool $permissionCheckDone = false if true, it checks permission of the current user to add groups ('manage_membergroups')
 * @return bool success or failure
 * @package Membergroups
 */
function addMembersToGroup($members, $group, $type = 'auto', $permissionCheckDone = false)
{
	$db = database();

	// Show your licence, but only if it hasn't been done yet.
	if (!$permissionCheckDone)
	{
		isAllowedTo('manage_membergroups');
	}

	// Make sure we don't keep old stuff cached.
	updateSettings(array('settings_updated' => time()));

	$members = !is_array($members) ? array((int) $members) : array_unique(array_map('intval', $members));

	$group = (int) $group;

	// Some groups just don't like explicitly having members.
	$implicitGroups = array(-1, 0, 3);
	$group_names = array();
	$group_details = membergroupById($group, true);
	if ($group_details['min_posts'] != -1)
	{
		$implicitGroups[] = $group_details['id_group'];
	}
	else
	{
		$group_names[$group_details['id_group']] = $group_details['group_name'];
	}

	// Sorry, you can't join an implicit group.
	if (in_array($group, $implicitGroups) || empty($members))
	{
		return false;
	}

	// Only admins can add admins...
	if (!allowedTo('admin_forum') && $group == 1)
	{
		return false;
	}
	// ... and assign protected groups!
	elseif (!allowedTo('admin_forum') && $group_details['group_type'] == 1)
	{
		return false;
	}

	// Do the actual updates.
	if ($type === 'only_additional')
	{
		$db->query('', '
			UPDATE {db_prefix}members
			SET additional_groups = CASE WHEN additional_groups = {string:blank_string} THEN {string:id_group_string} ELSE CONCAT(additional_groups, {string:id_group_string_extend}) END
			WHERE id_member IN ({array_int:member_list})
				AND id_group != {int:id_group}
				AND FIND_IN_SET({int:id_group}, additional_groups) = 0',
			array(
				'member_list' => $members,
				'id_group' => $group,
				'id_group_string' => (string) $group,
				'id_group_string_extend' => ',' . $group,
				'blank_string' => '',
			)
		);
	}
	elseif ($type === 'only_primary' || $type === 'force_primary')
	{
		$db->query('', '
			UPDATE {db_prefix}members
			SET id_group = {int:id_group}
			WHERE id_member IN ({array_int:member_list})' . ($type === 'force_primary' ? '' : '
				AND id_group = {int:regular_group}
				AND FIND_IN_SET({int:id_group}, additional_groups) = 0'),
			array(
				'member_list' => $members,
				'id_group' => $group,
				'regular_group' => 0,
			)
		);
	}
	elseif ($type === 'auto')
	{
		$db->query('', '
			UPDATE {db_prefix}members
			SET
				id_group = CASE WHEN id_group = {int:regular_group} THEN {int:id_group} ELSE id_group END,
				additional_groups = CASE WHEN id_group = {int:id_group} THEN additional_groups
					WHEN additional_groups = {string:blank_string} THEN {string:id_group_string}
					ELSE CONCAT(additional_groups, {string:id_group_string_extend}) END
			WHERE id_member IN ({array_int:member_list})
				AND id_group != {int:id_group}
				AND FIND_IN_SET({int:id_group}, additional_groups) = 0',
			array(
				'member_list' => $members,
				'regular_group' => 0,
				'id_group' => $group,
				'blank_string' => '',
				'id_group_string' => (string) $group,
				'id_group_string_extend' => ',' . $group,
			)
		);
	}
	// Ack!!?  What happened?
	else
	{
		trigger_error('addMembersToGroup(): Unknown type \'' . $type . '\'', E_USER_WARNING);
	}

	call_integration_hook('integrate_add_members_to_group', array($members, $group_details, &$group_names));

	// Update their postgroup statistics.
	updatePostGroupStats($members);

	require_once(SOURCEDIR . '/Logging.php');
	foreach ($members as $member)
	{
		logAction('added_to_group', array('group' => $group_names[$group], 'member' => $member), 'admin');
	}

	return true;
}

/**
 * Gets the members of a supplied membergroup.
 *
 * - Returns them as a link for display.
 *
 * @param int[] $members
 * @param int $membergroup
 * @param int|null $limit = null
 * @return bool
 * @package Membergroups
 */
function listMembergroupMembers_Href(&$members, $membergroup, $limit = null)
{
	$db = database();

	$members = array();
	$db->fetchQuery('
		SELECT 
			id_member, real_name
		FROM {db_prefix}members
		WHERE id_group = {int:id_group} OR FIND_IN_SET({int:id_group}, additional_groups) != 0' . ($limit === null ? '' : '
		LIMIT ' . ($limit + 1)),
		array(
			'id_group' => $membergroup,
		)
	)->fetch_callback(
		function ($row) use (&$members) {
			$members[$row['id_member']] = '<a href="' . getUrl('profile', ['action' => 'profile', 'u' => $row['id_member'], 'name' => $row['real_name']]) . '">' . $row['real_name'] . '</a>';
		}
	);

	// If there are more than $limit members, add a 'more' link.
	if ($limit !== null && count($members) > $limit)
	{
		array_pop($members);

		return true;
	}

	return false;
}

/**
 * Retrieve a list of (visible) membergroups used by the cache.
 *
 * @package Membergroups
 */
function cache_getMembergroupList()
{
	$db = database();

	$groupCache = $db->fetchQuery('
		SELECT 
			id_group, group_name, online_color
		FROM {db_prefix}membergroups
		WHERE min_posts = {int:min_posts}
			AND hidden = {int:not_hidden}
			AND id_group != {int:mod_group}
			AND online_color != {string:blank_string}
		ORDER BY group_name',
		array(
			'min_posts' => -1,
			'not_hidden' => 0,
			'mod_group' => 3,
			'blank_string' => '',
		)
	)->fetch_callback(
		function ($row) {
			return '<a href="' . getUrl('group', ['action' => 'groups', 'sa' => 'members', 'group' => $row['id_group'], 'name' => $row['group_name']]) . '" ' . ($row['online_color'] ? 'style="color: ' . $row['online_color'] . '"' : '') . '>' . $row['group_name'] . '</a>';
		}
	);

	return array(
		'data' => $groupCache,
		'expires' => time() + 3600,
		'refresh_eval' => 'return $GLOBALS[\'modSettings\'][\'settings_updated\'] > ' . time() . ';',
	);
}

/**
 * Helper function to generate a list of membergroups for display.
 *
 * @param int $start not used
 * @param int $items_per_page not used
 * @param string $sort An SQL query indicating how to sort the results
 * @param string $membergroup_type Should be 'post_count' for post groups or 'regular' for other groups
 * @param int $user_id id of the member making the request
 * @param bool $include_hidden If true includes hidden groups if the user has permission
 * @param bool $include_all If true includes all groups the user can see
 * @param bool $aggregate
 * @param bool $count_permissions
 * @param int|null $pid - profile id
 *
 * @return array
 * @package Membergroups
 *
 */
function list_getMembergroups($start, $items_per_page, $sort, $membergroup_type, $user_id, $include_hidden, $include_all = false, $aggregate = false, $count_permissions = false, $pid = null)
{
	global $txt, $context;

	$db = database();
	Txt::load('Admin');

	// Start collecting the data.
	$groups = array();
	$group_ids = array();
	$parent_groups = array();

	if ($membergroup_type === 'all')
	{
		// Determine the number of ungrouped members.
		$num_members = countMembersInGroup(0);

		// Fill the context variable with 'Guests' and 'Regular Members'.
		$groups = array(
			-1 => array(
				'id_group' => -1,
				'group_name' => $txt['membergroups_guests'],
				'group_name_color' => $txt['membergroups_guests'],
				'min_posts' => 0,
				'desc' => '',
				'num_members' => $txt['membergroups_guests_na'],
				'icons' => '',
				'can_search' => false,
				'id_parent' => -2,
				'num_permissions' => array(
					'allowed' => 0,
					'denied' => 0,
				)
			),
			0 => array(
				'id_group' => 0,
				'group_name' => $txt['membergroups_members'],
				'group_name_color' => $txt['membergroups_members'],
				'min_posts' => 0,
				'desc' => '',
				'num_members' => $num_members,
				'icons' => '',
				'can_search' => true,
				'id_parent' => -2,
				'num_permissions' => array(
					'allowed' => 0,
					'denied' => 0,
				)
			),
		);
	}

	$db->fetchQuery('
		SELECT 
			mg.id_group, mg.group_name, mg.min_posts, mg.description, mg.group_type, mg.online_color,
			mg.hidden, mg.id_parent, mg.icons, COALESCE(gm.id_member, 0) AS can_moderate, 0 AS num_members
		FROM {db_prefix}membergroups AS mg
			LEFT JOIN {db_prefix}group_moderators AS gm ON (gm.id_group = mg.id_group AND gm.id_member = {int:current_member})
		WHERE mg.min_posts {raw:min_posts}' . ($include_all ? '' : '
			AND mg.id_group != {int:mod_group}
			AND mg.group_type != {int:is_protected}') . '
		ORDER BY {raw:sort}',
		array(
			'current_member' => $user_id,
			'min_posts' => ($membergroup_type === 'post_count' ? '!= -1' : '= -1'),
			'mod_group' => 3,
			'is_protected' => 1,
			'sort' => $sort,
		)
	)->fetch_callback(
		function ($row) use (&$parent_groups, &$groups, &$group_ids, &$include_hidden, $count_permissions, $aggregate) {
			global $txt;

			// We only list the groups they can see.
			if ($row['hidden'] && !$row['can_moderate'] && !$include_hidden)
			{
				return;
			}

			if ($row['id_parent'] != -2)
			{
				$parent_groups[] = $row['id_parent'];
			}

			// If it's inherited, just add it as a child.
			if ($aggregate && $row['id_parent'] != -2)
			{
				if (isset($groups[$row['id_parent']]))
				{
					$groups[$row['id_parent']]['children'][$row['id_group']] = $row['group_name'];
				}

				return;
			}

			$row['icons'] = explode('#', $row['icons']);

			$groups[$row['id_group']] = array(
				'id_group' => $row['id_group'],
				'group_name' => $row['group_name'],
				'group_name_color' => empty($row['online_color']) ? $row['group_name'] : '<span style="color: ' . $row['online_color'] . '">' . $row['group_name'] . '</span>',
				'min_posts' => $row['min_posts'],
				'desc' => $row['description'],
				'online_color' => $row['online_color'],
				'type' => $row['group_type'],
				'num_members' => $row['num_members'],
				'moderators' => array(),
				'icons' => $row['icons'],
				'can_search' => $row['id_group'] != 3,
				'id_parent' => $row['id_parent'],
			);

			if ($count_permissions)
			{
				$groups[$row['id_group']]['num_permissions'] = array(
					'allowed' => $row['id_group'] == 1 ? '(' . $txt['permissions_all'] . ')' : 0,
					'denied' => $row['id_group'] == 1 ? '(' . $txt['permissions_none'] . ')' : 0,
				);
			}

			$include_hidden |= $row['can_moderate'];
			$group_ids[] = $row['id_group'];
		}
	);

	// If we found any membergroups, get the amount of members in them.
	if (!empty($group_ids))
	{
		if ($membergroup_type === 'post_count')
		{
			$groups_count = membersInGroups($group_ids);
		}
		else
		{
			$groups_count = membersInGroups(array(), $group_ids, $include_hidden);
		}

		// @todo not sure why += wouldn't = be enough?
		foreach ($groups_count as $group_id => $num_members)
		{
			$groups[$group_id]['num_members'] += $num_members;
		}

		$db->fetchQuery('
			SELECT 
				mods.id_group, mods.id_member, mem.member_name, mem.real_name
			FROM {db_prefix}group_moderators AS mods
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
			WHERE mods.id_group IN ({array_int:group_list})',
			array(
				'group_list' => $group_ids,
			)
		)->fetch_callback(
			function ($row) use (&$groups) {
				$groups[$row['id_group']]['moderators'][] = '<a href="' . getUrl('profile', ['action' => 'profile', 'u' => $row['id_member'], 'name' => $row['real_name']]) . '">' . $row['real_name'] . '</a>';
			}
		);
	}

	if (!empty($parent_groups))
	{
		$all_group_names = array(
			-1 => $txt['membergroups_guests'],
			0 => $txt['membergroups_members']
		);
		$db->fetchQuery('
			SELECT 
				id_group, group_name
			FROM {db_prefix}membergroups
			WHERE id_group IN ({array_int:groups})',
			array(
				'groups' => $parent_groups,
			)
		)->fetch_callback(
			function ($row) use (&$all_group_names) {
				$all_group_names[$row['id_group']] = $row['group_name'];
			}
		);
	}
	foreach ($groups as $key => $group)
	{
		if ($group['id_parent'] != -2)
		{
			$groups[$key]['parent_name'] = $all_group_names[$group['id_parent']];
		}
	}

	// Apply manual sorting if the 'number of members' column is selected.
	if (substr($sort, 0, 1) === '1' || strpos($sort, ', 1') !== false)
	{
		$sort_ascending = strpos($sort, 'DESC') === false;
		$sort_array = array();

		foreach ($groups as $group)
		{
			$sort_array[] = $group['id_group'] != 3 ? (int) $group['num_members'] : -1;
		}

		array_multisort($sort_array, $sort_ascending ? SORT_ASC : SORT_DESC, SORT_REGULAR, $groups);
	}

	if ($count_permissions)
	{
		// pid = profile id
		if (empty($pid))
		{
			$groups = countPermissions($groups, $context['hidden_permissions']);

			// Get the "default" profile permissions too.
			$groups = countBoardPermissions($groups, $context['hidden_permissions'], 1);
		}
		else
		{
			$groups = countBoardPermissions($groups, null, $pid);
		}
	}

	return $groups;
}

/**
 * Count the number of members in specific groups
 *
 * @param int[] $postGroups an array of post-based groups id.
 * @param int[] $normalGroups = array() an array of normal groups id.
 * @param bool $include_hidden if true, includes hidden groups in the count (default false).
 * @param bool $include_moderators if true, includes board moderators too (default false).
 * @param bool $include_non_active if true, includes non active members (default false).
 * @return array
 * @package Membergroups
 */
function membersInGroups($postGroups, $normalGroups = array(), $include_hidden = false, $include_moderators = false, $include_non_active = false)
{
	$db = database();

	$groups = array();

	// If we have post groups, let's count the number of members...
	if (!empty($postGroups))
	{
		$db->fetchQuery('
			SELECT 
				id_post_group AS id_group, COUNT(*) AS member_count
			FROM {db_prefix}members
			WHERE id_post_group IN ({array_int:post_group_list})' . ($include_non_active ? '' : '
				AND is_activated = {int:active_members}') . '
			GROUP BY id_post_group',
			array(
				'post_group_list' => $postGroups,
				'active_members' => 1,
			)
		)->fetch_callback(
			function ($row) use (&$groups) {
				$groups[$row['id_group']] = $row['member_count'];
			}
		);
	}

	if (!empty($normalGroups))
	{
		// Find people who are members of this group...
		$db->fetchQuery('
			SELECT 
				id_group, COUNT(*) AS member_count
			FROM {db_prefix}members
			WHERE id_group IN ({array_int:normal_group_list})' . ($include_non_active ? '' : '
				AND is_activated = {int:active_members}') . '
			GROUP BY id_group',
			array(
				'normal_group_list' => $normalGroups,
				'active_members' => 1,
			)
		)->fetch_callback(
			function ($row) use (&$groups) {
				$groups[$row['id_group']] = $row['member_count'];
			}
		);

		// Only do additional groups if we can moderate...
		if ($include_hidden)
		{
			// Also do those who have it as an additional membergroup - this ones more yucky...
			$db->fetchQuery('
				SELECT 
					mg.id_group, COUNT(*) AS member_count
				FROM {db_prefix}membergroups AS mg
					INNER JOIN {db_prefix}members AS mem ON (mem.additional_groups != {string:blank_string}
						AND mem.id_group != mg.id_group
						AND FIND_IN_SET(mg.id_group, mem.additional_groups) != 0)
				WHERE mg.id_group IN ({array_int:normal_group_list})' . ($include_non_active ? '' : '
					AND mem.is_activated = {int:active_members}') . '
				GROUP BY mg.id_group',
				array(
					'normal_group_list' => $normalGroups,
					'active_members' => 1,
					'blank_string' => '',
				)
			)->fetch_callback(
				function ($row) use (&$groups) {
					if (isset($groups[$row['id_group']]))
					{
						$groups[$row['id_group']] += $row['member_count'];
					}
					else
					{
						$groups[$row['id_group']] = $row['member_count'];
					}
				}
			);
		}
	}

	if ($include_moderators)
	{
		// Any moderators?
		$request = $db->query('', '
			SELECT 
				COUNT(DISTINCT id_member) AS num_distinct_mods
			FROM {db_prefix}moderators
			LIMIT 1',
			array()
		);
		list ($groups[3]) = $request->fetch_row();
		$request->free_result();
	}

	return $groups;
}

/**
 * Returns details of membergroups based on the id
 *
 * @param int[]|int $group_ids the IDs of the groups.
 * @param int $limit = 1 the number of results returned (default 1, if null/false/0 returns all).
 * @param bool $detailed = false if true then it returns more fields (default false).
 *     false returns: id_group, group_name, group_type.
 *     true adds to above: description, min_posts, online_color, max_messages, icons, hidden, id_parent.
 * @param bool $assignable = false determine if the group is assignable or not and return that information.
 * @return array
 * @package Membergroups
 */
function membergroupsById($group_ids, $limit = 1, $detailed = false, $assignable = false)
{
	$db = database();

	if (empty($group_ids))
	{
		return [];
	}

	$group_ids = !is_array($group_ids) ? array($group_ids) : $group_ids;

	$groups = array();
	$group_ids = array_map('intval', $group_ids);

	$db->fetchQuery('
		SELECT 
			id_group, group_name, group_type' . (!$detailed ? '' : ',
			description, min_posts, online_color, max_messages, icons, hidden, id_parent') . (!$assignable ? '' : ',
			CASE WHEN min_posts = {int:min_posts} THEN 1 ELSE 0 END AS assignable,
			CASE WHEN min_posts != {int:min_posts} THEN 1 ELSE 0 END AS is_post_group') . '
		FROM {db_prefix}membergroups
		WHERE id_group IN ({array_int:group_ids})' . (empty($limit) ? '' : '
		LIMIT {int:limit}'),
		array(
			'min_posts' => -1,
			'group_ids' => $group_ids,
			'limit' => $limit,
		)
	)->fetch_callback(
		function ($row) use (&$groups) {
			$groups[$row['id_group']] = $row;
		}
	);

	return $groups;
}

/**
 * Uses membergroupsById to return the group information of a single group
 *
 * @param int $group_id
 * @param bool $detailed
 * @param bool $assignable
 *
 * @return bool|mixed
 * @package Membergroups
 *
 */
function membergroupById($group_id, $detailed = false, $assignable = false)
{
	$groups = membergroupsById(array($group_id), 1, $detailed, $assignable);

	return $groups[$group_id] ?? false;
}

/**
 * Gets basic membergroup data
 *
 * - the $includes and $excludes array is used for granular filtering the output.
 * - We need to exclude groups sometimes because they are special ones.
 * Example: getBasicMembergroupData(array('admin', 'mod', 'globalmod'));
 * $includes parameters:
 * - 'admin' includes the admin: id_group = 1
 * - 'mod' includes the local moderator: id_group = 3
 * - 'globalmod' includes the global moderators: id_group = 2
 * - 'member' includes the ungrouped users from id_group = 0
 * - 'postgroups' includes the post based membergroups
 * - 'protected' includes protected groups
 * - 'all' lists all groups
 * $excludes parameters:
 * - 'newbie' excludes the newbie group id_group 4
 * - 'custom' lists only the system based groups (id 0, 1, 2, 3)
 * - 'membergroups' excludes permission groups, lists the post based membergroups
 * - 'hidden' excludes hidden groups
 *
 * @param string[]|string $includes
 * @param string[] $excludes
 * @param string|null $sort_order
 * @param bool|null $split splits postgroups and membergroups
 * @return array
 * @package Membergroups
 */
function getBasicMembergroupData($includes = array(), $excludes = array(), $sort_order = null, $split = null)
{
	global $txt, $modSettings;

	$db = database();

	// No $includes parameters given? Let's set some default values
	if (empty($includes))
	{
		$includes = array('globalmod', 'member', 'postgroups');
	}
	elseif (!is_array($includes))
	{
		$includes = array($includes);
	}

	$groups = array();

	$where = '';
	$sort_order = $sort_order ?? 'min_posts, CASE WHEN id_group < {int:newbie_group} THEN id_group ELSE 4 END, group_name';

	// Do we need the post based membergroups?
	$where .= !empty($modSettings['permission_enable_postgroups']) || in_array('postgroups', $includes) ? '' : 'AND min_posts = {int:min_posts}';
	// Include protected groups?
	$where .= allowedTo('admin_forum') || in_array('protected', $includes) ? '' : ' AND group_type != {int:is_protected}';
	// Include the global moderators?
	$where .= in_array('globalmod', $includes) ? '' : ' AND id_group != {int:global_mod_group}';
	// Include the admins?
	$where .= in_array('admin', $includes) ? '' : ' AND id_group != {int:admin_group}';
	// Local Moderators?
	$where .= in_array('mod', $includes) ? '' : ' AND id_group != {int:moderator_group}';
	// Ignore the first post based group?
	$where .= !in_array('newbie', $excludes) ? '' : ' AND id_group != {int:newbie_group}';
	// Exclude custom groups?
	$where .= !in_array('custom', $excludes) ? '' : ' AND id_group < {int:newbie_group}';
	// Exclude hidden?
	$where .= !in_array('hidden', $excludes) ? '' : ' AND hidden != {int:hidden_group}';

	// Only the post based membergroups? We can safely overwrite the $where.
	if (in_array('membergroups', $excludes))
	{
		$where = ' AND min_posts != {int:min_posts}';
	}

	// Simply all of them?
	if (in_array('all', $includes))
	{
		$where = '';
	}

	$request = $db->query('', '
		SELECT 
			id_group, group_name, min_posts, online_color
		FROM {db_prefix}membergroups
		WHERE 1 = 1
			' . $where . '
		ORDER BY ' . $sort_order,
		array(
			'admin_group' => 1,
			'moderator_group' => 3,
			'global_mod_group' => 2,
			'min_posts' => -1,
			'is_protected' => 1,
			'newbie_group' => 4,
			'hidden_group' => 2,
		)
	);

	// Include the default membergroup? the ones with id_member = 0
	if (in_array('member', $includes) && !isset($split))
	{
		$groups[] = array(
			'id' => 0,
			'name' => $txt['membergroups_members']
		);
	}

	if (!empty($split))
	{
		if (empty($modSettings['permission_enable_postgroups']))
		{
			$groups['groups'][0] = array(
				'id' => 0,
				'name' => $txt['membergroups_members'],
				'can_be_additional' => false,
				'member_count' => 0,
			);
			$groups['membergroups'][0] = array(
				'id' => 0,
				'name' => $txt['membergroups_members'],
				'can_be_additional' => false,
				'member_count' => 0,
			);
		}
		while (($row = $request->fetch_assoc()))
		{
			$groups['groups'][$row['id_group']] = array(
				'id' => $row['id_group'],
				'name' => $row['group_name'],
				'member_count' => 0,
			);

			if ($row['min_posts'] == -1)
			{
				$groups['membergroups'][] = array(
					'id' => $row['id_group'],
					'name' => $row['group_name'],
					'can_be_additional' => true,
				);
			}
			else
			{
				$groups['postgroups'][] = array(
					'id' => $row['id_group'],
					'name' => $row['group_name'],
				);
			}
		}
	}
	else
	{
		while (($row = $request->fetch_assoc()))
		{
			$groups[] = array(
				'id' => $row['id_group'],
				'name' => $row['group_name'],
				'online_color' => $row['online_color'],
			);
		}
	}

	$request->free_result();

	return $groups;
}

/**
 * Retrieve groups and their number of members.
 *
 * @param int[] $groupList
 * @return array with ('id', 'name', 'member_count')
 * @package Membergroups
 */
function getGroups($groupList)
{
	global $txt;

	$db = database();

	$groups = array();
	if (in_array(0, $groupList))
	{
		$groups[0] = array(
			'id' => 0,
			'name' => $txt['announce_regular_members'],
			'member_count' => 'n/a',
		);
	}

	// Get all membergroups that have access to the board the announcement was made on.
	$db->fetchQuery('
		SELECT 
			mg.id_group, mg.group_name, COUNT(mem.id_member) AS num_members
		FROM {db_prefix}membergroups AS mg
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_group = mg.id_group OR FIND_IN_SET(mg.id_group, mem.additional_groups) != 0 OR mg.id_group = mem.id_post_group)
		WHERE mg.id_group IN ({array_int:group_list})
		GROUP BY mg.id_group, mg.group_name',
		array(
			'group_list' => $groupList,
		)
	)->fetch_callback(
		function ($row) use (&$groups) {
			$groups[$row['id_group']] = array(
				'id' => $row['id_group'],
				'name' => $row['group_name'],
				'member_count' => $row['num_members'],
			);
		}
	);

	return $groups;
}

/**
 * Gets the last assigned group id.
 *
 * @return int $id_group
 * @package Membergroups
 */
function getMaxGroupID()
{
	$db = database();

	$request = $db->query('', '
		SELECT 
			MAX(id_group)
		FROM {db_prefix}membergroups',
		array()
	);
	list ($id_group) = $request->fetch_row();
	$request->free_result();

	return $id_group;
}

/**
 * Adds a new group to the membergroups table.
 *
 * @param string $groupname
 * @param int $minposts
 * @param string $type
 * @package Membergroups
 */
function createMembergroup($groupname, $minposts, $type)
{
	$db = database();

	$db->insert('',
		'{db_prefix}membergroups',
		array(
			'description' => 'string', 'group_name' => 'string-80', 'min_posts' => 'int',
			'icons' => 'string', 'online_color' => 'string', 'group_type' => 'int',
		),
		array(
			'', Util::htmlspecialchars($groupname, ENT_QUOTES), $minposts,
			'1#icon.png', '', $type,
		),
		array('id_group')
	);

	return $db->insert_id('{db_prefix}membergroups');
}

/**
 * Copies permissions from a given membergroup.
 *
 * @param int $id_group
 * @param int $copy_from
 * @param string[]|null $illegal_permissions
 * @todo another function with the same name in ManagePermissions.subs.php
 * @package Membergroups
 */
function copyPermissions($id_group, $copy_from, $illegal_permissions)
{
	$db = database();

	$inserts = array();

	$db->fetchQuery('
		SELECT 
			permission, add_deny
		FROM {db_prefix}permissions
		WHERE id_group = {int:copy_from}',
		array(
			'copy_from' => $copy_from,
		)
	)->fetch_callback(
		function ($row) use (&$inserts, $illegal_permissions, $id_group) {
			if (empty($illegal_permissions) || !in_array($row['permission'], $illegal_permissions))
			{
				$inserts[] = array($id_group, $row['permission'], $row['add_deny']);
			}
		}
	);

	if (!empty($inserts))
	{
		$db->insert('insert',
			'{db_prefix}permissions',
			array('id_group' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
			$inserts,
			array('id_group', 'permission')
		);
	}
}

/**
 * Copies the board permissions from a given membergroup.
 *
 * @param int $id_group
 * @param int $copy_from
 * @package Membergroups
 */
function copyBoardPermissions($id_group, $copy_from)
{
	$db = database();

	$inserts = $db->fetchQuery('
		SELECT 
			id_profile, permission, add_deny
		FROM {db_prefix}board_permissions
		WHERE id_group = {int:copy_from}',
		array(
			'copy_from' => $copy_from,
		)
	)->fetch_callback(
		function ($row) use ($id_group) {
			return array($id_group, $row['id_profile'], $row['permission'], $row['add_deny']);
		}
	);

	if (!empty($inserts))
	{
		$db->insert('insert',
			'{db_prefix}board_permissions',
			array('id_group' => 'int', 'id_profile' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
			$inserts,
			array('id_group', 'id_profile', 'permission')
		);
	}
}

/**
 * Updates the properties of a copied membergroup.
 *
 * @param int $id_group
 * @param int $copy_from
 * @package Membergroups
 */
function updateCopiedGroup($id_group, $copy_from)
{
	$db = database();

	require_once(SUBSDIR . '/Membergroups.subs.php');
	$group_info = membergroupById($copy_from, true);

	// update the new membergroup
	$db->query('', '
		UPDATE {db_prefix}membergroups
		SET
			online_color = {string:online_color},
			max_messages = {int:max_messages},
			icons = {string:icons}
			WHERE id_group = {int:current_group}',
		array(
			'max_messages' => $group_info['max_messages'],
			'current_group' => $id_group,
			'online_color' => $group_info['online_color'],
			'icons' => $group_info['icons'],
		)
	);
}

/**
 * Updates the properties of a inherited membergroup.
 *
 * @param int $id_group
 * @param int $copy_id
 * @package Membergroups
 */
function updateInheritedGroup($id_group, $copy_id)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}membergroups
		SET id_parent = {int:copy_from}
		WHERE id_group = {int:current_group}',
		array(
			'copy_from' => $copy_id,
			'current_group' => $id_group,
		)
	);
}

/**
 * This function updates the membergroup with the given information.
 *
 * - It's passed an associative array $properties, with 'current_group' holding
 * the group to update. The rest of the keys are details to update it with.
 *
 * @param mixed[] $properties
 * @package Membergroups
 */
function updateMembergroupProperties($properties)
{
	$db = database();

	$known_properties = array(
		'max_messages' => array('type' => 'int'),
		'min_posts' => array('type' => 'int'),
		'group_type' => array('type' => 'int'),
		'hidden' => array('type' => 'int'),
		'id_parent' => array('type' => 'int'),
		'group_name' => array('type' => 'string'),
		'online_color' => array('type' => 'string'),
		'icons' => array('type' => 'string'),
		'description' => array('type' => 'string'),
	);

	$values = array('current_group' => $properties['current_group']);
	$updates = array();
	foreach ($properties as $name => $value)
	{
		if (isset($known_properties[$name]))
		{
			$updates[] = $name . '={' . $known_properties[$name]['type'] . ':subs_' . $name . '}';
			switch ($known_properties[$name]['type'])
			{
				case 'string':
					$values['subs_' . $name] = Util::htmlspecialchars((string) $value);
					break;
				default:
					$values['subs_' . $name] = (int) $value;
			}
		}
	}

	if (empty($values))
	{
		return;
	}

	$db->query('', '
		UPDATE {db_prefix}membergroups
		SET ' . implode(', ', $updates) . '
		WHERE id_group = {int:current_group}',
		$values
	);
}

/**
 * Detaches a membergroup from the boards listed in $boards.
 *
 * @param int $id_group
 * @param mixed[] $boards
 * @param string $access_list ('allow', 'deny')
 * @package Membergroups
 */
function detachGroupFromBoards($id_group, $boards, $access_list)
{
	$db = database();

	// Find all boards in whose access list this group is in, but shouldn't be.
	$db->fetchQuery('
		SELECT 
			id_board, {raw:column}
		FROM {db_prefix}boards
		WHERE FIND_IN_SET({string:current_group}, {raw:column}) != 0' . (empty($boards[$access_list]) ? '' : '
			AND id_board NOT IN ({array_int:board_access_list})'),
		array(
			'current_group' => $id_group,
			'board_access_list' => $boards[$access_list],
			'column' => $access_list === 'allow' ? 'member_groups' : 'deny_member_groups',
		)
	)->fetch_callback(
		function ($row) use ($id_group, $access_list, $db) {
			$db->query('', '
				UPDATE {db_prefix}boards
				SET {raw:column} = {string:member_group_access}
				WHERE id_board = {int:current_board}',
				array(
					'current_board' => $row['id_board'],
					'member_group_access' => implode(',', array_diff(explode(',', $row['member_groups']), array($id_group))),
					'column' => $access_list === 'allow' ? 'member_groups' : 'deny_member_groups',
				)
			);
		}
	);
}

/**
 * Assigns the given group $id_group to the boards specified, for
 * the 'allow' or 'deny' list.
 *
 * @param int $id_group
 * @param mixed[] $boards
 * @param string $access_list ('allow', 'deny')
 * @package Membergroups
 */
function assignGroupToBoards($id_group, $boards, $access_list)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}boards
		SET {raw:column} = CASE WHEN {raw:column} = {string:blank_string} THEN {string:group_id_string} ELSE CONCAT({raw:column}, {string:comma_group}) END
		WHERE id_board IN ({array_int:board_list})
			AND FIND_IN_SET({int:current_group}, {raw:column}) = 0',
		array(
			'board_list' => $boards[$access_list],
			'blank_string' => '',
			'current_group' => $id_group,
			'group_id_string' => (string) $id_group,
			'comma_group' => ',' . $id_group,
			'column' => $access_list === 'allow' ? 'member_groups' : 'deny_member_groups',
		)
	);
}

/**
 * Membergroup was deleted? We need to detach that group from our members, too...
 *
 * @param int $id_group
 * @package Membergroups
 */
function detachDeletedGroupFromMembers($id_group)
{
	$db = database();

	$updates = array();

	$db->query('', '
		UPDATE {db_prefix}members
		SET id_group = {int:regular_member}
		WHERE id_group = {int:current_group}',
		array(
			'regular_member' => 0,
			'current_group' => $id_group,
		)
	);

	$db->fetchQuery('
		SELECT 
			id_member, additional_groups
		FROM {db_prefix}members
		WHERE FIND_IN_SET({string:current_group}, additional_groups) != 0',
		array(
			'current_group' => $id_group,
		)
	)->fetch_callback(
		function ($row) use (&$updates) {
			$updates[$row['additional_groups']][] = $row['id_member'];
		}
	);

	require_once(SUBSDIR . '/Members.subs.php');
	foreach ($updates as $additional_groups => $memberArray)
	{
		updateMemberData($memberArray, array('additional_groups' => implode(',', array_diff(explode(',', $additional_groups), array($id_group)))));
	}

}

/**
 * Make the given group hidden. Hidden groups are stored in the additional_groups.
 *
 * @param int $id_group
 * @package Membergroups
 */
function setGroupToHidden($id_group)
{
	$db = database();

	$updates = array();

	$db->fetchQuery('
		SELECT 
			id_member, additional_groups
		FROM {db_prefix}members
		WHERE id_group = {int:current_group}
			AND FIND_IN_SET({int:current_group}, additional_groups) = 0',
		array(
			'current_group' => $id_group,
		)
	)->fetch_callback(
		function ($row) use (&$updates) {
			$updates[$row['additional_groups']][] = $row['id_member'];
		}
	);

	require_once(SUBSDIR . '/Members.subs.php');
	foreach ($updates as $additional_groups => $memberArray)
	{
		updateMemberData($memberArray, array('additional_groups' => implode(',', array_merge(explode(',', $additional_groups), array($id_group)))));
	}

	$db->query('', '
		UPDATE {db_prefix}members
		SET id_group = {int:regular_member}
		WHERE id_group = {int:current_group}',
		array(
			'regular_member' => 0,
			'current_group' => $id_group,
		)
	);
}

/**
 * Make sure the setting to display membergroup key on the board index is valid.
 * It updates the setting if necessary.
 *
 * @package Membergroups
 */
function validateShowGroupMembership()
{
	global $modSettings;

	$db = database();

	$request = $db->query('', '
		SELECT
		 	COUNT(*)
		FROM {db_prefix}membergroups
		WHERE group_type > {int:non_joinable}',
		array(
			'non_joinable' => 1,
		)
	);
	list ($have_joinable) = $request->fetch_row();
	$request->free_result();

	// Do we need to update the setting?
	if ((empty($modSettings['show_group_membership']) && $have_joinable) || (!empty($modSettings['show_group_membership']) && !$have_joinable))
	{
		updateSettings(array('show_group_membership' => $have_joinable ? 1 : 0));
	}
}

/**
 * Detaches group moderators from a deleted group.
 *
 * @param int $id_group
 * @package Membergroups
 */
function detachGroupModerators($id_group)
{
	$db = database();

	$db->query('', '
		DELETE FROM {db_prefix}group_moderators
		WHERE id_group = {int:current_group}',
		array(
			'current_group' => $id_group,
		)
	);
}

/**
 * Get the id_member from the membergroup moderators.
 *
 * @param string[] $moderators
 *
 * @return int[]
 * @package Membergroups
 */
function getIDMemberFromGroupModerators($moderators)
{
	$db = database();

	return $db->fetchQuery('
		SELECT 
			id_member
		FROM {db_prefix}members
		WHERE member_name IN ({array_string:moderators}) OR real_name IN ({array_string:moderators})
		LIMIT ' . count($moderators),
		array(
			'moderators' => $moderators,
		)
	)->fetch_callback(
		function ($row) {
			return $row['id_member'];
		}
	);
}

/**
 * Assign members to the membergroup moderators.
 *
 * @param int $id_group
 * @param int[] $group_moderators
 * @package Membergroups
 */
function assignGroupModerators($id_group, $group_moderators)
{
	$db = database();

	$mod_insert = array();
	foreach ($group_moderators as $moderator)
	{
		$mod_insert[] = array($id_group, $moderator);
	}

	$db->insert('insert',
		'{db_prefix}group_moderators',
		array('id_group' => 'int', 'id_member' => 'int'),
		$mod_insert,
		array('id_group', 'id_member')
	);
}

/**
 * List moderators from a given membergroup.
 *
 * @param int $id_group
 * @return array moderators as array(id => name)
 * @package Membergroups
 */
function getGroupModerators($id_group)
{
	$db = database();

	$moderators = array();

	$db->fetchQuery('
		SELECT 
			mem.id_member, mem.real_name
		FROM {db_prefix}group_moderators AS mods
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
		WHERE mods.id_group = {int:current_group}',
		array(
			'current_group' => $id_group,
		)
	)->fetch_callback(
		function ($row) use (&$moderators) {
			$moderators[$row['id_member']] = $row['real_name'];
		}
	);

	return $moderators;
}

/**
 * Lists all groups which inherit permission profiles from the given group.
 *
 * - If no group is specified it will list any group that can be used
 *
 * @param int|bool $id_group
 * @return array
 * @package Membergroups
 */
function getInheritableGroups($id_group = false)
{
	global $modSettings;

	$db = database();

	$inheritable_groups = array();

	$db->fetchQuery('
		SELECT 
			id_group, group_name
		FROM {db_prefix}membergroups
		WHERE id_parent = {int:not_inherited}' . ($id_group === false ? '' : '
			AND id_group != {int:current_group}') .
		(empty($modSettings['permission_enable_postgroups']) ? '
			AND min_posts = {int:min_posts}' : '') . (allowedTo('admin_forum') ? '' : '
			AND group_type != {int:is_protected}') . '
			AND id_group NOT IN (1, 3)',
		array(
			'current_group' => $id_group,
			'min_posts' => -1,
			'not_inherited' => -2,
			'is_protected' => 1,
		)
	)->fetch_callback(
		function ($row) use (&$inheritable_groups) {
			$inheritable_groups[$row['id_group']] = $row['group_name'];
		}
	);

	return $inheritable_groups;
}

/**
 * List all membergroups and prepares them to assign permissions to..
 *
 * @return array
 * @package Membergroups
 */
function prepareMembergroupPermissions()
{
	global $modSettings, $txt;

	$db = database();

	// Start this with the guests/members.
	$profile_groups = array(
		-1 => array(
			'id' => -1,
			'name' => $txt['membergroups_guests'],
			'color' => '',
			'new_topic' => 'disallow',
			'replies_own' => 'disallow',
			'replies_any' => 'disallow',
			'attachment' => 'disallow',
			'children' => array(),
		),
		0 => array(
			'id' => 0,
			'name' => $txt['membergroups_members'],
			'color' => '',
			'new_topic' => 'disallow',
			'replies_own' => 'disallow',
			'replies_any' => 'disallow',
			'attachment' => 'disallow',
			'children' => array(),
		),
	);

	$db->fetchQuery('
		SELECT 
			id_group, group_name, online_color, id_parent
		FROM {db_prefix}membergroups
		WHERE id_group != {int:admin_group}
			' . (empty($modSettings['permission_enable_postgroups']) ? ' AND min_posts = {int:min_posts}' : '') . '
		ORDER BY id_parent ASC',
		array(
			'admin_group' => 1,
			'min_posts' => -1,
		)
	)->fetch_callback(
		function ($row) use (&$profile_groups) {
			if ($row['id_parent'] == -2)
			{
				$profile_groups[$row['id_group']] = array(
					'id' => $row['id_group'],
					'name' => $row['group_name'],
					'color' => $row['online_color'],
					'new_topic' => 'disallow',
					'replies_own' => 'disallow',
					'replies_any' => 'disallow',
					'attachment' => 'disallow',
					'children' => array(),
				);
			}
			elseif (isset($profile_groups[$row['id_parent']]))
			{
				$profile_groups[$row['id_parent']]['children'][] = $row['group_name'];
			}
		}
	);

	return $profile_groups;
}

/**
 * Returns the groups that a user could see.
 *
 * - Ask and it will give you.
 *
 * @param int $id_member the id of a member
 * @param bool $show_hidden true if hidden groups (that the user can moderate) should be loaded (default false)
 * @param int $min_posts minimum number of posts for the group (-1 for non-post based groups)
 *
 * @return array
 * @package Membergroups
 */
function loadGroups($id_member, $show_hidden = false, $min_posts = -1)
{
	$db = database();

	$groups = array();
	$db->fetchQuery('
		SELECT 
			mg.id_group, mg.group_name, COALESCE(gm.id_member, 0) AS can_moderate, mg.hidden
		FROM {db_prefix}membergroups AS mg
			LEFT JOIN {db_prefix}group_moderators AS gm ON (gm.id_group = mg.id_group AND gm.id_member = {int:current_member})
		WHERE mg.min_posts = {int:min_posts}
			AND mg.id_group != {int:moderator_group}' . ($show_hidden ? '' : '
			AND mg.hidden = {int:not_hidden}') . '
		ORDER BY mg.group_name',
		array(
			'current_member' => $id_member,
			'min_posts' => $min_posts,
			'moderator_group' => 3,
			'not_hidden' => 0,
		)
	)->fetch_callback(
		function ($row) use (&$groups, $show_hidden) {
			// Hide hidden groups!
			if ($show_hidden && $row['hidden'] && !$row['can_moderate'])
			{
				return;
			}

			$groups[$row['id_group']] = $row['group_name'];
		}
	);

	return $groups;
}

/**
 * Returns the groups that the current user can see.
 *
 * - uses User::$info and allowedTo().
 * - does not include post count based groups
 *
 * @return array
 * @package Membergroups
 */
function accessibleGroups()
{
	$db = database();

	$groups = array();
	$db->fetchQuery('
		SELECT 
			mg.id_group, mg.group_name, COALESCE(gm.id_member, 0) AS can_moderate, mg.hidden
		FROM {db_prefix}membergroups AS mg
			LEFT JOIN {db_prefix}group_moderators AS gm ON (gm.id_group = mg.id_group AND gm.id_member = {int:current_member})
		WHERE mg.min_posts = {int:min_posts}
			AND mg.id_group != {int:moderator_group}',
		array(
			'current_member' => User::$info->id,
			'min_posts' => -1,
			'moderator_group' => 3,
		)
	)->fetch_callback(
		function ($row) use (&$groups) {
			// Hide hidden groups!
			if ($row['hidden'] && !$row['can_moderate'] && !allowedTo('manage_membergroups'))
			{
				return;
			}

			$groups[$row['id_group']] = $row['group_name'];
		}
	);

	asort($groups);

	return $groups;
}

/**
 * Finds the number of group requests in the system
 *
 * - Callback function for createList().
 *
 * @param string $where
 * @param string[] $where_parameters
 * @return int the count of group requests
 * @package Membergroups
 */
function list_getGroupRequestCount($where, $where_parameters)
{
	$db = database();

	$request = $db->query('', '
		SELECT 
			COUNT(*)
		FROM {db_prefix}log_group_requests AS lgr
		WHERE ' . $where,
		array_merge($where_parameters, array())
	);
	list ($totalRequests) = $request->fetch_row();
	$request->free_result();

	return $totalRequests;
}

/**
 * Find the details of pending group requests
 *
 * - Callback function for createList()
 *
 * @param int $start The item to start with (for pagination purposes)
 * @param int $items_per_page The number of items to show per page
 * @param string $sort A string indicating how to sort the results
 * @param string $where
 * @param string[] $where_parameters
 * @return mixed[] an array of group requests
 * Each group request has:
 *   'id'
 *   'member_link'
 *   'group_link'
 *   'reason'
 *   'time_submitted'
 * @package Membergroups
 */
function list_getGroupRequests($start, $items_per_page, $sort, $where, $where_parameters)
{
	$db = database();

	return $db->fetchQuery('
		SELECT 
			lgr.id_request, lgr.id_member, lgr.id_group, lgr.time_applied, lgr.reason,
			mem.member_name, mg.group_name, mg.online_color, mem.real_name
		FROM {db_prefix}log_group_requests AS lgr
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = lgr.id_member)
			INNER JOIN {db_prefix}membergroups AS mg ON (mg.id_group = lgr.id_group)
		WHERE ' . $where . '
		ORDER BY {raw:sort}
		LIMIT ' . $items_per_page . '  OFFSET ' . $start,
		array_merge($where_parameters, array(
			'sort' => $sort,
		))
	)->fetch_callback(
		function ($row) {
			return array(
				'id' => $row['id_request'],
				'member_link' => '<a href="' . getUrl('profile', ['action' => 'profile', 'u' => $row['id_member'], 'name' => $row['real_name']]) . '">' . $row['real_name'] . '</a>',
				'group_link' => '<span style="color: ' . $row['online_color'] . '">' . $row['group_name'] . '</span>',
				'reason' => censor($row['reason']),
				'time_submitted' => standardTime($row['time_applied']),
			);
		}
	);
}

/**
 * Deletes old group requests.
 *
 * @param int[] $groups
 * @package Membergroups
 */
function deleteGroupRequests($groups)
{
	$db = database();

	// Remove the evidence...
	$db->query('', '
		DELETE FROM {db_prefix}log_group_requests
		WHERE id_request IN ({array_int:request_list})',
		array(
			'request_list' => $groups,
		)
	);
}

/**
 * This function updates those members who match post-based
 * membergroups in the database (restricted by parameter $members).
 *
 * @param int[]|null $members = null The members to update, null if all
 * @param string[]|null $parameter2 = null
 * @package Membergroups
 */
function updatePostGroupStats($members = null, $parameter2 = null)
{
	$db = database();

	// Parameter two is the updated columns: we should check to see if we base groups off any of these.
	if ($parameter2 !== null && !in_array('posts', $parameter2))
	{
		return;
	}

	$postgroups = Cache::instance()->get('updatePostGroupStats', 360);
	if ($postgroups === null || $members === null)
	{
		// Fetch the postgroups!
		$postgroups = array();
		$db->fetchQuery('
			SELECT 
			 	id_group, min_posts
			FROM {db_prefix}membergroups
			WHERE min_posts != {int:min_posts}',
			array(
				'min_posts' => -1,
			)
		)->fetch_callback(
			function ($row) use (&$postgroups) {
				$postgroups[$row['id_group']] = $row['min_posts'];
			}
		);

		// Sort them this way because if it's done with MySQL it causes a filesort :(.
		arsort($postgroups);

		Cache::instance()->put('updatePostGroupStats', $postgroups, 360);
	}

	// Oh great, they've screwed their post groups.
	if (empty($postgroups))
	{
		return;
	}

	// Set all membergroups from most posts to the least posts.
	$conditions = '';
	$lastMin = 0;
	foreach ($postgroups as $id => $min_posts)
	{
		$conditions .= '
				WHEN posts >= ' . $min_posts . (!empty($lastMin) ? ' AND posts <= ' . $lastMin : '') . ' THEN ' . $id;
		$lastMin = $min_posts;
	}

	// A big fat CASE WHEN... END is faster than a zillion UPDATE's ;).
	$db->query('', '
		UPDATE {db_prefix}members
		SET id_post_group = CASE ' . $conditions . '
				ELSE 0
			END' . ($members !== null ? '
		WHERE id_member IN ({array_int:members})' : ''),
		array(
			'members' => is_array($members) ? $members : array($members),
		)
	);
}

/**
 * Get the ids of the groups that are unassignable
 *
 * @param bool $ignore_protected To ignore protected groups
 * @return int[]
 */
function getUnassignableGroups($ignore_protected)
{
	$db = database();

	return $db->fetchQuery('
		SELECT 
			id_group
		FROM {db_prefix}membergroups
		WHERE min_posts != {int:min_posts}' . ($ignore_protected ? '' : '
			OR group_type = {int:is_protected}'),
		array(
			'min_posts' => -1,
			'is_protected' => 1,
		)
	)->fetch_callback(
		function ($row) {
			return $row['id_group'];
		},
		array(-1, 3)
	);
}

/**
 * Returns a list of groups that a member can be assigned to
 *
 * @return array
 */
function getGroupsList()
{
	global $txt;

	Txt::load('Profile');

	$db = database();
	$member_groups = array(
		0 => array(
			'id' => 0,
			'name' => $txt['no_primary_membergroup'],
			'is_primary' => false,
			'can_be_additional' => false,
			'can_be_primary' => true,
		)
	);

	// Load membergroups, but only those groups the user can assign.
	$db->fetchQuery('
		SELECT 
			group_name, id_group, hidden
		FROM {db_prefix}membergroups
		WHERE id_group != {int:moderator_group}
			AND min_posts = {int:min_posts}' . (allowedTo('admin_forum') ? '' : '
			AND group_type != {int:is_protected}') . '
		ORDER BY min_posts, CASE WHEN id_group < {int:newbie_group} THEN id_group ELSE 4 END, group_name',
		array(
			'moderator_group' => 3,
			'min_posts' => -1,
			'is_protected' => 1,
			'newbie_group' => 4,
		)
	)->fetch_callback(
		function ($row) use (&$member_groups) {
			// We should skip the administrator group if they don't have the admin_forum permission!
			if ($row['id_group'] == 1 && !allowedTo('admin_forum'))
			{
				return;
			}

			$member_groups[$row['id_group']] = array(
				'id' => $row['id_group'],
				'name' => $row['group_name'],
				'hidden' => $row['hidden'],
				'is_primary' => false,
				'can_be_primary' => $row['hidden'] != 2,
			);
		}
	);

	return $member_groups;
}
