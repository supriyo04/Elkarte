<?php

/**
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

require('installcore.php');

// General options for the script.
$timeLimitThreshold = 3;
$upgrade_path = realpath(__DIR__ . '/..');
$upgradeurl = $_SERVER['PHP_SELF'];

// Disable the need for admins to login?
$disable_security = false;

// How long, in seconds, must admin be inactive to allow someone else to run?
$upcontext['inactive_timeout'] = 30;

// This bunch of indexes necessary in the template and are set a bit too late
$upcontext['current_item_num'] = 0;
$upcontext['current_item_name'] = '';
$upcontext['current_debug_item_num'] = 0;
$upcontext['current_debug_item_name'] = '';

// All the steps in detail.
// Number,Name,Function,Progress Weight.
$upcontext['steps'] = array(
	0 => array(1, 'Login', 'action_welcomeLogin', 0),
	1 => array(2, 'Upgrade Options', 'action_upgradeOptions', 5),
	2 => array(3, 'Backup', 'action_backupDatabase', 15),
	3 => array(4, 'Database Changes', 'action_databaseChanges', 65),
	4 => array(5, 'Database Changes', 'action_deleteOldFiles', 15),
	5 => array(6, 'Delete Upgrade', 'action_deleteUpgrade', 0),
);

// Just to remember which one has files in it.
$upcontext['database_step'] = 3;

@set_time_limit(600);
@ini_set('mysql.connect_timeout', -1);
@ini_set('default_socket_timeout', 900);
@ini_set('memory_limit', '256M');

// Clean the upgrade path if this is from the client.
if (!empty($_SERVER['argv']) && php_sapi_name() === 'cli' && empty($_SERVER['REMOTE_ADDR']))
{
	for ($i = 1; $i < $_SERVER['argc']; $i++)
	{
		if (preg_match('~^--path=(.+)$~', $_SERVER['argv'][$i], $match) != 0)
		{
			$upgrade_path = substr($match[1], -1) === '/' ? substr($match[1], 0, -1) : $match[1];
		}
	}
}

define('TMP_BOARDDIR', $upgrade_path);

// Call in our support staff
require_once(__DIR__ . '/CommonCode.php');
require_once(__DIR__ . '/LegacyCode.php');
require_once(__DIR__ . '/ToRefactorCode.php');
require_once(__DIR__ . '/TemplateUpgrade.php');

// Are we from the client?
$command_line = false;
if (php_sapi_name() === 'cli' && empty($_SERVER['REMOTE_ADDR']))
{
	$command_line = true;
	$disable_security = 1;
}

// Load Settings now just because we can, globals should indicate what we want.
global $db_type, $sourcedir, $boarddir, $boardurl, $cachedir, $language, $maintenance;
require_once(TMP_BOARDDIR . '/Settings.php');
$db_type = $db_type === 'mysql' ? 'mysqli' : $db_type;

// Fix for using the current directory as a path.
if (substr($sourcedir, 0, 1) === '.' && substr($sourcedir, 1, 1) !== '.')
{
	$sourcedir = TMP_BOARDDIR . substr($sourcedir, 1);
}

// Make sure the paths are correct... at least try to fix them.
if (!file_exists($boarddir) && file_exists(TMP_BOARDDIR . '/bootstrap.php'))
{
	$boarddir = TMP_BOARDDIR;
}

if (!file_exists($sourcedir) && file_exists($boarddir . '/sources'))
{
	$sourcedir = $boarddir . '/sources';
}

// This may be an SMF install we are upgrading
if (!file_exists($sourcedir . '/controllers'))
{
	$sourcedir = str_replace('/Sources', '/sources', $sourcedir);
	if (!file_exists($sourcedir . '/controllers') && file_exists($boarddir . '/sources'))
	{
		$sourcedir = $boarddir . '/sources';
	}
}

// Check that directories which didn't exist in past releases are initialized.
if ((empty($cachedir) || !file_exists($cachedir)) && file_exists($boarddir . '/cache'))
{
	$cachedir = $boarddir . '/cache';
}

if ((empty($extdir) || !file_exists($extdir)) && file_exists($sourcedir . '/ext'))
{
	$extdir = $sourcedir . '/ext';
}

if ((empty($languagedir) || !file_exists($languagedir)) && file_exists($sourcedir . '/Languages'))
{
	$languagedir = $sourcedir . '/ElkArte/Languages';
}

// Time to forget about variables and go with constants!
DEFINE('BOARDDIR', $boarddir);
DEFINE('CACHEDIR', $cachedir);
DEFINE('EXTDIR', $extdir);
DEFINE('LANGUAGEDIR', $languagedir);
DEFINE('SOURCEDIR', $sourcedir);
DEFINE('ADMINDIR', $sourcedir . '/admin');
DEFINE('CONTROLLERDIR', $sourcedir . '/controllers');
DEFINE('SUBSDIR', $sourcedir . '/subs');

// Are we logged in?
if (isset($upgradeData))
{
	$upcontext['user'] = json_decode(base64_decode($upgradeData), true);

	// Check for sensible values.
	if (empty($upcontext['user']['started']) || $upcontext['user']['started'] < time() - 86400)
	{
		$upcontext['user']['started'] = time();
	}

	if (empty($upcontext['user']['updated']) || $upcontext['user']['updated'] < time() - 86400)
	{
		$upcontext['user']['updated'] = 0;
	}

	$upcontext['started'] = $upcontext['user']['started'];
	$upcontext['updated'] = $upcontext['user']['updated'];
}

// Nothing sensible?
if (empty($upcontext['updated']))
{
	$upcontext['started'] = time();
	$upcontext['updated'] = 0;
	$upcontext['user'] = array(
		'id' => 0,
		'name' => 'Guest',
		'pass' => 0,
		'started' => $upcontext['started'],
		'updated' => $upcontext['updated'],
	);
}

// Load up essential data such as modSettings, Classloader, db
loadEssentialData();

// Are we going to be mimicking SSI at this point?
if (isset($_GET['ssi']))
{
	loadUserSettings();
	loadPermissions();
}

// We may need this later
require_once(SUBSDIR . '/Package.subs.php');

// All the non-SSI stuff.
loadEssentialFunctions();

// We should have the database easily at this point
$db = load_database();

// Does this exist?
if (isset($modSettings['elkVersion']))
{
	$db->skip_next_error();
	$db->fetchQuery('
		SELECT 
			variable, value
		FROM {db_prefix}themes
		WHERE id_theme = {int:id_theme}
			AND variable IN ({string:theme_url}, {string:theme_dir}, {string:images_url})',
		array(
			'id_theme' => 1,
			'theme_url' => 'theme_url',
			'theme_dir' => 'theme_dir',
			'images_url' => 'images_url',
		)
	)->fetch_callback(
		function ($row) use (&$modSettings) {
			$modSettings[$row['variable']] = $row['value'];
		}
	);
}

// Make sure we have the theme information setup
if (!isset($modSettings['theme_dir']) || !file_exists($modSettings['theme_dir']))
{
	$modSettings['theme_dir'] = BOARDDIR . '/themes/default';
	$modSettings['theme_url'] = $boardurl . '/themes/default';
	$modSettings['images_url'] = $boardurl . '/themes/default/images';
}

if (!isset($settings['default_theme_url']))
{
	$settings['default_theme_url'] = $modSettings['theme_url'];
}

if (!isset($settings['default_theme_dir']))
{
	$settings['default_theme_dir'] = $modSettings['theme_dir'];
}

$upcontext['is_large_forum'] = (empty($modSettings['elkVersion']) || $modSettings['elkVersion'] <= '1.0') && !empty($modSettings['totalMessages']) && $modSettings['totalMessages'] > 75000;
$upcontext['page_title'] = 'Upgrading Your ElkArte Install!';
$upcontext['right_to_left'] = $txt['lang_rtl'] ?? false;

// Have we got log data - if so use it (It will be clean!)
if (isset($_GET['data']))
{
	$upcontext['upgrade_status'] = unserialize(base64_decode($_GET['data']));
	$upcontext['current_step'] = $upcontext['upgrade_status']['curstep'];
	$upcontext['language'] = ucfirst($upcontext['upgrade_status']['lang']);
	$upcontext['rid'] = $upcontext['upgrade_status']['rid'];
	$is_debug = $upcontext['upgrade_status']['debug'];
	$support_js = $upcontext['upgrade_status']['js'];

	// Load the language.
	global $txt;
	$lang = new \ElkArte\Languages\Loader($upcontext['language'], $txt, database());
	$lang->load('Install');
}
// Set the defaults.
else
{
	$upcontext['current_step'] = 0;
	$upcontext['rid'] = mt_rand(0, 5000);
	$upcontext['upgrade_status'] = array(
		'curstep' => 0,
		// memo: .lng files were used by YaBB SE, $language is defined in Settings.php
		'lang' => $_GET['lang'] ?? ucfirst(basename($language, '.lng')),
		'rid' => $upcontext['rid'],
		'pass' => 0,
		'debug' => 0,
		'js' => 0,
	);
	$upcontext['language'] = ucfirst($upcontext['upgrade_status']['lang']);
}

// If this isn't the first stage see whether they are logging in and resuming.
if ($upcontext['current_step'] != 0 || !empty($upcontext['user']['step']))
{
	checkLogin();
}

if ($command_line)
{
	cmdStep0();
}

// Don't error if we're using xml.
if (isset($_GET['xml']))
{
	$upcontext['return_error'] = true;
}

// Loop through all the steps doing each one as required.
$upcontext['overall_percent'] = 0;
foreach ($upcontext['steps'] as $num => $step)
{
	if ($num >= $upcontext['current_step'])
	{
		// The current weight of this step in terms of overall progress.
		$upcontext['step_weight'] = $step[3];

		// Make sure we reset the skip button.
		$upcontext['skip'] = false;

		// We cannot proceed if we're not logged in.
		if ($num != 0 && !$disable_security && $upcontext['user']['pass'] != $upcontext['upgrade_status']['pass'])
		{
			$upcontext['steps'][0][2]();
			break;
		}

		// Call the step and if it returns false that means pause!
		if (function_exists($step[2]) && $step[2]() === false)
		{
			break;
		}
		elseif (function_exists($step[2]))
		{
			$upcontext['current_step']++;
		}
	}

	$upcontext['overall_percent'] += $step[3];
}

upgradeExit();

/**
 * Exit the upgrade script.
 *
 * @param boolean $fallThrough
 */
