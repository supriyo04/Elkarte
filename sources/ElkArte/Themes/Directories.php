<?php
/**
 * This class has the only scope to handle themes directories to
 * tell Templates and loadLanguageFiles where to go check for files.
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte\Themes;

class Directories
{
	/**
	 * Template directory's that we will be searching for the sheets
	 *
	 * @var array
	 */
	protected $dirs = [];

	/**
	 * Holds the file that are in the include list
	 *
	 * @var array
	 */
	protected $templates = [];

	/**
	 *
	 * @param array $settings
	 */
	public function __construct(array $settings)
	{
		$this->reloadDirectories($settings);
	}

	/**
	 * Add a template directory to the search stack
	 *
	 * @param string $dir
	 *
	 * @return $this
	 */
	public function addDirectory($dir)
	{
		$this->dirs[] = (string) $dir;

		return $this;
	}

	/**
	 * Returns if theme directory's have been loaded
	 *
	 * @return bool
	 */
	public function hasDirectories()
	{
		return !empty($this->dirs);
	}

	/**
	 * Returns the loaded directories
	 *
	 * @return string[]
	 */
	public function getDirectories()
	{
		return $this->dirs;
	}

	/**
	 * Reloads the directory stack/queue to ensure they are searched in the proper order
	 *
	 * @param array $settings
	 */
	public function reloadDirectories(array $settings)
	{
		$this->dirs = [];

		if (!empty($settings['theme_dir']))
		{
			$this->addDirectory($settings['theme_dir']);
		}

		// Based on theme (if there is one).
		if (!empty($settings['base_theme_dir']))
		{
			$this->addDirectory($settings['base_theme_dir']);
		}

		// Lastly the default theme.
		if ($settings['theme_dir'] !== $settings['default_theme_dir'])
		{
			$this->addDirectory($settings['default_theme_dir']);
		}
	}

	/**
	 * Load the template/language file using eval or require? (with eval we can show an
	 * error message!)
	 *
	 * What it does:
	 * - Loads the template or language file specified by filename.
	 * - Uses eval unless disableTemplateEval is enabled.
	 * - Outputs a parse error if the file did not exist or contained errors.
	 * - Attempts to detect the error and line, and show detailed information.
	 *
	 * @param string $filename
	 * @param bool $once = false, if true only includes the file once (like include_once)
	 */
	public function fileInclude($filename, $once = false)
	{
		/*
		 * I know this looks weird but this is used to include $txt files.
		 * If the parent doesn't declare them global, the scope will be
		 * local to this function. IOW, don't remove this line!
		 */
		global $txt;

		// Don't include the file more than once, if $once is true.
		if ($once && in_array($filename, $this->templates))
		{
			return;
		}
		// Add this file to the include list, whether $once is true or not.
		else
		{
			$this->templates[] = $filename;
		}

		// Load it if we find it
		$file_found = file_exists($filename);

		try
		{
			if ($once && $file_found)
			{
				require_once($filename);
			}
			elseif ($file_found)
			{
				require($filename);
			}
		}
		catch (Error $e)
		{
			$this->templateNotFound($e);
		}
	}
}