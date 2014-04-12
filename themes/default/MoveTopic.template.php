<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.0 Beta 2
 *
 */

/**
 * Have the generic templates available
 */
function template_MoveTopic_init()
{
	loadTemplate('GenericHelpers');
}

/**
 * Show an interface for selecting which board to move a post to.
 */
function template_move_topic()
{
	global $context, $txt, $scripturl;

	echo '
	<div id="move_topic">
		<form action="', $scripturl, '?action=movetopic2;current_board=' . $context['current_board'] . ';topic=', $context['current_topic'], '.0" method="post" accept-charset="UTF-8" onsubmit="submitonce(this);">
			<h2 class="category_header">', $txt['move_topic'], '</h2>
			<div class="windowbg centertext">
				<div class="content">
					<div class="move_topic">
						<dl class="settings">
							<dt>
								<strong>', $txt['move_to'], ':</strong>
							</dt>
							<dd>', template_select_boards('toboard'), '
							</dd>';

	// Disable the reason textarea when the postRedirect checkbox is unchecked...
	echo '
						</dl>
						<label for="reset_subject"><input type="checkbox" name="reset_subject" id="reset_subject" onclick="document.getElementById(\'subjectArea\').style.display = this.checked ? \'block\' : \'none\';" class="input_check" /> ', $txt['moveTopic2'], '.</label><br />
						<fieldset id="subjectArea" style="display: none;">
							<dl class="settings">
								<dt><strong><label for="custom_subject">', $txt['moveTopic3'], '</label>:</strong></dt>
								<dd><input type="text" id="custom_subject" name="custom_subject" size="30" value="', $context['subject'], '" class="input_text" /></dd>
							</dl>
							<label for="enforce_subject"><input type="checkbox" name="enforce_subject" id="enforce_subject" class="input_check" /> ', $txt['moveTopic4'], '.</label>
						</fieldset>
						<label for="postRedirect"><input type="checkbox" name="postRedirect" id="postRedirect" ', $context['is_approved'] ? 'checked="checked"' : '', ' onclick="', $context['is_approved'] ? '' : 'if (this.checked && !confirm(\'' . $txt['move_topic_unapproved_js'] . '\')) return false; ', 'document.getElementById(\'reasonArea\').style.display = this.checked ? \'block\' : \'none\';" class="input_check" /> ', $txt['moveTopic1'], '.</label>
						<fieldset id="reasonArea" style="margin-top: 1ex;', $context['is_approved'] ? '' : 'display: none;', '">
							<dl class="settings">
								<dt>
									<label for="reason">', $txt['moved_why'], '</label>
								</dt>
								<dd>
									<textarea id="reason" name="reason" rows="3" cols="40">', $txt['movetopic_default'], '</textarea>
								</dd>
								<dt>
									<label for="redirect_topic">', $txt['movetopic_redirect'], '</label>
								</dt>
								<dd>
									<input type="checkbox" name="redirect_topic" id="redirect_topic" ', !empty($context['redirect_topic']) ? 'checked="checked"' : '', ' class="input_check" />
								</dd>
								<dt>
									<label for="redirect_expires">', $txt['movetopic_expires'], '</label>
								</dt>
								<dd>
									<select id="redirect_expires" name="redirect_expires">
										<option value="0"', empty($context['redirect_expires']) ? ' selected="selected"' : '', '>', $txt['never'], '</option>
										<option value="1440"', !empty($context['redirect_expires']) && $context['redirect_expires'] == 1440 ? ' selected="selected"' : '', '>', $txt['one_day'], '</option>
										<option value="10080"', !empty($context['redirect_expires']) && $context['redirect_expires'] == 10080 ? ' selected="selected"' : '', '>', $txt['one_week'], '</option>
										<option value="20160"', !empty($context['redirect_expires']) && $context['redirect_expires'] == 20160 ? ' selected="selected"' : '', '>', $txt['two_weeks'], '</option>
										<option value="43200"', !empty($context['redirect_expires']) && $context['redirect_expires'] == 43200 ? ' selected="selected"' : '', '>', $txt['one_month'], '</option>
										<option value="86400"', !empty($context['redirect_expires']) && $context['redirect_expires'] == 86400 ? ' selected="selected"' : '', '>', $txt['two_months'], '</option>
									</select>
								</dd>
							</dl>
						</fieldset>
						<input type="submit" value="', $txt['move_topic'], '" onclick="return submitThisOnce(this);" accesskey="s" class="right_submit" />
					</div>
				</div>
			</div>';

	if ($context['back_to_topic'])
		echo '
			<input type="hidden" name="goback" value="1" />';

	echo '
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
			<input type="hidden" name="seqnum" value="', $context['form_sequence_number'], '" />
		</form>
	</div>';
}