function upgradeExit($fallThrough = false)
{
	global $upcontext, $upgradeurl, $command_line;

	// Save where we are...
	if (!empty($upcontext['current_step']) && !empty($upcontext['user']['id']))
	{
		$upcontext['user']['step'] = $upcontext['current_step'];
		$upcontext['user']['substep'] = $_GET['substep'];
		$upcontext['user']['updated'] = time();

		// Make a copy, then save the data in Settings.php
		$upgradeData = base64_encode(json_encode($upcontext['user']));
		copy(BOARDDIR . '/Settings.php', BOARDDIR . '/Settings_bak.php');
		changeSettings(array('upgradeData' => '\'' . $upgradeData . '\''));
		updateLastError();
	}

	// Handle the progress of the step, if any.
	if (!empty($upcontext['step_progress']) && isset($upcontext['steps'][$upcontext['current_step']]))
	{
		$upcontext['step_progress'] = round($upcontext['step_progress'], 1);
		$upcontext['overall_percent'] += $upcontext['step_progress'] * ($upcontext['steps'][$upcontext['current_step']][3] / 100);
	}

	$upcontext['overall_percent'] = (int) $upcontext['overall_percent'];

	// We usually dump our templates out.
	if (!$fallThrough)
	{
		// This should not happen my dear... HELP ME DEVELOPERS!!
		if (!empty($command_line))
		{
			if (function_exists('debug_print_backtrace'))
			{
				debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			}

			echo "\n" . 'Error: Unexpected call to use the ' . ($upcontext['sub_template'] ?? '') . ' template. Please copy and paste all the text above and visit the ElkArte Community to tell the Developers that they\'ve made a doh!; they\'ll get you up and running again.';
			flush();
			die();
		}

		if (!isset($_GET['xml']))
		{
			template_upgrade_above();
		}
		else
		{
			header('Content-Type: text/xml; charset=UTF-8');

			// Sadly we need to retain the $_GET data thanks to the old upgrade scripts.
			$upcontext['get_data'] = array();
			foreach ($_GET as $k => $v)
			{
				if (substr($k, 0, 3) !== 'amp' && !in_array($k, array('xml', 'substep', 'lang', 'data', 'step', 'filecount')))
				{
					$upcontext['get_data'][$k] = $v;
				}
			}

			template_xml_above();
		}

		// Call the template.
		if (isset($upcontext['sub_template']))
		{
			$upcontext['upgrade_status']['curstep'] = $upcontext['current_step'];
			$upcontext['form_url'] = $upgradeurl . '?step=' . $upcontext['current_step'] . '&amp;substep=' . $_GET['substep'] . '&amp;data=' . base64_encode(serialize($upcontext['upgrade_status']));

			// Custom stuff to pass back?
			if (!empty($upcontext['query_string']))
			{
				$upcontext['form_url'] .= $upcontext['query_string'];
			}

			call_user_func('template_' . $upcontext['sub_template']);
		}

		// Was there an error?
		if (!empty($upcontext['forced_error_message']))
		{
			echo $upcontext['forced_error_message'];
		}

		// Show the footer.
		if (!isset($_GET['xml']))
		{
			template_upgrade_below();
		}
		else
		{
			template_xml_below();
		}
	}

	// Bang - gone!
	die();
}

/**
 * Used to direct the user to another location.
 *
 * @param string $location
 * @param boolean $addForm
 */
function redirectLocation($location, $addForm = true)
{
	global $upgradeurl, $upcontext, $command_line;

	// Command line users can't be redirected.
	if ($command_line)
	{
		upgradeExit(true);
	}

	// Are we providing the core info?
	if ($addForm)
	{
		$upcontext['upgrade_status']['curstep'] = $upcontext['current_step'];
		$location = $upgradeurl . '?step=' . $upcontext['current_step'] . '&substep=' . $_GET['substep'] . '&data=' . base64_encode(serialize($upcontext['upgrade_status'])) . $location;
	}

	while (@ob_end_clean())
	{
		header('Location: ' . strtr($location, array('&amp;' => '&')));
	}

	// Exit - saving status as we go.
	upgradeExit(true);
}

/**
 * Load all essential data and connect to the DB as this is pre SSI.php
 */
function loadEssentialData()
{
	global $db_character_set, $db_type, $modSettings;

	// Report all errors except for depreciation notices so users don't complain.
	error_reporting(E_ALL & ~E_DEPRECATED);

	if (!defined('ELK'))
	{
		define('ELK', 1);
	}

	header('X-Frame-Options: SAMEORIGIN');
	header('X-XSS-Protection: 1');
	header('X-Content-Type-Options: nosniff');

	// Start the session.
	if (ini_get('session.save_handler') === 'user')
	{
		@ini_set('session.save_handler', 'files');
	}
	@session_start();

	// Initialize everything...
	definePaths();
	initialize_inputs();

	if (file_exists(SOURCEDIR . '/database/Database.subs.php'))
	{
		require_once(SOURCEDIR . '/Subs.php');
		require_once(SOURCEDIR . '/Logging.php');
		require_once(SOURCEDIR . '/Load.php');
		require_once(SUBSDIR . '/Cache.subs.php');
		require_once(SOURCEDIR . '/Security.php');
		require_once(EXTDIR . '/ClassLoader.php');

		$loader = new \ElkArte\ext\Composer\Autoload\ClassLoader();
		$loader->setPsr4('ElkArte\\', SOURCEDIR . '/ElkArte');
		$loader->setPsr4('BBC\\', SOURCEDIR . '/ElkArte/BBC');
		$loader->register();
		require_once('./DatabaseCode.php');

		$db = load_database();

		// Load the modSettings data...
		$modSettings = array();
		$db->skip_next_error();
		$db->fetchQuery('
			SELECT 
				variable, value
			FROM {db_prefix}settings',
			array()
		)->fetch_callback(
			function ($row) use (&$modSettings) {
				$modSettings[$row['variable']] = $row['value'];
			}
		);
	}
	else
	{
		return throw_error('Cannot find ' . SOURCEDIR . '/database/Database.subs.php. Please check you have uploaded all source files and have the correct paths set.');
	}

	// If they don't have the file, they're going to get a warning anyway,
	// so we won't need to clean request vars.
	if (file_exists(SOURCEDIR . '/QueryString.php'))
	{
		require_once(SOURCEDIR . '/QueryString.php');

		cleanRequest();
	}

	// Set a session life limit for the admin
	if (isset($modSettings['admin_session_lifetime']))
	{
		$modSettings['admin_session_lifetime'] = 5;
	}

	$_GET['substep'] = $_GET['substep'] ?? 0;
}

/**
 * Prepare for the installation, set up which set we are on, etc
 */
