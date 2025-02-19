<?php

/**
 * This file handles the administration of languages tasks.
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

namespace ElkArte\AdminController;

use ElkArte\AbstractController;
use ElkArte\Action;
use ElkArte\Cache\Cache;
use ElkArte\Exceptions\Exception;
use ElkArte\FileFunctions;
use ElkArte\SettingsForm\SettingsForm;
use ElkArte\Languages\Txt;
use ElkArte\Util;
use ElkArte\Languages\Editor as LangEditor;
use ElkArte\Languages\Loader as LangLoader;

/**
 * Manage languages controller class.
 *
 * @package Languages
 */
class ManageLanguages extends AbstractController
{
	/**
	 * This is the main function for the languages' area.
	 *
	 * What it does:
	 *
	 * - It dispatches the requests.
	 * - Loads the ManageLanguages template. (sub-actions will use it)
	 *
	 * @event integrate_sa_manage_languages Used to add more sub actions
	 * @uses ManageSettings language file
	 * @see  \ElkArte\AbstractController::action_index()
	 */
	public function action_index()
	{
		global $context, $txt;

		theme()->getTemplates()->load('ManageLanguages');
		Txt::load('ManageSettings');

		$subActions = array(
			'edit' => array($this, 'action_edit', 'permission' => 'admin_forum'),
			'settings' => array($this, 'action_languageSettings_display', 'permission' => 'admin_forum'),
			'downloadlang' => array($this, 'action_downloadlang', 'permission' => 'admin_forum'),
			'editlang' => array($this, 'action_editlang', 'permission' => 'admin_forum'),
		);

		// Get ready for action
		$action = new Action('manage_languages');

		// Load up all the tabs...
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['language_configuration'],
			'description' => $txt['language_description'],
		);

		// By default we're managing languages, call integrate_sa_manage_languages
		$subAction = $action->initialize($subActions, 'edit');

		// Some final bits
		$context['sub_action'] = $subAction;
		$context['page_title'] = $txt['edit_languages'];
		$context['sub_template'] = 'show_settings';

