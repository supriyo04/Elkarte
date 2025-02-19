<?php

/**
 * This file is the file which all subscription gateways should call
 * when a payment has been received - it sorts out the user status.
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

use ElkArte\Http\Headers;
use ElkArte\Languages\Txt;
use ElkArte\Util;

// Start things rolling by getting the forum alive...
if (!file_exists(dirname(__FILE__) . '/bootstrap.php'))
{
	die('Unable to initialize');
}

global $ssi_guest_access;

require_once(dirname(__FILE__) . '/bootstrap.php');
$ssi_guest_access = true;
new Bootstrap(true);

global $txt, $modSettings, $context, $scripturl;

// Need lots of help
require_once(SUBSDIR . '/PaidSubscriptions.subs.php');
require_once(SUBSDIR . '/Admin.subs.php');
require_once(SUBSDIR . '/Members.subs.php');

Txt::load('ManagePaid');

// If there's literally nothing coming in, let's take flight!
if (empty($_POST))
{
	Headers::instance()->contentType('text/html', 'UTF-8')->sendHeaders();
	die($txt['paid_no_data']);
}

// I assume we're even active?
if (empty($modSettings['paid_enabled']))
{
	exit;
}

// If we have some custom people who find out about problems load them here.
$notify_users = array();
if (!empty($modSettings['paid_email_to']))
{
	foreach (explode(',', $modSettings['paid_email_to']) as $email)
	{
		$notify_users[] = array(
			'email' => $email,
			'name' => $txt['who_member'],
			'id' => 0,
		);
	}
}

$db = database();

// We need to see whether we can find the correct payment gateway,
// Go through all our gateway scripts and find out if they are happy with what we have.
$txnType = '';
$gatewayHandles = loadPaymentGateways();
foreach ($gatewayHandles as $gateway)
{
	$gatewayClass = new $gateway['payment_class']();
	if ($gatewayClass->isValid())
	{
		$txnType = $gateway['code'];
		break;
	}
}

if (empty($txnType))
{
	generateSubscriptionError($txt['paid_unknown_transaction_type'], $notify_users);
}

// Get the subscription and member ID amongst others...
@list($subscription_id, $member_id) = $gatewayClass->precheck();

// Integer these just in case.
$subscription_id = (int) $subscription_id;
$member_id = (int) $member_id;

// This would be bad...
if (empty($member_id))
{
	generateSubscriptionError($txt['paid_empty_member'], $notify_users);
}

// Verify the member.
$member_info = getBasicMemberData($member_id);

// Didn't find them?
if (empty($member_info))
{
	generateSubscriptionError(sprintf($txt['paid_could_not_find_member'], $member_id), $notify_users);
}

// Get the subscription details.
$request = $db->query('', '
	SELECT 
		cost, length, name
	FROM {db_prefix}subscriptions
	WHERE id_subscribe = {int:current_subscription}',
	array(
		'current_subscription' => $subscription_id,
	)
);

// Didn't find it?
if ($request->num_rows() === 0)
{
	generateSubscriptionError(sprintf($txt['paid_count_not_find_subscription'], $member_id, $subscription_id), $notify_users);
}

$subscription_info = $request->fetch_assoc();
$request->free_result();

// We wish to check the pending payments to make sure we are expecting this.
$request = $db->query('', '
	SELECT 
		id_sublog, id_subscribe, payments_pending, pending_details, end_time
	FROM {db_prefix}log_subscribed
	WHERE id_subscribe = {int:current_subscription}
		AND id_member = {int:current_member}
	LIMIT 1',
	array(
		'current_subscription' => $subscription_id,
		'current_member' => $member_id,
	)
);
if ($request->num_rows() == 0)
{
	generateSubscriptionError(sprintf($txt['paid_count_not_find_subscription_log'], $member_id, $subscription_id), $notify_users);
}
$subscription_info += $request->fetch_assoc();
$request->free_result();

// Is this a refund?
if ($gatewayClass->isRefund())
{
	handleRefund($subscription_info, $member_id, $context['subscriptions'][$subscription_id]['num_length']);

	// Receipt?
	if (!empty($modSettings['paid_email']) && $modSettings['paid_email'] == 2)
	{
		$replacements = array(
			'NAME' => $subscription_info['name'],
			'REFUNDNAME' => $member_info['member_name'],
			'REFUNDUSER' => $member_info['real_name'],
			'PROFILELINK' => getUrl('profile', ['action' => 'profile', 'u' => $member_id, 'name' => $member_info['real_name']]),
			'DATE' => standardTime(time(), false),
		);

		emailAdmins('paid_subscription_refund', $replacements, $notify_users);
	}
}
// Otherwise is it what we want, a purchase?
elseif ($gatewayClass->isPayment() || $gatewayClass->isSubscription())
{
	$cost = Util::unserialize($subscription_info['cost']);
	$total_cost = $gatewayClass->getCost();
	$notify = false;

	// For one off's we want to only capture them once!
	if (!$gatewayClass->isSubscription())
	{
		$real_details = Util::unserialize($subscription_info['pending_details']);
		if (empty($real_details))
		{
			generateSubscriptionError(sprintf($txt['paid_count_not_find_outstanding_payment'], $member_id, $subscription_id), $notify_users);
		}

		// Now we just try to find anything pending.
		// We don't really care which it is as security happens later.
		foreach ($real_details as $id => $detail)
		{
			unset($real_details[$id]);
			if ($detail[3] == 'payback' && $subscription_info['payments_pending'])
			{
				$subscription_info['payments_pending']--;
			}
			break;
		}

		$subscription_info['pending_details'] = empty($real_details) ? '' : serialize($real_details);

		updateNonrecurrent($subscription_info);
	}

	// Is this flexible?
	if ($subscription_info['length'] == 'F')
	{
		$found_duration = 0;

		// This is a little harder, can we find the right duration?
		foreach ($cost as $duration => $value)
		{
			if ($duration == 'fixed')
			{
				continue;
			}
			elseif ((float) $value == (float) $total_cost)
			{
				$found_duration = strtoupper(substr($duration, 0, 1));
			}
		}

		// If we have the duration then we're done.
		if ($found_duration !== 0)
		{
			$notify = true;
			addSubscription($subscription_id, $member_id, $found_duration);
		}
	}
	else
	{
		$actual_cost = $cost['fixed'];

		// It must be at least the right amount.
		if ($total_cost != 0 && $total_cost >= $actual_cost)
		{
			// Add the subscription.
			$notify = true;
			addSubscription($subscription_id, $member_id);
		}
	}

	// Send a receipt?
	if (!empty($modSettings['paid_email']) && $modSettings['paid_email'] == 2 && $notify)
	{
		$replacements = array(
			'NAME' => $subscription_info['name'],
			'SUBNAME' => $member_info['member_name'],
			'SUBUSER' => $member_info['real_name'],
			'SUBEMAIL' => $member_info['email_address'],
			'PRICE' => sprintf($modSettings['paid_currency_symbol'], $total_cost),
			'PROFILELINK' => getUrl('profile', ['action' => 'profile', 'u' => $member_id, 'name' => $member_info['real_name']]),
			'DATE' => standardTime(time(), false),
		);

		emailAdmins('paid_subscription_new', $replacements, $notify_users);
	}
}
// Maybe they're cancelling. This allows payment gateways to perform processing if needed
elseif ($gatewayClass->isCancellation())
{
	if (method_exists($gatewayClass, 'processCancelation'))
	{
		$gatewayClass->processCancelation($subscription_id, $member_id, $subscription_info);
	}
}
else
{
	// Some other "valid" transaction such as:
	//
	// subscr_signup: This IPN response (txn_type) is sent only the first time the user signs up for a subscription.
	// It then does not fire in any event later. This response is received somewhere before or after the first payment of
	// subscription is received (txn_type=subscr_payment) which is what we do process
	//
	// Should we log any of these ...
}

// In case we have anything specific to do.
$gatewayClass->close($subscription_id);

/**
 * Log an error then exit
 *
 * @param string $text
 * @param mixed[] $notify_users
 * @throws \ElkArte\Exceptions\Exception
 */
function generateSubscriptionError($text, $notify_users = [])
{
	global $modSettings;

	// Send an email?
	if (!empty($modSettings['paid_email']))
	{
		$replacements = array(
			'ERROR' => $text,
		);

		emailAdmins('paid_subscription_error', $replacements, $notify_users);
	}

	// Maybe we can try to give them the post data?
	if (!empty($_POST))
	{
		foreach ($_POST as $key => $val)
		{
			$text .= '<br />' . Util::htmlspecialchars($key) . ': ' . Util::htmlspecialchars($val);
		}
	}

	// Then just log and die.
	\ElkArte\Errors\Errors::instance()->log_error($text);

	exit;
}