function initialize_inputs()
{
	global $start_time;

	$start_time = time();

	umask(0);
	ob_start();

	// Better to upgrade cleanly and fall apart than to screw everything up if things take too long.
	ignore_user_abort(true);

	// This is really quite simple; if ?delete is on the URL, delete the upgrader...
	if (isset($_GET['delete']))
	{
		action_deleteInstaller();
	}

	// Something is causing this to happen, and it's annoying.  Stop it.
	$temp = 'upgrade_php?step';
	while (strlen($temp) > 4)
	{
		if (isset($_GET[$temp]))
		{
			unset($_GET[$temp]);
		}

		$temp = substr($temp, 1);
	}

	// Force a step, defaulting to 0.
	$_GET['step'] = $_GET['step'] ?? 0;
	$_GET['substep'] =$_GET['substep'] ?? 0;
}

/**
 * Step 0
 * Let's welcome them in and ask them to login!
 * Preforms several checks to make sure the appropriate files are available to do the updates
 * Validates php and db versions meet the minimum requirements
 * Validates the credentials supplied have db alter privileges
 * Checks that needed files/directories are writable
 */
function action_welcomeLogin()
{
	global $modSettings, $upgradeurl, $upcontext, $db_type, $db_character_set, $txt, $boardurl;

	$db = load_database();
	load_possible_databases();
	$fileFunc = \ElkArte\FileFunctions::instance();

	$upcontext['sub_template'] = 'welcome_message';
	$upcontext['language'] = !empty($upcontext['language']) ? ucfirst($upcontext['language']): 'English';

	// Check for some key files - one template, one language, and a new and an old source file.
	$check = $fileFunc->fileExists($modSettings['theme_dir'] . '/index.template.php')
		&& $fileFunc->fileExists(SOURCEDIR . '/QueryString.php')
		&& $fileFunc->fileExists(SOURCEDIR . '/ElkArte/Database/' . ucfirst($db_type) . '/Query.php')
		&& $fileFunc->fileExists(__DIR__ . '/upgrade_' . DB_SCRIPT_VERSION . '.php');

	// This needs to exist!
	if (!$fileFunc->fileExists(SOURCEDIR . '/ElkArte/Languages/Install/' . $upcontext['language'] . '.php'))
	{
		return throw_error('The upgrade script could not find the &quot;Install&quot; language file for the forum default language, ' . $upcontext['language'] . '.<br /><br />Please make certain you uploaded all the files included in the package, even the theme and language files for the default theme.<br />&nbsp;&nbsp;&nbsp;[<a href="' . $upgradeurl . '?lang=english">Try English</a>]');
	}
	require_once(SOURCEDIR . '/ElkArte/Languages/Install/' . $upcontext['language'] . '.php');

	// Do not try to upgrade to the same version you are already running
	if (isset($_GET['v']) && defined('CURRENT_VERSION') && !empty($_SESSION['installing']))
	{
		if ($_GET['v'] === CURRENT_VERSION)
		{
			$upcontext['current_step'] = 4;
			$upcontext['overall_percent'] = 100;
			unset($_GET['v']);

			return throw_error(sprintf($txt['upgrade_warning_already_done'], CURRENT_VERSION, $boardurl), true);
		}
	}

	// If the db is not UTF
	if (!isset($modSettings['elkVersion']) && ($db_type === 'mysql' || $db_type === 'mysqli') && (!isset($db_character_set) || $db_character_set !== 'utf8' || empty($modSettings['global_character_set']) || $modSettings['global_character_set'] !== 'UTF-8'))
	{
		return throw_error('The upgrader detected your database is not UTF-8. In order to be able to upgrade, please first convert your database to the UTF-8 charset.');
	}

	// Don't tell them what files exactly because it's a spot check -
	// just like teachers don't tell which problems they are spot checking, that's dumb.
	if (!$check)
	{
		return throw_error('The upgrader was unable to find some crucial files.<br /><br />Please make sure you uploaded all of the files included in the package, including the themes, sources, and other directories.');
	}

	// Do they meet the installation requirements?
	if (version_compare(REQUIRED_PHP_VERSION, PHP_VERSION, '>'))
	{
		return throw_error('Warning!  You do not appear to have a version of PHP installed on your webserver that meets ElkArte\'s minimum installations requirements.<br /><br />Please ask your host to upgrade.');
	}

	if (!db_version_check())
	{
		return throw_error('Your ' . $GLOBALS['databases'][$db_type]['name'] . ' version does not meet the minimum requirements of ElkArte.<br /><br />Please ask your host to upgrade.');
	}

	// Do they have ALTER privileges?
	$db->skip_next_error();
	if (!empty($GLOBALS['databases'][$db_type]['alter_support'])
		&& $db->query('', '	ALTER TABLE {db_prefix}log_digest ORDER BY id_topic', array()) === false)
	{
		return throw_error('The ' . $GLOBALS['databases'][$db_type]['name'] . ' user you have set in Settings.php does not have proper privileges.<br /><br />Please ask your host to give this user the ALTER, CREATE, and DROP privileges.');
	}

	// Do a quick version spot check.
	$temp = substr(@implode('', @file(BOARDDIR . '/bootstrap.php')), 0, 4096);
	preg_match('~\*\s@version\s+(.+)~i', $temp, $match);
	if (empty($match[1]) || compareVersions(trim($match[1]), CURRENT_VERSION) != 0)
	{
		return throw_error('The upgrader found some old or outdated files.<br /><br />Please make certain you uploaded the new versions of all the files included in the package.');
	}

	// What absolutely needs to be writable?
	$writable_files = array(
		BOARDDIR . '/Settings.php',
		BOARDDIR . '/Settings_bak.php',
	);

	// Check the cache directory.
	$CACHEDIR_temp = !defined('CACHEDIR') ? BOARDDIR . '/cache' : CACHEDIR;
	if (!$fileFunc->createDirectory($CACHEDIR_temp))
	{
		return throw_error('The cache directory could not be found.<br /><br />Please make sure you have a directory called &quot;cache&quot; in your forum directory before continuing.');
	}

	// Make sure the custom avatar directory exists and is writable!
	$custom_avatar_dir = !empty($modSettings['custom_avatar_dir']) ? $modSettings['custom_avatar_dir'] : BOARDDIR . '/avatars_user';
	if (!$fileFunc->createDirectory($custom_avatar_dir))
	{
		print_error('Error: Unable to obtain write access to "' . $custom_avatar_dir . '"', true);
	}

	// Do we have a language file to use
	if (!$fileFunc->fileExists(SOURCEDIR . '/ElkArte/Languages/Index/' . $upcontext['language'] . '.php') && !isset($modSettings['elkVersion'], $_GET['lang']))
	{
		return throw_error('The upgrader was unable to find language files for the language specified in Settings.php.<br />ElkArte will not work without the primary language files installed.<br /><br />Please either install them, or <a href="' . $upgradeurl . '?step=0;lang=english">use english instead</a>.');
	}

	// Is it for this version of ElkArte?
	if (!isset($_GET['skiplang']))
	{
		$temp = substr(@implode('', @file(SOURCEDIR . '/ElkArte/Languages/Index/' . $upcontext['language'] . '.php')), 0, 4096);
		preg_match('~(?://|/\*)\s*Version:\s+(.+?);\s*index(?:[\s]{2}|\*/)~i', $temp, $match);

		if (empty($match[1]) || $match[1] != CURRENT_LANG_VERSION)
		{
			return throw_error('The upgrader found some old or outdated language files, for the forum default language, ' . $upcontext['language'] . '.<br /><br />Please make certain you uploaded the new versions of all the files included in the package, even the theme and language files for the default theme.<br />&nbsp;&nbsp;&nbsp;[<a href="' . $upgradeurl . '?skiplang">SKIP</a>] [<a href="' . $upgradeurl . '?lang=english">Try English</a>]');
		}
	}

	if (!makeFilesWritable($writable_files))
	{
		return false;
	}

	// We're going to check that their board dir setting is right in case they've been moving stuff around.
	if (strtr(BOARDDIR, array('/' => '', '\\' => '')) != strtr(TMP_BOARDDIR, array('/' => '', '\\' => '')))
	{
		$upcontext['warning'] = '
			It looks as if your board directory settings <em>might</em> be incorrect. Your board directory is currently set to &quot;' . BOARDDIR . '&quot; but should probably be &quot;' . TMP_BOARDDIR . '&quot;. Settings.php currently lists your paths as:<br />
			<ul class="bbc_list">
				<li>Board Directory: ' . BOARDDIR . '</li>
				<li>Source Directory: ' . BOARDDIR . '</li>
				<li>Cache Directory: ' . $CACHEDIR_temp . '</li>
			</ul>
			If these seem incorrect please open Settings.php in a text editor before proceeding with this upgrade. If they are incorrect due to you moving your forum to a new location please download and execute the <a href="https://github.com/emanuele45/tools/downloads">Repair Settings</a> tool from the ElkArte website before continuing.';
	}

	// Either we're logged in or we're going to present the login.
	if (checkLogin())
	{
		return true;
	}

	require_once(SOURCEDIR . '/Security.php');
	$upcontext += createToken('login');

	return false;
}