		// Call the right function for this sub-action.
		$action->dispatch($subAction);
	}

	/**
	 * Interface for adding a new language.
	 *
	 * @uses ManageLanguages template, add_language sub-template.
	 */
	public function action_add()
	{
		global $context, $txt;

		// Are we searching for new languages on the site?
		if (!empty($this->_req->post->lang_add_sub))
		{
			// Need fetch_web_data.
			require_once(SUBSDIR . '/Package.subs.php');
			require_once(SUBSDIR . '/Language.subs.php');

			$context['elk_search_term'] = $this->_req->getPost('lang_add', 'trim|htmlspecialchars[ENT_COMPAT]');

			$listOptions = array(
				'id' => 'languages',
				'get_items' => array(
					'function' => 'list_getLanguagesList',
				),
				'columns' => array(
					'name' => array(
						'header' => array(
							'value' => $txt['name'],
						),
						'data' => array(
							'db' => 'name',
						),
					),
					'description' => array(
						'header' => array(
							'value' => $txt['add_language_elk_desc'],
						),
						'data' => array(
							'db' => 'description',
						),
					),
					'version' => array(
						'header' => array(
							'value' => $txt['add_language_elk_version'],
						),
						'data' => array(
							'db' => 'version',
						),
					),
					'utf8' => array(
						'header' => array(
							'value' => $txt['add_language_elk_utf8'],
						),
						'data' => array(
							'db' => 'utf8',
						),
					),
					'install_link' => array(
						'header' => array(
							'value' => $txt['add_language_elk_install'],
							'class' => 'centertext',
						),
						'data' => array(
							'db' => 'install_link',
							'class' => 'centertext',
						),
					),
				),
			);

			createList($listOptions);
		}

		$context['sub_template'] = 'add_language';
	}

	/**
	 * This lists all the current languages and allows editing of them.
	 */
	public function action_edit()
	{
		global $txt, $context, $language;

		require_once(SUBSDIR . '/Language.subs.php');
		$fileFunc = FileFunctions::instance();

		// Setting a new default?
		if (!empty($this->_req->post->set_default) && !empty($this->_req->post->def_language))
		{
			checkSession();
			validateToken('admin-lang');

			$lang_exists = false;
			$available_langs = getLanguages();
			foreach ($available_langs as $lang)
			{
				if ($this->_req->post->def_language === $lang['filename'])
				{
					$lang_exists = true;
					break;
				}
			}

			if ($this->_req->post->def_language !== $language && $lang_exists)
			{
				$language = $this->_req->post->def_language;
				$this->updateLanguage($language);
				redirectexit('action=admin;area=languages;sa=edit');
			}
		}

		// Create another one time token here.
		createToken('admin-lang');
		createToken('admin-ssc');

		$listOptions = array(
			'id' => 'language_list',
			'items_per_page' => 20,
			'base_href' => getUrl('admin', ['action' => 'admin', 'area' => 'languages']),
			'title' => $txt['edit_languages'],
			'data_check' => array(
				'class' => function ($rowData) {
					if ($rowData['default'])
					{
						return 'highlight2';
					}
					else
					{
						return '';
					}
				},
			),
			'get_items' => array(
				'function' => 'list_getLanguages',
			),
			'get_count' => array(
				'function' => 'list_getNumLanguages',
			),
			'columns' => array(
				'default' => array(
					'header' => array(
						'value' => $txt['languages_default'],
						'class' => 'centertext',
					),
					'data' => array(
						'function' => function ($rowData) {
							return '<input type="radio" name="def_language" value="' . $rowData['id'] . '" ' . ($rowData['default'] ? 'checked="checked"' : '') . ' class="input_radio" />';
						},
						'style' => 'width: 8%;',
						'class' => 'centertext',
					),
				),
				'name' => array(
					'header' => array(
						'value' => $txt['languages_lang_name'],
					),
					'data' => array(
						'function' => function ($rowData) {
							return sprintf('<a href="%1$s">%2$s<i class="icon icon-small i-modify"></i></a>', getUrl('admin', ['action' => 'admin', 'area' => 'languages', 'sa' => 'editlang', 'lid' => $rowData['id']]), $rowData['name']);
						},
					),
				),
				'count' => array(
					'header' => array(
						'value' => $txt['languages_users'],
					),
					'data' => array(
						'db_htmlsafe' => 'count',
					),
				),
				'locale' => array(
					'header' => array(
						'value' => $txt['languages_locale'],
					),
					'data' => array(
						'db_htmlsafe' => 'locale',
					),
				),
			),
			'form' => array(
				'href' => getUrl('admin', ['action' => 'admin', 'area' => 'languages']),
				'token' => 'admin-lang',
			),
			'additional_rows' => array(
				array(
					'class' => 'submitbutton',
					'position' => 'bottom_of_list',
					'value' => '
						<input type="hidden" name="' . $context['session_var'] . '" value="' . $context['session_id'] . '" />
						<input type="submit" name="set_default" value="' . $txt['save'] . '"' . ($fileFunc->isWritable(BOARDDIR . '/Settings.php') ? '' : ' disabled="disabled"') . ' />
						<input type="hidden" name="' . $context['admin-ssc_token_var'] . '" value="' . $context['admin-ssc_token'] . '" />',
				),
			),
			// For highlighting the default.
			'javascript' => '
				initHighlightSelection(\'language_list\');
			',
		);

		// Display a warning if we cannot edit the default setting.
		if (!$fileFunc->isWritable(BOARDDIR . '/Settings.php'))
		{
			$listOptions['additional_rows'][] = array(
				'position' => 'after_title',
				'value' => $txt['language_settings_writable'],
				'class' => 'smalltext alert',
			);
		}

		createList($listOptions);

		$context['sub_template'] = 'show_list';
		$context['default_list'] = 'language_list';
	}

	/**
	 * Update the language in use
	 *
	 * @param string $language
	 */
	private function updateLanguage($language)
	{
		$configVars = array(
			array('language', '', 'file')
		);
		$configValues = array(
			'language' => $language
		);
		$settingsForm = new SettingsForm(SettingsForm::FILE_ADAPTER);
		$settingsForm->setConfigVars($configVars);
		$settingsForm->setConfigValues((array) $configValues);
		$settingsForm->save();
	}

	/**
	 * Download a language file from the website.
	 *
	 * What it does:
	 *
	 * - Requires a valid download ID ("did") in the URL.
	 * - Also handles installing language files.
	 * - Attempts to chmod things as needed.
	 * - Uses a standard list to display information about all the files and where they'll be put.
	 *
	 * @uses ManageLanguages template, download_language sub-template.
	 * @uses Admin template, show_list sub-template.
	 */
	public function action_downloadlang()
	{
		// @todo for the moment there is no facility to download packages, so better kill it here
		throw new Exception('no_access', false);

		Txt::load('ManageSettings');
		require_once(SUBSDIR . '/Package.subs.php');
		$fileFunc = FileFunctions::instance();

		// Clearly we need to know what to request.
		if (!isset($this->_req->query->did))
		{
			throw new Exception('no_access', false);
		}

		// Some lovely context.
		$context['download_id'] = $this->_req->query->did;
		$context['sub_template'] = 'download_language';
		$context['menu_data_' . $context['admin_menu_id']]['current_subsection'] = 'add';

		// Can we actually do the installation - and do they want to?
		if (!empty($this->_req->post->do_install) && !empty($this->_req->post->copy_file))
		{
			checkSession('get');
			validateToken('admin-dlang');

			$chmod_files = array();
			$install_files = array();

			// Check writable status.
			foreach ($this->_req->post->copy_file as $file)
			{
				// Check it's not very bad.
				if (strpos($file, '..') !== false || (strpos($file, 'themes') !== 0 && !preg_match('~agreement\.[A-Za-z-_0-9]+\.txt$~', $file)))
				{
					throw new Exception($txt['languages_download_illegal_paths']);
				}

				$chmod_files[] = BOARDDIR . '/' . $file;
				$install_files[] = $file;
			}

			// Call this in case we have work to do.
			$file_status = create_chmod_control($chmod_files);
			$files_left = $file_status['files']['notwritable'];

			// Something not writable?
			if (!empty($files_left))
			{
				$context['error_message'] = $txt['languages_download_not_chmod'];
			}
			// Otherwise, go go go!
			elseif (!empty($install_files))
			{
				// @todo retrieve the language pack per naming pattern from our sites
				read_tgz_file('http://download.elkarte.net/fetch_language.php?version=' . urlencode(strtr(FORUM_VERSION, array('ElkArte ' => ''))) . ';fetch=' . urlencode($this->_req->query->did), BOARDDIR, false, true, $install_files);

				// Make sure the files aren't stuck in the cache.
				package_flush_cache();
				$context['install_complete'] = sprintf($txt['languages_download_complete_desc'], getUrl('admin', ['action' => 'admin', 'area' => 'languages']));

				return;
			}
		}

		// @todo Open up the old china.
		$archive_content = read_tgz_file('http://download.elkarte.net/fetch_language.php?version=' . urlencode(strtr(FORUM_VERSION, array('ElkArte ' => ''))) . ';fetch=' . urlencode($this->_req->query->did), null);

		if (empty($archive_content))
		{
			throw new Exception($txt['add_language_error_no_response']);
		}

		// Now for each of the files, let's do some *stuff*
		$context['files'] = array(
			'lang' => array(),
			'other' => array(),
		);
		$context['make_writable'] = array();
		foreach ($archive_content as $file)
		{
			$dirname = dirname($file['filename']);
			$filename = basename($file['filename']);
			$extension = substr($filename, strrpos($filename, '.') + 1);

			// Don't do anything with files we don't understand.
			if (!in_array($extension, array('php', 'jpg', 'gif', 'jpeg', 'png', 'txt')))
			{
				continue;
			}

			// Basic data.
			$context_data = array(
				'name' => $filename,
				'destination' => BOARDDIR . '/' . $file['filename'],
				'generaldest' => $file['filename'],
				'size' => $file['size'],
				// Does chmod status allow the copy?
				'writable' => false,
				// Should we suggest they copy this file?
				'default_copy' => true,
				// Does the file already exist, if so is it same or different?
				'exists' => false,
			);

			// Does the file exist, is it different and can we overwrite?
			if ($fileFunc->fileExists(BOARDDIR . '/' . $file['filename']))
			{
				if ($fileFunc->isWritable(BOARDDIR . '/' . $file['filename']))
				{
					$context_data['writable'] = true;
				}

				// Finally, do we actually think the content has changed?
				if ($file['size'] == filesize(BOARDDIR . '/' . $file['filename']) && $file['md5'] === md5_file(BOARDDIR . '/' . $file['filename']))
				{
					$context_data['exists'] = 'same';
					$context_data['default_copy'] = false;
				}
				// Attempt to discover newline character differences.
				elseif ($file['md5'] === md5(preg_replace("~[\r]?\n~", "\r\n", file_get_contents(BOARDDIR . '/' . $file['filename']))))
				{
					$context_data['exists'] = 'same';
					$context_data['default_copy'] = false;
				}
				else
				{
					$context_data['exists'] = 'different';
				}
			}
			// No overwrite?
			elseif ($fileFunc->isWritable(BOARDDIR . '/' . $dirname))
			{
				// Can we at least stick it in the directory...
				$context_data['writable'] = true;
			}

			// I love PHP files, that's why I'm a developer and not an artistic type spending my time drinking absinth and living a life of sin...
			if ($extension === 'php' && preg_match('~\w+\.\w+(?:-utf8)?\.php~', $filename))
			{
				$context_data += array(
					'version' => '??',
					'cur_version' => false,
					'version_compare' => 'newer',
				);

				list ($name,) = explode('.', $filename);

				// Let's get the new version, I like versions, they tell me that I'm up to date.
				if (preg_match('~\s*Version:\s+(.+?);\s*' . preg_quote($name, '~') . '~i', $file['preview'], $match) == 1)
				{
					$context_data['version'] = $match[1];
				}

				// Now does the old file exist - if so what is it's version?
				if ($fileFunc->fileExists(BOARDDIR . '/' . $file['filename']))
				{
					// OK - what is the current version?
					$fp = fopen(BOARDDIR . '/' . $file['filename'], 'rb');
					$header = fread($fp, 768);
					fclose($fp);

					// Find the version.
					if (preg_match('~(?://|/\*)\s*Version:\s+(.+?);\s*' . preg_quote($name, '~') . '(?:[\s]{2}|\*/)~i', $header, $match) == 1)
					{
						$context_data['cur_version'] = $match[1];

						// How does this compare?
						if ($context_data['cur_version'] === $context_data['version'])
						{
							$context_data['version_compare'] = 'same';
						}
						elseif ($context_data['cur_version'] > $context_data['version'])
						{
							$context_data['version_compare'] = 'older';
						}

						// Don't recommend copying if the version is the same.
						if ($context_data['version_compare'] != 'newer')
						{
							$context_data['default_copy'] = false;
						}
					}
				}

				// Add the context data to the main set.
				$context['files']['lang'][] = $context_data;
			}
			else
			{
				// If we think it's a theme thing, work out what the theme is.
				if (strpos($dirname, 'themes') === 0 && preg_match('~themes[\\/]([^\\/]+)[\\/]~', $dirname, $match))
				{
					$theme_name = $match[1];
				}
				else
				{
					$theme_name = 'misc';
				}

				// Assume it's an image, could be an acceptance note etc but rare.
				$context['files']['images'][$theme_name][] = $context_data;
			}

			// Collect together all non-writable areas.
			if (!$context_data['writable'])
			{
				$context['make_writable'][] = $context_data['destination'];
			}
		}

		// So, I'm a perfectionist - let's get the theme names.
		$indexes = array();
		foreach ($context['files']['images'] as $k => $dummy)
		{
			$indexes[] = $k;
		}

		$context['theme_names'] = array();
		if (!empty($indexes))
		{
			require_once(SUBSDIR . '/Themes.subs.php');
			$value_data = array(
				'query' => array(),
				'params' => array(),
			);

			foreach ($indexes as $k => $index)
			{
				$value_data['query'][] = 'value LIKE {string:value_' . $k . '}';
				$value_data['params']['value_' . $k] = '%' . $index;
			}

			$themes = validateThemeName($indexes, $value_data);

			// Now we have the id_theme we can get the pretty description.
			if (!empty($themes))
			{
				$context['theme_names'] = getBasicThemeInfos($themes);
			}
		}

		// Before we go to far can we make anything writable, eh, eh?
		if (!empty($context['make_writable']))
		{
			// What is left to be made writable?
			$file_status = create_chmod_control($context['make_writable']);
			$context['still_not_writable'] = $file_status['files']['notwritable'];

			// Mark those which are now writable as such.
			foreach ($context['files'] as $type => $data)
			{
				if ($type === 'lang')
				{
					foreach ($data as $k => $file)
					{
						if (!$file['writable'] && !in_array($file['destination'], $context['still_not_writable']))
						{
							$context['files'][$type][$k]['writable'] = true;
						}
					}
				}
				else
				{
					foreach ($data as $theme => $files)
					{
						foreach ($files as $k => $file)
						{
							if (!$file['writable'] && !in_array($file['destination'], $context['still_not_writable']))
							{
								$context['files'][$type][$theme][$k]['writable'] = true;
							}
						}
					}
				}
			}

			// Are we going to need more language stuff?
			if (!empty($context['still_not_writable']))
			{
				Txt::load('Packages');
			}
		}

		// This is the list for the main files.
		$listOptions = array(
			'id' => 'lang_main_files_list',
			'title' => $txt['languages_download_main_files'],
			'get_items' => array(
				'function' => function () {
					global $context;

					return $context['files']['lang'];
				},
			),
			'columns' => array(
				'name' => array(
					'header' => array(
						'value' => $txt['languages_download_filename'],
					),
					'data' => array(
						'function' => function ($rowData) {
							global $txt;

							return '<strong>' . $rowData['name'] . '</strong><br /><span class="smalltext">' . $txt['languages_download_dest'] . ': ' . $rowData['destination'] . '</span>' . ($rowData['version_compare'] == 'older' ? '<br />' . $txt['languages_download_older'] : '');
						},
					),
				),
				'writable' => array(
					'header' => array(
						'value' => $txt['languages_download_writable'],
					),
					'data' => array(
						'function' => function ($rowData) {
							global $txt;

							return '<span class="' . ($rowData['writable'] ? 'success' : 'error') . ';">' . ($rowData['writable'] ? $txt['yes'] : $txt['no']) . '</span>';
						},
					),
				),
				'version' => array(
					'header' => array(
						'value' => $txt['languages_download_version'],
					),
					'data' => array(
						'function' => function ($rowData) {
							return '<span class="' . ($rowData['version_compare'] == 'older' ? 'error' : ($rowData['version_compare'] == 'same' ? 'softalert' : 'success')) . ';">' . $rowData['version'] . '</span>';
						},
					),
				),
				'exists' => array(
					'header' => array(
						'value' => $txt['languages_download_exists'],
					),
					'data' => array(
						'function' => function ($rowData) {
							global $txt;

							return $rowData['exists'] ? ($rowData['exists'] == 'same' ? $txt['languages_download_exists_same'] : $txt['languages_download_exists_different']) : $txt['no'];
						},
					),
				),
				'copy' => array(
					'header' => array(
						'value' => $txt['languages_download_copy'],
						'class' => 'centertext',
					),
					'data' => array(
						'function' => function ($rowData) {
							return '<input type="checkbox" name="copy_file[]" value="' . $rowData['generaldest'] . '" ' . ($rowData['default_copy'] ? 'checked="checked"' : '') . ' class="input_check" />';
						},
						'style' => 'width: 4%;',
						'class' => 'centertext',
					),
				),
			),
		);

		// Kill the cache, as it is now invalid..
		$cache = Cache::instance();
		$cache->put('known_languages', null, $cache->maxLevel(1) ? 86400 : 3600);

		createList($listOptions);

		createToken('admin-dlang');
	}

	/**
	 * Edit a particular set of language entries.
	 */
	public function action_editlang()
	{
		global $context, $txt;

		$base_lang_dir = SOURCEDIR . '/ElkArte/Languages';
		require_once(SUBSDIR . '/Language.subs.php');
		Txt::load('ManageSettings');

		// Select the languages tab.
		$context['menu_data_' . $context['admin_menu_id']]['current_subsection'] = 'edit';
		$context['page_title'] = $txt['edit_languages'];
		$context['sub_template'] = 'modify_language_entries';

		$context['lang_id'] = $this->_req->query->lid;
		$file_id = !empty($this->_req->post->tfid) ? $this->_req->post->tfid : '';

		// Clean the ID - just in case.
		preg_match('~([A-Za-z0-9_-]+)~', $context['lang_id'], $matches);
		$context['lang_id'] = $matches[1];
		$matches = '';
		preg_match('~([A-Za-z0-9_-]+)~', $file_id, $matches);
		$file_id = ucfirst($matches[1] ?? '');

		// This will be where we look
		$lang_dirs = glob($base_lang_dir . '/*', GLOB_ONLYDIR);
		$images_dirs = array();

		$current_file = $file_id ? $base_lang_dir . '/' . $file_id . '/' . ucfirst($context['lang_id']) . '.php' : '';

		// Now for every theme get all the files and stick them in context!
		$context['possible_files'] =  array_map(function($file) use ($file_id, $txt) {
			return [
				'id' => basename($file, '.php'),
				'name' => $txt['lang_file_desc_' . basename($file)] ?? basename($file),
				'path' => $file,
				'selected' => $file_id == basename($file),
			];
		}, $lang_dirs);

		if ($context['lang_id'] != 'english')
		{
			$possiblePackage = findPossiblePackages($context['lang_id']);
			if ($possiblePackage !== false)
			{
				$context['langpack_uninstall_link'] = getUrl('admin', ['action' => 'admin', 'area' => 'packages', 'sa' => 'uninstall', 'package' => $possiblePackage[1], 'pid' => $possiblePackage[0]]);
			}
		}

		// Quickly load index language entries.
		$mtxt = [];
		$new_lang = new LangLoader($context['lang_id'], $mtxt, database());
		$new_lang->load('Index', true);

		// Setup the primary settings context.
		$context['primary_settings'] = array(
			'name' => Util::ucwords(strtr($context['lang_id'], array('_' => ' ', '-utf8' => ''))),
			'locale' => $mtxt['lang_locale'],
			'dictionary' => $mtxt['lang_dictionary'],
			'spelling' => $mtxt['lang_spelling'],
			'rtl' => $mtxt['lang_rtl'],
		);

		// Quickly load index language entries.
		$edit_lang = new LangEditor($context['lang_id'], database());
		$edit_lang->load($file_id);

		$context['file_entries'] = $edit_lang->getForEditing();

		// Are we saving?
		$save_strings = array();
		if (isset($this->_req->post->save_entries) && !empty($this->_req->post->entry))
		{
			checkSession();
			validateToken('admin-mlang');

			$edit_lang->save($file_id, $this->_req->post->entry);

			redirectexit('action=admin;area=languages;sa=editlang;lid=' . $context['lang_id']);
		}

		createToken('admin-mlang');
	}

	/**
	 * Edit language related settings.
	 *
	 * - Accessed by ?action=admin;area=languages;sa=settings
	 * - This method handles the display, allows to edit, and saves the result
	 * for the _languageSettings form.
	 *
	 * @event integrate_save_language_settings
	 */
	public function action_languageSettings_display()
	{
		global $context, $txt;

		// Initialize the form
		$settingsForm = new SettingsForm(SettingsForm::FILE_ADAPTER);
		$fileFunc = FileFunctions::instance();

		// Initialize it with our settings
		$settingsForm->setConfigVars($this->_settings());

		// Warn the user if the backup of Settings.php failed.
		$settings_not_writable = !$fileFunc->isWritable(BOARDDIR . '/Settings.php');
		$settings_backup_fail = !$fileFunc->isWritable(BOARDDIR . '/Settings_bak.php') || !@copy(BOARDDIR . '/Settings.php', BOARDDIR . '/Settings_bak.php');

		// Saving settings?
		if (isset($this->_req->query->save))
		{
			checkSession();

			call_integration_hook('integrate_save_language_settings');

			$settingsForm->setConfigValues((array) $this->_req->post);
			$settingsForm->save();
			redirectexit('action=admin;area=languages;sa=settings');
		}

		// Setup the template stuff.
		$context['post_url'] = getUrl('admin', ['action' => 'admin', 'area' => 'languages', 'sa' => 'settings', 'save']);
		$context['settings_title'] = $txt['language_settings'];
		$context['save_disabled'] = $settings_not_writable;

		if ($settings_not_writable)
		{
			$context['error_type'] = 'notice';
			$context['settings_message'] = $txt['settings_not_writable'];
		}
		elseif ($settings_backup_fail)
		{
			$context['error_type'] = 'notice';
			$context['settings_message'] = $txt['admin_backup_fail'];
		}

		// Fill the config array in contextual data for the template.
		$settingsForm->prepare();
	}

	/**
	 * Load up all language settings
	 *
	 * @event integrate_modify_language_settings Use to add new config options
	 */
	private function _settings()
	{
		global $txt;

		// Warn the user if the backup of Settings.php failed.
		$settings_not_writable = !is_writable(BOARDDIR . '/Settings.php');

		$config_vars = array(
			'language' => array('language', $txt['default_language'], 'file', 'select', array(), null, 'disabled' => $settings_not_writable),
			array('userLanguage', $txt['userLanguage'], 'db', 'check', null, 'userLanguage'),
		);

		call_integration_hook('integrate_modify_language_settings', array(&$config_vars));

		// Get our languages. No cache.
		$languages = getLanguages(false);
		foreach ($languages as $lang)
		{
			$config_vars['language'][4][] = array($lang['filename'], strtr($lang['name'], array('-utf8' => ' (UTF-8)')));
		}

		return $config_vars;
	}

	/**
	 * Return the form settings for use in admin search
	 */
	public function settings_search()
	{
		return $this->_settings();
	}
}
