<?php
/**
 * @package    Kumwe CMS
 *
 * @created    3rd April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS;

\defined('LPATH_PLATFORM') or die;

use Joomla\Application\WebApplicationInterface;
use Joomla\Database\Service\DatabaseProvider;
use Joomla\DI\Container;
use Joomla\Registry\Registry;
use PHPMailer\PHPMailer\Exception as phpmailerException;

/**
 * Kumwe Platform Factory class.
 *
 * @since  1.0.0
 *
 * SOURCE: https://github.com/joomla/joomla-cms/blob/4.1-dev/libraries/src/Factory.php#L39
 */
abstract class Factory
{
	/**
	 * Global application object
	 *
	 * @var    WebApplicationInterface
	 * @since  1.0.0
	 */
	public static $application = null;

	/**
	 * Global configuration object
	 *
	 * @var         \LConfig
	 * @since       1.0.0
	 */
	public static $config = null;

	/**
	 * Global container object
	 *
	 * @var    Container
	 * @since  1.0.0
	 */
	public static $container = null;

	/**
	 * Global mailer object
	 *
	 * @var    Mail
	 * @since  1.0.0
	 */
	public static $mailer = null;

	/**
	 * Get the global application object. When the global application doesn't exist, an exception is thrown.
	 *
	 * @return  WebApplicationInterface object
	 *
	 * @since   1.0.0
	 * @throws  \Exception
	 */
	public static function getApplication() : WebApplicationInterface
	{
		if (!self::$application)
		{
			throw new \Exception('Failed to start application', 500);
		}

		return self::$application;
	}

	/**
	 * Get a container object
	 *
	 * Returns the global service container object, only creating it if it doesn't already exist.
	 *
	 * This method is only suggested for use in code whose responsibility is to create new services
	 * and needs to be able to resolve the dependencies, and should therefore only be used when the
	 * container is not accessible by other means.  Valid uses of this method include:
	 *
	 * - A static `getInstance()` method calling a factory service from the container,
	 *   see `Joomla\CMS\Toolbar\Toolbar::getInstance()` as an example
	 * - An application front controller loading and executing the Joomla application class,
	 *   see the `cli/joomla.php` file as an example
	 * - Retrieving optional constructor dependencies when not injected into a class during a transitional
	 *   period to retain backward compatibility, in this case a deprecation notice should also be emitted to
	 *   notify developers of changes needed in their code
	 *
	 * This method is not suggested for use as a one-for-one replacement of static calls, such as
	 * replacing calls to `Factory::getDbo()` with calls to `Factory::getContainer()->get('db')`, code
	 * should be refactored to support dependency injection instead of making this change.
	 *
	 * @return  Container
	 *
	 * @since   4.0.0
	 */
	public static function getContainer(): Container
	{
		if (!self::$container)
		{
			self::$container = self::createContainer();
		}

		return self::$container;
	}

	/**
	 * Get a mailer object.
	 *
	 * Returns the global {@link Mail} object, only creating it if it doesn't already exist.
	 *
	 * @return  Mail object
	 *
	 * @see     Mail
	 * @since   1.7.0
	 */
	public static function getMailer()
	{
		if (!self::$mailer)
		{
			self::$mailer = self::createMailer();
		}

		$copy = clone self::$mailer;

		return $copy;
	}

	/**
	 * Create a container object
	 *
	 * @return  Container
	 *
	 * @since   4.0.0
	 */
	protected static function createContainer(): Container
	{
		return (new Container)
			->registerServiceProvider(new Service\ConfigurationProvider(LPATH_CONFIGURATION . '/config.php'))
			->registerServiceProvider(new Service\SessionProvider)
			->registerServiceProvider(new Service\UserProvider)
			->registerServiceProvider(new Service\InputProvider)
			->registerServiceProvider(new DatabaseProvider)
			->registerServiceProvider(new Service\EventProvider)
			->registerServiceProvider(new Service\HttpProvider)
			->registerServiceProvider(new Service\LoggingProvider);
	}