/**
 * Step 0.5: Does the login work?
 */
function checkLogin()
{
	global $modSettings, $upcontext, $disable_security, $db_type, $support_js;

	// Login checks require hard database work :P
	$db = load_database();

	// Are we trying to login?
	if (isset($_POST['contbutt']) && (!empty($_POST['user']) || $disable_security))
	{
		// If we've disabled security pick a suitable name!
		if (empty($_POST['user']))
		{
			$_POST['user'] = 'Administrator';
		}

		// Get what we believe to be their details.
		if (!$disable_security)
		{
			$db->skip_next_error();
			$request = $db->query('', '
				SELECT 
					id_member, member_name, passwd, id_group, additional_groups, lngfile
				FROM {db_prefix}members
				WHERE member_name = {string:member_name}',
				array(
					'member_name' => $_POST['user'],
				)
			);

			if ($request->num_rows() != 0)
			{
				list ($id_member, $name, $password, $id_group, $addGroups, $user_language) = $request->fetch_row();
				$user_language = ucfirst($user_language);

				// These will come in handy, if you want to login
				require_once(SOURCEDIR . '/Security.php');
				require_once(SUBSDIR . '/Auth.subs.php');

				$groups = explode(',', $addGroups);
				$groups[] = $id_group;
				foreach ($groups as $k => $v)
				{
					$groups[$k] = (int) $v;
				}

				// Figure out if the password is using our encryption - if what they typed is right.
				if (isset($_REQUEST['hash_passwrd']) && strlen($_REQUEST['hash_passwrd']) === 64)
				{
					validateToken('login');

					$valid_password = validateLoginPassword($_REQUEST['hash_passwrd'], $password);

					// Challenge passed.
					if ($valid_password)
					{
						$sha_passwd = $_REQUEST['hash_passwrd'];
						$valid_password = true;
					}
					// Needs upgrading if the db string is an actual 40 hexchar SHA-1
					elseif (preg_match('/^[0-9a-f]{40}$/i', $password))
					{
						// Might Need to update so we will need to ask for the password again.
						$upcontext['disable_login_hashing'] = true;
						$upcontext['login_hash_error'] = true;
					}
				}
				// Maybe a plain text password was used this time
				else
				{
					// validateLoginPassword will convert this to a SHA-256 pw and check it
					$sha_passwd = $_POST['passwrd'];
					$valid_password = validateLoginPassword($sha_passwd, $password, $_POST['user']);
				}

				// Password still not working?
				if ($valid_password === false && !empty($_POST['passwrd']))
				{
					// SHA-1 from SMF?
					$sha_passwd = sha1(\ElkArte\Util::strtolower($_POST['user']) . $_POST['passwrd']);
					$valid_password = $sha_passwd === $password;

					// Lets upgrade this to our new password
					if ($valid_password)
					{
						$password = validateLoginPassword($_POST['passwrd'], '', $_POST['user'], true);
						$password_salt = substr(base64_encode(sha1(mt_rand() . microtime(), true)), 0, 16);

						// Update the password hash and set up the salt.
						require_once(SUBSDIR . '/Members.subs.php');
						updateMemberData($id_member, array('passwd' => $password, 'password_salt' => $password_salt, 'passwd_flood' => ''));
					}
				}
			}
			// Can't find this user in the database
			else
			{
				$upcontext['username_incorrect'] = true;
			}

			$request->free_result();
		}

		$upcontext['username'] = $_POST['user'];

		// Track whether javascript works!
		if (!empty($_POST['js_works']))
		{
			$upcontext['upgrade_status']['js'] = 1;
			$support_js = 1;
		}
		else
		{
			$support_js = 0;
		}

		// Note down the version we are coming from.
		if (!empty($modSettings['elkVersion']) && empty($upcontext['user']['version']))
		{
			$upcontext['user']['version'] = $modSettings['elkVersion'];
		}

		// Didn't get anywhere?
		if (empty($valid_password) && empty($upcontext['username_incorrect']) && !$disable_security)
		{
			// MD5?
			$md5pass = md5_hmac($_REQUEST['passwrd'], strtolower($_POST['user']));
			if ($md5pass != $password)
			{
				$upcontext['password_failed'] = true;

				// Disable the hashing this time.
				$upcontext['disable_login_hashing'] = true;
			}
		}

		if ((empty($upcontext['password_failed']) && !empty($name)) || $disable_security)
		{
			// Set the password.
			if (!$disable_security)
			{
				// Do we actually have permission?
				if (!in_array(1, $groups))
				{
					$db->skip_next_error();
					$request = $db->query('', '
						SELECT 
							permission
						FROM {db_prefix}permissions
						WHERE id_group IN ({array_int:groups})
							AND permission = {string:admin_forum}',
						array(
							'groups' => $groups,
							'admin_forum' => 'admin_forum',
						)
					);
					if ($request->num_rows() == 0)
					{
						return throw_error('You need to be an admin to perform an upgrade!');
					}
					$request->free_result();
				}

				$upcontext['user']['id'] = $id_member;
				$upcontext['user']['name'] = $name;
			}
			else
			{
				$upcontext['user']['id'] = 1;
				$upcontext['user']['name'] = 'Administrator';
			}

			$upcontext['user']['pass'] = mt_rand(0, 60000);
			$user_language = ucfirst($user_language) ?? null;
			$upcontext['language'] = ucfirst($upcontext['language']);

			// This basically is used to match the GET variables to Settings.php.
			$upcontext['upgrade_status']['pass'] = $upcontext['user']['pass'];

			// Set the language to that of the user?
			if (isset($user_language) && $user_language !== $upcontext['language'] && file_exists(SOURCEDIR . '/ElkArte/Languages/Index/' . basename($user_language, '.lng') . '.php'))
			{
				$user_language = basename($user_language, '.lng');
				$temp = substr(@implode('', @file(SOURCEDIR . '/ElkArte/Languages/Index/' . $user_language . '.php')), 0, 4096);
				preg_match('~(?://|/\*)\s*Version:\s+(.+?);\s*index(?:[\s]{2}|\*/)~i', $temp, $match);

				if (empty($match[1]) || $match[1] != CURRENT_LANG_VERSION)
				{
					$upcontext['upgrade_options_warning'] = 'The language files for your selected language, ' . $user_language . ', have not been updated to the latest version. Upgrade will continue with the forum default, ' . $upcontext['language'] . '.';
				}
				elseif (!file_exists(SOURCEDIR . '/ElkArte/Languages/Install/' . $user_language . '.php'))
				{
					$upcontext['upgrade_options_warning'] = 'The language files for your selected language, ' . $user_language . ', have not been uploaded/updated as the &quot;Install&quot; language file is missing. Upgrade will continue with the forum default, ' . $upcontext['language'] . '.';
				}
				else
				{
					// Set this as the new language.
					$upcontext['language'] = $user_language;
					$upcontext['upgrade_status']['lang'] = $upcontext['language'];

					// Include the file.
					require_once(SOURCEDIR . '/ElkArte/Languages/Install/' . $user_language . '.php');
				}
			}

			// If we're resuming set the step and substep to be correct.
			if (isset($_POST['cont']))
			{
				$upcontext['current_step'] = $upcontext['user']['step'];
				$_GET['substep'] = $upcontext['user']['substep'];
			}

			return true;
		}
	}

	return false;
}

/**
 * Step 1: Do the maintenance and backup.
 */
