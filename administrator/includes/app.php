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

// Check for presence of vendor dependencies not included in the git repository
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/includes/app.php#L26
if (!file_exists(LPATH_LIBRARIES . '/vendor/autoload.php'))
{
	echo file_get_contents(LPATH_ROOT . '/templates/system/build_incomplete.html');

	exit;
}

// Load configuration (or install)
// source: https://github.com/joomla/joomla-cms/blob/4.1-dev/includes/app.php#L34
require_once LPATH_BASE . '/includes/framework.php';

// Wrap in a try/catch so we can display an error if need be
try
{
	$container = (new Joomla\DI\Container)
		->registerServiceProvider(new Kumwe\CMS\Service\ConfigurationProvider(LPATH_CONFIGURATION . '/config.php'))
		->registerServiceProvider(new Kumwe\CMS\Service\SessionProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\UserProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\InputProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\AdminApplicationProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\AdminRouterProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\AdminMVCProvider)
		->registerServiceProvider(new Joomla\Database\Service\DatabaseProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\EventProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\HttpProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\LoggingProvider)
		->registerServiceProvider(new Joomla\Preload\Service\PreloadProvider)
		->registerServiceProvider(new Kumwe\CMS\Service\AdminTemplatingProvider);

	// Alias the web application to Kumwe's base application class as this is the primary application for the environment
	$container->alias(Joomla\Application\AbstractApplication::class, Joomla\Application\AbstractWebApplication::class);

	// Alias the web logger to the PSR-3 interface as this is the primary logger for the environment
	$container->alias(Monolog\Logger::class, 'monolog.logger.application.web')
		->alias(Psr\Log\LoggerInterface::class, 'monolog.logger.application.web');
}
catch (\Throwable $e)
{
	error_log($e);

	header('HTTP/1.1 500 Internal Server Error', null, 500);
	echo '<html><head><title>Container Initialization Error</title></head><body><h1>Container Initialization Error</h1><p>An error occurred while creating the DI container: ' . $e->getMessage() . '</p></body></html>';

	exit(1);
}

// Execute the application
// source: https://github.com/joomla/framework.joomla.org/blob/master/www/index.php#L85
try
{
	$app = $container->get(Joomla\Application\AbstractApplication::class);
	// Set the application as global app
	\Kumwe\CMS\Factory::$application = $app;
	// Execute the application.
	$app->execute();
}
catch (\Throwable $e)
{
	error_log($e);

	if (!headers_sent())
	{
		header('HTTP/1.1 500 Internal Server Error', null, 500);
		header('Content-Type: text/html; charset=utf-8');
	}

	echo '<html><head><title>Application Error</title></head><body><h1>Application Error</h1><p>An error occurred while executing the application: ' . $e->getMessage() . '</p></body></html>';

	exit(1);
}
// I am just playing around... ((ewɘ))yn purring