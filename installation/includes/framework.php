<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

defined('_LEXEC') or die;

// System includes
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/administrator/includes/framework.php#L14
require_once LPATH_LIBRARIES . '/bootstrap.php';

// Installation check, and check on removal of the installation directory.
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/administrator/includes/framework.php#L17
if (!file_exists(LPATH_CONFIGURATION . '/config.php')
	|| (filesize(LPATH_CONFIGURATION . '/config.php') < 10)
	|| (file_exists(LPATH_INSTALLATION . '/index.php')))
{
	if (file_exists(LPATH_INSTALLATION . '/index.php'))
	{
		header('Location: ../installation/index.php');

		exit;
	}
	else
	{
		echo 'No configuration file found and no installation code available. Exiting...';

		exit;
	}
}

// Pre-Load configuration. Don't remove the Output Buffering due to BOM issues.
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/administrator/includes/framework.php#L36
ob_start();
require_once LPATH_CONFIGURATION . '/config.php';
ob_end_clean();