function action_upgradeOptions()
{
	global $command_line, $is_debug, $maintenance, $upcontext, $db_type;

	$upcontext['sub_template'] = 'upgrade_options';
	$upcontext['page_title'] = 'Upgrade Options';

	// If we've not submitted then we're done.
	if (empty($_POST['upcont']))
	{
		return false;
	}

	// Get hold of our db
	$db = load_database();

	// Cleanup all the hooks (we are upgrading, so better have everything cleaned up)
	$db->skip_next_error();
	$db->query('', '
		DELETE FROM {db_prefix}settings
		WHERE variable = {string:integrate}',
		array(
			'integrate' => 'integrate_%',
		)
	);

	// Optionally empty the error log?
	if (!empty($_POST['empty_error']))
	{
		$db->truncate('{db_prefix}log_errors');
	}

	$changes = array();

	// If we're overriding the language follow it through.
	if (isset($_GET['lang']) && file_exists(SOURCEDIR . '/ElkArte/Languages/Index/' . ucfirst($_GET['lang']) . '.php'))
	{
		$changes['language'] = '\'' . ucfirst($_GET['lang']) . '\'';
	}

	// Place the board in to maintance mode?
	if (!empty($_POST['maint']))
	{
		$changes['maintenance'] = '2';

		// Remember what it was...
		$upcontext['user']['main'] = $maintenance;

		// Specifying a unique maintenance message, like god help us all or just the default
		if (!empty($_POST['maintitle']))
		{
			$changes['mtitle'] = '\'' . addslashes($_POST['maintitle']) . '\'';
			$changes['mmessage'] = '\'' . addslashes($_POST['mainmessage']) . '\'';
		}
		else
		{
			$changes['mtitle'] = '\'Upgrading the forum...\'';
			$changes['mmessage'] = '\'Don\\\'t worry, we will be back shortly with an updated forum.  It will only be a minute ;).\'';
		}
	}

	if ($command_line)
	{
		echo ' * Updating Settings.php...';
	}

	// Backup the current one first.
	copy(BOARDDIR . '/Settings.php', BOARDDIR . '/Settings_bak.php');

	// Fix some old paths.
	if (substr(BOARDDIR, 0, 1) === '.')
	{
		$changes['boarddir'] = '\'' . fixRelativePath(BOARDDIR) . '\'';
	}

	if (substr(SOURCEDIR, 0, 1) === '.')
	{
		$changes['sourcedir'] = '\'' . fixRelativePath(SOURCEDIR) . '\'';
	}

	if (!defined('CACHEDIR') || substr(CACHEDIR, 0, 1) === '.')
	{
		$changes['cachedir'] = '\'' . fixRelativePath(BOARDDIR) . '/cache\'';
	}

	// Not had the database type added before?
	if (empty($db_type))
	{
		$changes['db_type'] = 'mysql';
	}

	// Update Settings.php with the new settings.
	changeSettings($changes);

	if ($command_line)
	{
		echo ' Successful.' . "\n";
	}

	// Are we doing debug?
	if (isset($_POST['debug']))
	{
		$upcontext['upgrade_status']['debug'] = true;
		$is_debug = true;
	}

	// If we're not backing up then jump past that step.
	if (empty($_POST['backup']))
	{
		$upcontext['current_step']++;
	}

	// If we've got here then let's proceed to the next step!
	return true;
}

/**
 * Backup the database - why not...
 */
function action_backupDatabase()
{
	global $upcontext, $db_prefix, $command_line, $is_debug, $support_js, $file_steps;

	$upcontext['sub_template'] = isset($_GET['xml']) ? 'backup_xml' : 'backup_database';
	$upcontext['page_title'] = 'Backup Database';

	// Done it already - js wise?
	if (!empty($_POST['backup_done']))
	{
		return true;
	}

	// Some useful stuff here.
	$db = load_database();

	// Get all the table names.
	$filter = str_replace('_', '\_', preg_match('~^`(.+?)`\.(.+?)$~', $db_prefix, $match) != 0 ? $match[2] : $db_prefix) . '%';
	$db_name = preg_match('~^`(.+?)`\.(.+?)$~', $db_prefix, $match) != 0 ? strtr($match[1], array('`' => '')) : false;
	$tables = $db->list_tables($db_name, $filter);

	$table_names = array();
	foreach ($tables as $table)
	{
		if (substr($table, 0, 7) !== 'backup_')
		{
			$table_names[] = $table;
		}
	}

	$upcontext['table_count'] = count($table_names);
	$upcontext['cur_table_num'] = $_GET['substep'];
	$upcontext['cur_table_name'] = str_replace($db_prefix, '', $table_names[$_GET['substep']] ?? $table_names[0]);
	$upcontext['step_progress'] = (int) (($upcontext['cur_table_num'] / $upcontext['table_count']) * 100);

	// For non-java auto submit...
	$file_steps = $upcontext['table_count'];

	// What ones have we already done?
	foreach ($table_names as $id => $table)
	{
		if ($id < $_GET['substep'])
		{
			$upcontext['previous_tables'][] = $table;
		}
	}

	if ($command_line)
	{
		echo 'Backing Up Tables.';
	}

	// If we don't support javascript we backup here.
	if (!$support_js || isset($_GET['xml']))
	{
		// Backup each table!
		for ($substep = $_GET['substep'], $n = count($table_names); $substep < $n; $substep++)
		{
			$upcontext['cur_table_name'] = str_replace($db_prefix, '', ($table_names[$substep + 1] ?? $table_names[$substep]));
			$upcontext['cur_table_num'] = $substep + 1;
			$upcontext['step_progress'] = (int) (($upcontext['cur_table_num'] / $upcontext['table_count']) * 100);

			// Do we need to pause?
			nextSubstep($substep);

			backupTable($table_names[$substep]);

			// If this is XML to keep it nice for the user do one table at a time anyway!
			if (isset($_GET['xml']))
			{
				return upgradeExit();
			}
		}

		if ($is_debug && $command_line)
		{
			echo "\n" . ' Successful.\'' . "\n";
			flush();
		}

		$upcontext['step_progress'] = 100;

		$_GET['substep'] = 0;

		// Make sure we move on!
		return true;
	}

	// Either way next place to post will be database changes!
	$_GET['substep'] = 0;

	return false;
}

/**
 * Backup one table...
 *
 * @param string $table
 */
function backupTable($table)
{
	global $is_debug, $command_line, $db_prefix;

	if ($is_debug && $command_line)
	{
		echo "\n" . ' +++ Backing up \"' . str_replace($db_prefix, '', $table) . '"...';
		flush();
	}

	$db = load_database();
	$db->backup_table($table, 'backup_' . $table);

	if ($is_debug && $command_line)
	{
		echo ' done.';
	}
}

/**
 * Step 2: Everything that needs to be done to the database
 */
function action_databaseChanges()
{
	global $modSettings, $command_line, $upcontext, $support_js;

	$db = load_database();

	// Have we just completed this?
	if (!empty($_POST['database_done']))
	{
		return true;
	}

	$upcontext['sub_template'] = isset($_GET['xml']) ? 'database_xml' : 'database_changes';
	$upcontext['page_title'] = 'Database Changes';

	// All possible files.
	// Name, less than version, insert_on_complete.
	$files = getUpgradeFiles();
	$files_todo = array();

	// How many files are there in total?
	$filecount = 0;
	if (isset($_GET['filecount']))
	{
		$filecount = (int) $_GET['filecount'];
	}

	// Find all update files that are appropriate for this version of ElkArte
	$upcontext['file_count'] = 0;
	foreach ($files as $file)
	{
		if (file_exists(__DIR__ . '/' . $file[0]) && version_compare($modSettings['elkVersion'], $file[1]) <= 0)
		{
			$files_todo[] = $file;
			$upcontext['file_count']++;
		}
	}

	// Do each file!
	$upcontext['step_progress'] = 0;
	$upcontext['cur_file_num'] = 0;
	foreach ($files_todo as $file)
	{
		$upcontext['cur_file_num']++;
		$upcontext['cur_file_name'] = $file[0];

		if ($filecount > $upcontext['cur_file_num'])
		{
			continue;
		}

		// @todo Do we actually need to do this still?
		if (file_exists(__DIR__ . '/' . $file[0]) && (!isset($modSettings['elkVersion']) || version_compare($modSettings['elkVersion'], $file[1]) <= 0))
		{
			$nextFile = parse_sql(__DIR__ . '/' . $file[0]);
			if ($nextFile)
			{
				// Only update the version of this if complete.
				$db->replace(
					'{db_prefix}settings',
					array('variable' => 'string', 'value' => 'string'),
					array('elkVersion', $file[2]),
					array('variable')
				);

				$modSettings['elkVersion'] = $file[2];
			}

			// If this is XML we only do this stuff once.
			if (isset($_GET['xml']))
			{
				// Flag to move on to the next.
				$upcontext['completed_step'] = true;

				// Did we complete the whole file?
				if ($nextFile)
				{
					$upcontext['current_debug_item_num'] = -1;
				}

				return upgradeExit();
			}
			elseif ($support_js)
			{
				break;
			}
		}

		// Set the progress bar to be right as if we had - even if we hadn't...
		$upcontext['step_progress'] = ($upcontext['cur_file_num'] / $upcontext['file_count']) * 100;
	}

	$_GET['substep'] = 0;

	// So the template knows we're done.
	if (!$support_js)
	{
		$upcontext['changes_complete'] = true;

		// If this is the command line we can't do any more.
		if ($command_line)
		{
			return action_deleteUpgrade();
		}

		return true;
	}

	return false;
}

/**
 * Remove 1.x files that have been moved or removed from 2.0
 */
