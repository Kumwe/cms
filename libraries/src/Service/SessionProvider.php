<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Service;

use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\Dispatcher;
use Joomla\Session\Session;
use Joomla\Session\SessionInterface;
use Joomla\Session\Storage\NativeStorage as SessionNativeStorage;
use Joomla\Session\StorageInterface;
use Joomla\Session\Handler\DatabaseHandler as SessionDatabaseHandler;
use Joomla\Session\HandlerInterface;
use Kumwe\CMS\Session\MetadataManager;

/**
 * Session service provider
 */
class SessionProvider implements ServiceProviderInterface
{
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container): void
	{
		$container->alias(SessionDatabaseHandler::class, HandlerInterface::class)
			->share(HandlerInterface::class, [$this, 'getSessionDatabaseHandlerClassService'], true);

		$container->alias(SessionNativeStorage::class, StorageInterface::class)
			->share(StorageInterface::class, [$this, 'getSessionNativeStorageClassService'], true);

		$container->alias(Session::class, SessionInterface::class)
			->share(SessionInterface::class, [$this, 'getSessionClassService'], true);

		$container->alias(MetadataManager::class, MetadataManager::class)
			->share(MetadataManager::class, [$this, 'getMetadataManagerClassService'], true);
	}

	/**
	 * Get the session metadata manager service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  MetadataManager
	 */
	public function getMetadataManagerClassService(Container $container): MetadataManager
	{
		return new MetadataManager(
			$container->get(DatabaseInterface::class)
		);
	}

	/**
	 * Get the `admin.session` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  SessionInterface
	 * @throws \Exception
	 */
	public function getSessionClassService(Container $container): SessionInterface
	{
		/** @var \Joomla\Session\Session; $session */
		$session = new Session($container->get(SessionNativeStorage::class), $container->get(Dispatcher::class));

		// Start session if not already started
		if (empty($session->getId()))
		{
			$session->start();
		}

		return $session;
	}

	/**
	 * Get the Session Database Handler service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  HandlerInterface
	 */
	public function getSessionDatabaseHandlerClassService(Container $container): HandlerInterface
	{
		return new SessionDatabaseHandler($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `admin.session` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  StorageInterface
	 */
	public function getSessionNativeStorageClassService(Container $container): StorageInterface
	{
		/** @var \Joomla\Registry\Registry $config */
		$config = $container->get('config');

		// Generate a session name. (not secure enough)
		$name = md5('kumweAdmin');

		// Calculate the session lifetime.
		$lifetime = $config->get('lifetime') ? $config->get('lifetime') * 60 : 900;

		// Initialize the options for the Session object.
		$options = [
			'name'   => $name,
			'expire' => $lifetime
		];

		return new SessionNativeStorage($container->get(SessionDatabaseHandler::class), $options);
	}
}