	/**
	 * Get a configuration object
	 *
	 * Returns the global {@link \JConfig} object, only creating it if it doesn't already exist.
	 *
	 * @param   string  $file       The path to the configuration file
	 * @param   string  $type       The type of the configuration file
	 * @param   string  $namespace  The namespace of the configuration file
	 *
	 * @return  Registry
	 *
	 * @see         Registry
	 * @since       1.1.1
	 */
	public static function getConfig($file = null, $type = 'PHP', $namespace = '')
	{
		/**
		 * If there is an application object, fetch the configuration from there.
		 * Check it's not null because LanguagesModel can make it null and if it's null
		 * we would want to re-init it from configuration.php.
		 */
		if (self::$application && self::$application->getConfig() !== null)
		{
			return self::$application->getConfig();
		}

		if (!self::$config)
		{
			if ($file === null)
			{
				$file = JPATH_CONFIGURATION . '/config.php';
			}

			self::$config = self::createConfig($file, $type, $namespace);
		}

		return self::$config;
	}

	/**
	 * Create a configuration object
	 *
	 * @param   string  $file       The path to the configuration file.
	 * @param   string  $type       The type of the configuration file.
	 * @param   string  $namespace  The namespace of the configuration file.
	 *
	 * @return  Registry
	 *
	 * @see         Registry
	 * @since       1.0.0
	 */
	protected static function createConfig($file, $type = 'PHP', $namespace = '')
	{
		if (is_file($file))
		{
			include_once $file;
		}

		// Create the registry with a default namespace of config
		$registry = new Registry;

		// Sanitize the namespace.
		$namespace = ucfirst((string) preg_replace('/[^A-Z_]/i', '', $namespace));

		// Build the config name.
		$name = 'LConfig' . $namespace;

		// Handle the PHP configuration type.
		if ($type === 'PHP' && class_exists($name))
		{
			// Create the LConfig object
			$config = new $name;

			// Load the configuration values into the registry
			$registry->loadObject($config);
		}

		return $registry;
	}

	/**
	 * Create a mailer object
	 *
	 * @return  Mail object
	 *
	 * @see     Mail
	 * @since   1.0.0
	 */
	protected static function createMailer()
	{
//		$conf = self::getConfig();
//
//		$smtpauth = ($conf->get('smtpauth') == 0) ? null : 1;
//		$smtpuser = $conf->get('smtpuser');
//		$smtppass = $conf->get('smtppass');
//		$smtphost = $conf->get('smtphost');
//		$smtpsecure = $conf->get('smtpsecure');
//		$smtpport = $conf->get('smtpport');
//		$mailfrom = $conf->get('mailfrom');
//		$fromname = $conf->get('fromname');
//		$mailer = $conf->get('mailer');
//
//		// Create a Mail object
//		$mail = Mail::getInstance();
//
//		// Clean the email address
//		$mailfrom = MailHelper::cleanLine($mailfrom);
//
//		// Set default sender without Reply-to if the mailfrom is a valid address
//		if (MailHelper::isEmailAddress($mailfrom))
//		{
//			// Wrap in try/catch to catch phpmailerExceptions if it is throwing them
//			try
//			{
//				// Check for a false return value if exception throwing is disabled
//				if ($mail->setFrom($mailfrom, MailHelper::cleanLine($fromname), false) === false)
//				{
//					Log::add(__METHOD__ . '() could not set the sender data.', Log::WARNING, 'mail');
//				}
//			}
//			catch (phpmailerException $e)
//			{
//				Log::add(__METHOD__ . '() could not set the sender data.', Log::WARNING, 'mail');
//			}
//		}
//
//		// Default mailer is to use PHP's mail function
//		switch ($mailer)
//		{
//			case 'smtp':
//				$mail->useSmtp($smtpauth, $smtphost, $smtpuser, $smtppass, $smtpsecure, $smtpport);
//				break;
//
//			case 'sendmail':
//				$mail->isSendmail();
//				break;
//
//			default:
//				$mail->isMail();
//				break;
//		}
//
//		return $mail;
	}
}