function action_deleteOldFiles()
{
	$fileFunc = \ElkArte\FileFunctions::instance();

	// Remove old files
	$fileFunc->delete(BOARDDIR . '/sources/AbstractModel.php');
	$fileFunc->delete(BOARDDIR . '/sources/Action.controller.php');
	$fileFunc->delete(BOARDDIR . '/sources/Autoloader.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/BrowserDetector.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/CurlFetchWebdata.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/Debug.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/Errors.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/Errors.php');
	$fileFunc->delete(BOARDDIR . '/sources/FrontpageInterface.php');
	$fileFunc->delete(BOARDDIR . '/sources/Hooks.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/Request.php');
	$fileFunc->delete(BOARDDIR . '/sources/Server.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/SiteCombiner.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/SiteDispatcher.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/Templates.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/Theme.php');
	$fileFunc->delete(BOARDDIR . '/sources/ValuesContainer.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/Db-abstract.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/Db-mysql.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/Db-postgresql.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/Db.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/DbSearch-mysql.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/DbSearch-postgresql.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/DbSearch.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/DbTable-mysql.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/DbTable-postgresql.php');
	$fileFunc->delete(BOARDDIR . '/sources/database/DbTable.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/ext/PasswordHash.php');
	$fileFunc->delete(BOARDDIR . '/sources/ext/serialize.php');
	$fileFunc->delete(BOARDDIR . '/sources/ext/cssmin/cssmin.php');
	$fileFunc->delete(BOARDDIR . '/sources/ext/cssmin/README.md');
	$fileFunc->delete(BOARDDIR . '/sources/ext/cssmin/tubalmartin');
	$fileFunc->delete(BOARDDIR . '/sources/ext/cssmin/cssmin.php');
	$fileFunc->delete(BOARDDIR . '/sources/ext/cssmin/README.md');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/BuddyMention.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/LikemsgMention.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/MailfailMention.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/MentionBoardAccessAbstract.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/MentionmemMention.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/MentionMessageAbstract.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/MentionTypeInterface.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/QuotedmemMention.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MentionType/RlikemsgMention.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Action.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/AdminSettingsSearch.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Agreement.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/BadBehavior.subs.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/BoardsList.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Cache.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/CalendarEvent.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Censor.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/DataValidator.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Drafts.integrate.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/EmailFormat.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/EmailParse.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/EmailSettings.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Event.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/EventManager.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/FtpConnection.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/GenericList.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Html2BBC.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Html2Md.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/HttpReq.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Ila.integrate.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Mentioning.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MessagesDelete.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/MessageTopicIcons.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Notifications.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/NotificationsTask.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/OpenID.subs.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/PackagesFilterIterator.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/PbeImap.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Permission.subs.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Permissions.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Priority.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/PrivacyPolicy.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/QueryAnalysis.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Recent.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Search.subs.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/SettingsForm.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Subscriptions-Authorize.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Subscriptions-PayPal.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Subscriptions-twoCheckOut.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Suggest.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/TemplateLayers.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/TokenHash.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/TopicsMerge.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/TopicUtil.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Unread.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/UnTgz.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/UnZip.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/UserNotification.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/UserNotification.integrate.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/Util.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/VerificationControls.class.php');
	$fileFunc->delete(BOARDDIR . '/sources/subs/XmlArray.class.php');
	$fileFunc->delete(BOARDDIR . '/themes/default/BadBehavior.template.php');
	$fileFunc->delete(BOARDDIR . '/themes/default/images/profile/aim.png');
	$fileFunc->delete(BOARDDIR . '/themes/default/scripts/jquery-ui-1.12.1.min.js');
	$fileFunc->delete(BOARDDIR . '/themes/default/scripts/spellcheck.js');

	// Remove old directories and their files
	$fileFunc->rmDir(BOARDDIR . '/sources/admin');
	$fileFunc->rmDir(BOARDDIR . '/sources/controllers');
	$fileFunc->rmDir(BOARDDIR . '/sources/modules');
	$fileFunc->rmDir(BOARDDIR . '/sources/ext/bad-behavior');
	$fileFunc->rmDir(BOARDDIR . '/sources/ext/cssmin/tubalmartin');
	$fileFunc->rmDir(BOARDDIR . '/themes/default/languages');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/BBC');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/CacheMethod');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/Errors');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/Exception');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/ScheduledTask');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/Search');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/SessionHandler');
	$fileFunc->rmDir(BOARDDIR . '/sources/subs/SettingsFormAdapter');
}

/**
 * Delete the damn thing!
 * Finalizes the upgrade
 * Updates' maintenance mode to what it was before the upgrade started
 * Updates settings.php, sometimes even correctly
 * Flushes the cache so there is a clean start
 */
function action_deleteUpgrade()
{
	global $command_line, $language, $upcontext, $maintenance, $db_type, $modSettings;

	// Now it's nice to have some of the basic source files.
	if (!isset($_GET['ssi']) && !$command_line)
	{
		redirectLocation('&ssi=1');
	}

	$upcontext['sub_template'] = 'upgrade_complete';
	$upcontext['page_title'] = 'Upgrade Complete';

	$endl = $command_line ? "\n" : '<br />' . "\n";

	$changes = array(
		'language' => '\'' . (substr($language, -4) === '.lng' ? substr($language, 0, -4) : $language) . '\'',
		'db_error_send' => '1',
	);

	// Are we in maintenance mode?
	if (isset($upcontext['user']['main']))
	{
		if ($command_line)
		{
			echo ' * ';
		}

		$upcontext['removed_maintenance'] = true;
		$changes['maintenance'] = $upcontext['user']['main'];
	}
	// Otherwise if somehow we are in 2 let's go to 1.
	elseif (!empty($maintenance) && $maintenance == 2)
	{
		$changes['maintenance'] = 1;
	}

	// Wipe this out...
	$upcontext['user'] = array();

	// @todo this is mad and needs to be looked at
	// Make a backup of Settings.php first as otherwise earlier changes are lost.
	copy(BOARDDIR . '/Settings.php', BOARDDIR . '/Settings_bak.php');
	changeSettings($changes);

	// Now remove our marker
	$changes = array(
		'upgradeData' => '#remove#',
	);
	copy(BOARDDIR . '/Settings.php', BOARDDIR . '/Settings_bak.php');
	changeSettings($changes);

	// Clean any old cache files away.
	clean_cache();

	// Can we delete the file?
	$upcontext['can_delete_script'] = is_writable(__DIR__) || is_writable(__FILE__);

	// Log what we've done.
	if (empty(ElkArte\User::$info->id))
	{
		ElkArte\User::$info->id = !empty($upcontext['user']['id']) ? $upcontext['user']['id'] : 0;
	}

	// We need to log in the database
	$db = load_database();

	// Log the action manually, so CLI still works.
	$db->insert('',
		'{db_prefix}log_actions',
		array(
			'log_time' => 'int', 'id_log' => 'int', 'id_member' => 'int', 'ip' => 'string-16', 'action' => 'string',
			'id_board' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'extra' => 'string-65534',
		),
		array(
			time(), 3, ElkArte\User::$info->id, $command_line ? '127.0.0.1' : ElkArte\User::$info->ip, 'upgrade',
			0, 0, 0, serialize(array('version' => CURRENT_VERSION, 'member' => ElkArte\User::$info->id)),
		),
		array('id_action')
	);

	ElkArte\User::$info->id = 0;

	// Drop old check for MySQL 5.0.50 and 5.0.51 bug.
	removeSettings('db_mysql_group_by_fix');

	// Set jquery to auto if its not already set
	if (!isset($modSettings['jquery_source']))
	{
		updateSettings(array('jquery_source' => 'auto'));
	}

	if ($command_line)
	{
		echo $endl;
		echo 'Upgrade Complete!', $endl;
		echo 'Please delete this file as soon as possible for security reasons.', $endl;
		exit;
	}

	// Make sure it says we're done.
	$upcontext['overall_percent'] = 100;
	if (isset($upcontext['step_progress']))
	{
		unset($upcontext['step_progress']);
	}

	$_GET['substep'] = 0;

	return false;
}

/**
 * Reads in setting_bak.php file
 * Removes flagged settings
 * Appends new settings as passed in $config_vars to the array
 * Writes out a new Settings.php file, overwriting any that may have existed
 *
 * @param array $config_vars
 */
function changeSettings($config_vars)
{
	$settingsArray = file(BOARDDIR . '/Settings_bak.php');

	$save_vars = array();
	foreach ($config_vars as $key => $var)
	{
		$save_vars[$key] = trim($var, '\'');
	}

	saveFileSettings($save_vars, $settingsArray);
}

/**
 * Fixes any relative path names that may have been supplied
 */
function fixRelativePath($path)
{
	global $install_path;

	// Fix the . at the start, clear any duplicate slashes, and fix any trailing slash...
	return addslashes(preg_replace(array('~^\.([/\\\]|$)~', '~[/]+~', '~[\\\]+~', '~[/\\\]$~'), array($install_path . '$1', '/', '\\', ''), $path));
}

/**
 * Used to parse our upgrade files
 */
