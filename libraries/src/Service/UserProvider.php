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

use Joomla\Authentication\AuthenticationStrategyInterface;
use Joomla\Authentication\Strategies\DatabaseStrategy;
use Joomla\Input\Input;
use Kumwe\CMS\Session\MetadataManager;
use Kumwe\CMS\User\UserFactory;
use Kumwe\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
* Service provider for the user dependency
*
* @since  1.0.0
* source: https://github.com/joomla/joomla-cms/blob/4.2-dev/libraries/src/Service/Provider/User.php
*/
class UserProvider implements ServiceProviderInterface
{
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	public function register(Container $container)
	{
		$container->alias('user.factory', UserFactoryInterface::class)
			->alias(UserFactory::class, UserFactoryInterface::class)
			->share(UserFactoryInterface::class, [$this, 'getUserFactoryService'], true);

		$container->alias(DatabaseStrategy::class, AuthenticationStrategyInterface::class)
			->share(AuthenticationStrategyInterface::class, [$this, 'getAuthenticationStrategyService'], true);
	}

	/**
	 * Get the UserFactoryInterface class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UserFactoryInterface
	 * @throws \Exception
	 */
	public function getUserFactoryService(Container $container): UserFactoryInterface
	{
		return new UserFactory(
			$container->get(DatabaseInterface::class),
			$container->get(AuthenticationStrategyInterface::class),
			$container->get(MetadataManager::class)
		);
	}

	/**
	 * Get the AuthenticationStrategyInterface class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  AuthenticationStrategyInterface
	 */
	public function getAuthenticationStrategyService(Container $container): AuthenticationStrategyInterface
	{
		return new DatabaseStrategy(
			$container->get(Input::class),
			$container->get(DatabaseInterface::class)
		);
	}
}
