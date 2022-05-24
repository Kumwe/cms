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

// Set the platform root path as a constant if necessary.
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/libraries/bootstrap.php#L12
defined('LPATH_PLATFORM') or define('LPATH_PLATFORM', __DIR__);

// Detect the native operating system type.
$os = strtoupper(substr(PHP_OS, 0, 3));

defined('IS_WIN') or define('IS_WIN', ($os === 'WIN'));
defined('IS_UNIX') or define('IS_UNIX', (($os !== 'MAC') && ($os !== 'WIN')));

// Import the library loader if necessary.
if (!class_exists('LLoader'))
{
	require_once LPATH_PLATFORM . '/loader.php';

	// If JLoader still does not exist panic.
	if (!class_exists('LLoader'))
	{
		throw new RuntimeException('Kumwe Platform not loaded.');
	}
}

// Setup the autoloaders.
LLoader::setup();

// Create the Composer autoloader
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require LPATH_LIBRARIES . '/vendor/autoload.php';

// We need to pull our decorated class loader into memory before unregistering Composer's loader
class_exists('\\Kumwe\\CMS\\Autoload\\ClassLoader');

$loader->unregister();

// Decorate Composer autoloader
spl_autoload_register([new \Kumwe\CMS\Autoload\ClassLoader($loader), 'loadClass'], true, true);