function parse_sql($filename)
{
	global $db_prefix, $boardurl, $command_line, $file_steps, $step_progress, $upcontext, $support_js, $is_debug;

	$replaces = array(
		'{$db_prefix}' => $db_prefix,
		'{BOARDDIR}' => BOARDDIR,
		'{$boardurl}' => $boardurl,
		'{$db_collation}' => discoverCollation()
	);
	$db = load_database();
	$db_table = db_table_install();
	$db_wrapper = new DbWrapper($db, $replaces);
	$db_table_wrapper = new DbTableWrapper($db_table);

	// Make our own error handler.
	set_error_handler('sql_error_handler');

	$endl = $command_line ? "\n" : '<br />' . "\n";
	require_once($filename);

	$class_name = 'UpgradeInstructions_' . str_replace('-', '_', basename($filename, '.php'));
	$install_instance = new $class_name($db_wrapper, $db_table_wrapper);

	// All the methods (steps) in this upgrade file
	$methods = array_filter(get_class_methods($install_instance), function ($method) {
		return substr($method, 0, 2) !== '__' && substr($method, -6) !== '_title';
	});

	$substep = 0;

	// Count the total number of steps within this file - for the progress bar.
	$file_steps = countSteps($install_instance, $methods);
	$upcontext['total_items'] = count($methods);
	$upcontext['debug_items'] = $file_steps;
	$upcontext['current_item_num'] = 0;
	$upcontext['current_item_name'] = '';
	$upcontext['current_debug_item_num'] = 0;
	$upcontext['current_debug_item_name'] = '';

	// This array keeps a record of what we've done in case javascript is dead...
	$upcontext['actioned_items'] = array();

	$done_something = false;

	// Start calling each method required to upgrade
	foreach ($methods as $method)
	{
		$do_current = $substep >= $_GET['substep'];

		// Always flush.  Flush, flush, flush.  Flush, flush, flush, flush!  FLUSH!
		if ($is_debug && !$support_js && $command_line)
		{
			flush();
		}

		$upcontext['current_item_num']++;
		$title = htmlspecialchars(rtrim($install_instance->{$method . '_title'}()), ENT_COMPAT);
		$upcontext['current_item_name'] = $title;

		if ($do_current)
		{
			$upcontext['actioned_items'][] = $title;
			if ($command_line)
			{
				echo ' * ';
			}
		}

		// Each method returns an array of actions
		$actions = $install_instance->{$method}();
		foreach ($actions as $action)
		{
			$upcontext['step_progress'] += (100 / $upcontext['file_count']) / $file_steps;
			$upcontext['current_debug_item_num']++;
			$upcontext['current_debug_item_name'] = htmlspecialchars(rtrim($action['debug_title']), ENT_COMPAT);

			// Have we already done something?
			if (isset($_GET['xml']) && $done_something)
			{
				restore_error_handler();

				return $upcontext['current_debug_item_num'] >= $upcontext['debug_items'];
			}

			if ($command_line)
			{
				echo ' +++ ' . $upcontext['current_debug_item_name'];
			}

			$action['function']();

			// Small step - only if we're actually doing stuff.
			if ($do_current)
			{
				$done_something = true;

				// nextSubstep calls upgradeExit that terminates the execution if necessary.
				nextSubstep(++$substep);
			}
			else
			{
				$substep++;
			}

			if ($command_line)
			{
				echo ' done.' . $endl;
			}
			else
			{
				if ($is_debug)
				{
					$upcontext['actioned_items'][] = $upcontext['current_debug_item_name'];
				}
			}
		}

		// If this is xml based and we're just getting the item name then that's grand.
		if ($support_js && !isset($_GET['xml']) && $upcontext['current_debug_item_name'] != '' && $do_current)
		{
			restore_error_handler();

			return false;
		}

		if (!$support_js && $do_current && $_GET['substep'] != 0 && $command_line)
		{
			echo ' Successful.', $endl;
			flush();
		}

		// Clean up by cleaning any step info.
		$step_progress = array();
	}

	// Put back the error handler.
	restore_error_handler();

	if ($command_line)
	{
		echo ' Successful.' . "\n";
		flush();
	}

	$_GET['substep'] = 0;

	return true;
}

/**
 * The next substep.
 *
 * @param int $substep
 */
function nextSubstep($substep)
{
	global $start_time, $timeLimitThreshold, $command_line, $custom_warning, $step_progress, $is_debug, $upcontext;

	if ($_GET['substep'] < $substep)
	{
		$_GET['substep'] = $substep;
	}

	if ($command_line)
	{
		if (time() - $start_time > 1 && empty($is_debug))
		{
			echo '.';
			$start_time = time();
		}

		return;
	}

	@set_time_limit(300);
	if (function_exists('apache_reset_timeout'))
	{
		@apache_reset_timeout();
	}

	if (time() - $start_time <= $timeLimitThreshold)
	{
		return;
	}

	// Do we have some custom step progress stuff?
	if (!empty($step_progress))
	{
		$upcontext['substep_progress'] = 0;
		$upcontext['substep_progress_name'] = $step_progress['name'] ?? '';
		if ($step_progress['current'] > $step_progress['total'])
		{
			$upcontext['substep_progress'] = 99.9;
		}
		else
		{
			$upcontext['substep_progress'] = ($step_progress['current'] / $step_progress['total']) * 100;
		}

		// Make it nicely rounded.
		$upcontext['substep_progress'] = round($upcontext['substep_progress'], 1);
	}

	// If this is XML we just exit right away!
	if (isset($_GET['xml']))
	{
		return upgradeExit();
	}

	// We're going to pause after this!
	$upcontext['pause'] = true;

	$upcontext['query_string'] = '';
	foreach ($_GET as $k => $v)
	{
		if ($k != 'data' && $k != 'substep' && $k != 'step')
		{
			$upcontext['query_string'] .= ';' . $k . '=' . $v;
		}
	}

	// Custom warning?
	if (!empty($custom_warning))
	{
		$upcontext['custom_warning'] = $custom_warning;
	}

	upgradeExit();
}

/**
 * Step 0 if running from the CLI, similar to welcome from the GUI
 *
 * Preforms several checks to make sure the appropriate files are available to do the updates
 * Validates php and db versions meet the minimum requirements
 * Validates the credentials supplied have db alter privileges
 * Checks that needed files/directories are writable
 */
