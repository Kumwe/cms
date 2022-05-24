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

// Option to override defines from root folder
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/includes/app.php#L15
if (file_exists(dirname(__DIR__) . '/defines.php'))
{
	include_once dirname(__DIR__) . '/defines.php';
}

// Load the default defines
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/includes/app.php#L20
if (!defined('_LDEFINES'))
{
	define('LPATH_BASE', dirname(__DIR__));
	require_once LPATH_BASE . '/includes/defines.php';
}

// I have not yet had time to finish this part of the application (CMS)
echo file_get_contents(LPATH_ROOT . '/templates/system/install_notice.html');

exit;
