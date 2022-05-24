<?php
/**
 * @package    Kumwe CMS
 *
 * @created    14th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Service;

use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

use Kumwe\CMS\Controller\DashboardController;
use Kumwe\CMS\Controller\LoginController;
use Kumwe\CMS\Controller\ItemsController;
use Kumwe\CMS\Controller\ItemController;
use Kumwe\CMS\Controller\MenuController;
use Kumwe\CMS\Controller\MenusController;
use Kumwe\CMS\Controller\UserController;
use Kumwe\CMS\Controller\UsersController;
use Kumwe\CMS\Controller\UserGroupController;
use Kumwe\CMS\Controller\UsergroupsController;

use Joomla\Router\Router;
use Joomla\Router\RouterInterface;

/**
 * Application service provider
 * source: https://github.com/joomla/framework.joomla.org/blob/master/src/Service/ApplicationProvider.php
 */
class AdminRouterProvider implements ServiceProviderInterface
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
		// This service cannot be protected as it is decorated when the debug bar is available
		$container->alias(RouterInterface::class, 'application.router')
			->alias(Router::class, 'application.router')
			->share('application.router', [$this, 'getApplicationRouterService']);
	}

	/**
	 * Get the `application.router` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  RouterInterface
	 */
	public function getApplicationRouterService(Container $container): RouterInterface
	{
		$router = new Router;

		/**
		 * CMS Admin Panels
		 **/
		$router->all(
			'/index.php/dashboard',
			DashboardController::class
		);
		$router->get(
			'/index.php/users',
			UsersController::class
		);
		$router->all(
			'/index.php/user',
			UserController::class
		);
		$router->get(
			'/index.php/usergroups',
			UsergroupsController::class
		);
		$router->all(
			'/index.php/usergroup',
			UsergroupController::class
		);
		$router->get(
			'/index.php/menus',
			MenusController::class
		);
		$router->all(
			'/index.php/menu',
			MenuController::class
		);
		$router->get(
			'/index.php/items',
			ItemsController::class
		);
		$router->all(
			'/index.php/item',
			ItemController::class
		);
		$router->get(
			'/*',
			LoginController::class
		);

		return $router;
	}
}