function cmdStep0()
{
	global $modSettings, $start_time, $db_type, $upcontext, $is_debug;

	// Lots of access and existence checking is needed
	$fileFunc = \ElkArte\FileFunctions::instance();

	$start_time = time();

	@ob_end_clean();
	ob_implicit_flush();
	@set_time_limit(600);

	if (!isset($_SERVER['argv']))
	{
		$_SERVER['argv'] = array();
	}
	$_GET['maint'] = 1;

	foreach ($_SERVER['argv'] as $i => $arg)
	{
		if (preg_match('~^--language=(.+)$~', $arg, $match) != 0)
		{
			$_GET['lang'] = $match[1];
		}
		elseif (preg_match('~^--path=(.+)$~', $arg) != 0)
		{
			continue;
		}
		elseif ($arg === '--no-maintenance')
		{
			$_GET['maint'] = 0;
		}
		elseif ($arg === '--debug')
		{
			$is_debug = true;
		}
		elseif ($arg === '--backup')
		{
			$_POST['backup'] = 1;
		}
		elseif ($arg === '--template' && (file_exists(BOARDDIR . '/template.php') || file_exists(BOARDDIR . '/template.html') && !file_exists($modSettings['theme_dir'] . '/converted')))
		{
			$_GET['conv'] = 1;
		}
		elseif ($i != 0)
		{
			echo 'ElkArte Command-line Upgrader
Usage: /path/to/php -f ' . basename(__FILE__) . ' -- [OPTION]...

    --language=LANG         Reset the forum\'s language to LANG.
    --no-maintenance        Don\'t put the forum into maintenance mode.
    --debug                 Output debugging information.
    --backup                Create backups of tables with "backup_" prefix.';
			echo "\n";
			exit;
		}
	}

	if (version_compare(REQUIRED_PHP_VERSION, PHP_VERSION, '>='))
	{
		print_error('Error: PHP ' . PHP_VERSION . ' does not match version requirements.', true);
	}

	if (!db_version_check())
	{
		print_error('Error: ' . $GLOBALS['databases'][$db_type]['name'] . ' ' . $GLOBALS['databases'][$db_type]['version'] . ' does not match minimum requirements.', true);
	}

	$db = load_database();

	$db->skip_next_error();
	if (!empty($GLOBALS['databases'][$db_type]['alter_support'])
		&& $db->query('', '	ALTER TABLE {db_prefix}log_digest ORDER BY id_topic', array()) === false)
	{
		print_error('Error: The ' . $GLOBALS['databases'][$db_type]['name'] . ' account in Settings.php does not have sufficient privileges.', true);
	}

	$check = $fileFunc->fileExists($modSettings['theme_dir'] . '/index.template.php')
		&& $fileFunc->fileExists(SOURCEDIR . '/QueryString.php')
		&& $fileFunc->fileExists(SOURCEDIR . '/ElkArte/AdminController/ManageBoards.controller.php');
	if (!$check && !isset($modSettings['elkVersion']))
	{
		print_error('Error: Some files are missing or out-of-date.', true);
	}

	// Do a quick version spot check.
	$temp = substr(@implode('', @file(BOARDDIR . '/bootstrap.php')), 0, 4096);
	preg_match('~\*\s@version\s+(.+)~i', $temp, $match);
	if (empty($match[1]) || $match[1] != CURRENT_VERSION)
	{
		print_error('Error: Some files have not yet been updated properly.');
	}

	// Make sure Settings.php is writable.
	if ($fileFunc->chmod(BOARDDIR . '/Settings.php') === false)
	{
		print_error('Error: Unable to obtain write access to "Settings.php".', true);
	}

	// Make sure Settings.bak.php is writable.
	if ($fileFunc->chmod(BOARDDIR . '/Settings_bak.php') === false)
	{
		print_error('Error: Unable to obtain write access to "Settings_bak.php".');
	}

	// Make sure themes is writable.
	if ($fileFunc->chmod($modSettings['theme_dir']) === false)
	{
		print_error('Error: Unable to obtain write access to "themes".');
	}

	// Make sure cache directory exists and is writable!
	$CACHEDIR_temp = !defined('CACHEDIR') ? BOARDDIR . '/cache' : CACHEDIR;
	if (!$fileFunc->createDirectory($CACHEDIR_temp))
	{
		print_error('Error: Unable to obtain write access to "cache".', true);
	}

	// Make sure the custom avatar directory exists and is writable!
	$custom_avatar_dir = !empty($modSettings['custom_avatar_dir']) ? $modSettings['custom_avatar_dir'] : BOARDDIR . '/avatars_user';
	if (!$fileFunc->createDirectory($custom_avatar_dir))
	{
		print_error('Error: Unable to obtain write access to "' . $custom_avatar_dir . '"', true);
	}

	if (!$fileFunc->fileExists(SOURCEDIR . '/ElkArte/Languages/Index/' . $upcontext['language'] . '.php') && !isset($modSettings['elkVersion'], $_GET['lang']))
	{
		print_error('Error: Unable to find language files!', true);
	}
	else
	{
		$temp = substr(@implode('', @file(SOURCEDIR . '/ElkArte/Languages/Index/' . $upcontext['language'] . '.php')), 0, 4096);
		preg_match('~(?://|/\*)\s*Version:\s+(.+?);\s*index(?:[\s]{2}|\*/)~i', $temp, $match);

		if (empty($match[1]) || $match[1] != CURRENT_LANG_VERSION)
		{
			print_error('Error: Language files out of date.', true);
		}

		if (!$fileFunc->fileExists(SOURCEDIR . '/ElkArte/Languages/Install/' . $upcontext['language'] . '.php'))
		{
			print_error('Error: Install language is missing for selected language.', true);
		}

		// Otherwise, include it!
		require_once(SOURCEDIR . '/ElkArte/Languages/Install/' . $upcontext['language'] . '.php');
	}

	// Make sure we skip the HTML for login.
	$_POST['upcont'] = true;
	$upcontext['current_step'] = 1;
}

/**
 * Displays an error on standard out for cli viewing, optionally ends execution
 *
 * @param string $message
 * @param boolean $fatal
 */
function print_error($message, $fatal = false)
{
	static $fp = null;

	if ($fp === null)
	{
		$fp = fopen('php://stderr', 'wb');
	}

	fwrite($fp, $message . "\n");

	if ($fatal)
	{
		exit;
	}
}

/**
 * Displays and error using the error template
 *
 * @param string $message
 * @param bool $fatal
 */
function throw_error($message, $fatal = false)
{
	global $upcontext;

	$upcontext['fatal'] = $fatal;
	$upcontext['error_msg'] = $message;
	$upcontext['sub_template'] = 'error_message';

	return false;
}

/**
 * In the event some critical functions are missing from the include files
 * due to from what we may be upgrading, they are defined here as well
 */
function loadEssentialFunctions()
{
	if (!function_exists('ip2range'))
	{
		require_once(SOURCEDIR . '/Subs.php');
	}

	if (!function_exists('cache_put_data'))
	{
		function cache_put_data($val)
		{

		}
	}

	if (!function_exists('un_htmlspecialchars'))
	{
		function un_htmlspecialchars($string)
		{
			$string = htmlspecialchars_decode($string, ENT_QUOTES);
			return str_replace('&nbsp;', ' ', $string);
		}
	}

	if (!function_exists('text2words'))
	{
		function text2words($text)
		{
			// Step 0: prepare numbers, so they are good for search & index 1000.45 -> 1000_45
			$words = preg_replace('~([\d]+)[.-/]+(?=[\d])~u', '$1_', $text);

			// Step 1: Remove entities/things we don't consider words:
			$words = preg_replace('~(?:[\x0B\0\x{A0}\t\r\s\n(){}\\[\\]<>!@$%^*.,:+=`\~\?/\\\\]+|&(?:amp|lt|gt|quot);)+~u', ' ', strtr($words, array('<br />' => ' ')));

			// Step 2: Entities we left to letters, where applicable, lowercase.
			$words = un_htmlspecialchars(ElkArte\Util::strtolower($words));

			// Step 3: Ready to split apart and index!
			$words = explode(' ', $words);

			// Trim characters before and after and add slashes for database insertion.
			$returned_words = array();
			foreach ($words as $word)
			{
				if (($word = trim($word, '-_\'')) !== '')
				{
					$returned_words[] = substr($word, 0, 20);
				}
			}

			// Filter out all words that occur more than once.
			return array_unique($returned_words);
		}
	}

	if (!function_exists('clean_cache'))
	{
		// Empty out the cache folder.
		function clean_cache($type = '')
		{
			// No directory = no game.
			if (!is_dir(CACHEDIR))
			{
				return;
			}

			// Remove the files in our own disk cache, if any
			$dh = opendir(CACHEDIR);
			while ($file = readdir($dh))
			{
				if ($file !== '.' && $file !== '..' && $file !== 'index.php' && $file !== '.htaccess' && (!$type || substr($file, 0, strlen($type)) == $type))
				{
					@unlink(CACHEDIR . '/' . $file);
				}
			}
			closedir($dh);

			// Invalidate cache, to be sure!
			// ... as long as Load.php can be modified, anyway.
			@touch(SOURCEDIR . '/index.php');
			clearstatcache();
		}
	}

	// MD5 Encryption.
	if (!function_exists('md5_hmac'))
	{
		function md5_hmac($data, $key)
		{
			$key = str_pad(strlen($key) <= 64 ? $key : pack('H*', md5($key)), 64, chr(0x00));

			return md5(($key ^ str_repeat(chr(0x5c), 64)) . pack('H*', md5(($key ^ str_repeat(chr(0x36), 64)) . $data)));
		}
	}
}

function discoverCollation()
{
	global $db_type, $db_prefix;

	$db_collation = '';

	// If we're on MySQL supporting collations then let's find out what the members table uses and put it in a global var - to allow upgrade script to match collations!
	if (!empty($GLOBALS['databases'][$db_type]['test_collation']))
	{
		$db = load_database();

		$db->skip_next_error();
		$request = $db->query('', '
			SHOW TABLE STATUS
			LIKE {string:table_name}',
			array(
				'table_name' => "{$db_prefix}members",
			)
		);
		if ($request->num_rows() == 0)
		{
			die('Unable to find members table!');
		}
		$table_status = $request->fetch_assoc();
		$request->free_result();

		if (!empty($table_status['Collation']))
		{
			$db->skip_next_error();
			$request = $db->query('', '
				SHOW COLLATION
				LIKE {string:collation}',
				array(
					'collation' => $table_status['Collation'],
				)
			);
			// Got something?
			if ($request->num_rows() != 0)
			{
				$collation_info = $request->fetch_assoc();
			}
			$request->free_result();

			// Excellent!
			if (!empty($collation_info['Collation']) && !empty($collation_info['Charset']))
			{
				$db_collation = ' CHARACTER SET ' . $collation_info['Charset'] . ' COLLATE ' . $collation_info['Collation'];
			}
		}
	}

	return $db_collation;
}

function countSteps($install_instance, $methods)
{
	$total = 0;
	foreach ($methods as $method)
	{
		$action = $install_instance->{$method}();
		$total += count($action);
	}

	return $total;
}
