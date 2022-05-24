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

use Joomla\Application\Controller\ContainerControllerResolver;
use Joomla\Application\Controller\ControllerResolverInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

use Kumwe\CMS\Controller\DashboardController;
use Kumwe\CMS\Controller\ItemsController;
use Kumwe\CMS\Controller\ItemController;
use Kumwe\CMS\Controller\LoginController;
use Kumwe\CMS\Controller\MenuController;
use Kumwe\CMS\Controller\MenusController;
use Kumwe\CMS\Controller\UserController;
use Kumwe\CMS\Controller\UsersController;
use Kumwe\CMS\Controller\UserGroupController;
use Kumwe\CMS\Controller\UsergroupsController;
use Kumwe\CMS\Controller\WrongCmsController;
use Kumwe\CMS\Model\DashboardModel;
use Kumwe\CMS\Model\ItemsModel;
use Kumwe\CMS\Model\ItemModel;
use Kumwe\CMS\Model\MenusModel;
use Kumwe\CMS\Model\MenuModel;
use Kumwe\CMS\Model\UserModel;
use Kumwe\CMS\Model\UsersModel;
use Kumwe\CMS\Model\UsergroupModel;
use Kumwe\CMS\Model\UsergroupsModel;
use Kumwe\CMS\User\UserFactoryInterface;
use Kumwe\CMS\View\Admin\DashboardHtmlView;
use Kumwe\CMS\View\Admin\ItemsHtmlView;
use Kumwe\CMS\View\Admin\ItemHtmlView;
use Kumwe\CMS\View\Admin\MenuHtmlView;
use Kumwe\CMS\View\Admin\MenusHtmlView;
use Kumwe\CMS\View\Admin\UserHtmlView;
use Kumwe\CMS\View\Admin\UsersHtmlView;
use Kumwe\CMS\View\Admin\UsergroupHtmlView;
use Kumwe\CMS\View\Admin\UsergroupsHtmlView;
use Kumwe\CMS\Application\AdminApplication;

use Joomla\Input\Input;
use Joomla\Renderer\RendererInterface;

/**
 * Model View Controller service provider
 */
class AdminMVCProvider implements ServiceProviderInterface
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
		$container->alias(ContainerControllerResolver::class, ControllerResolverInterface::class)
			->share(ControllerResolverInterface::class, [$this, 'getControllerResolverService']);

		// Controllers
		$container->alias(DashboardController::class, 'controller.dashboard')
			->share('controller.dashboard', [$this, 'getControllerDashboardService'], true);

		$container->alias(UsersController::class, 'controller.users')
			->share('controller.users', [$this, 'getControllerUsersService'], true);

		$container->alias(UserController::class, 'controller.user')
			->share('controller.user', [$this, 'getControllerUserService'], true);

		$container->alias(UsergroupsController::class, 'controller.usergroups')
			->share('controller.usergroups', [$this, 'getControllerUsergroupsService'], true);

		$container->alias(UsergroupController::class, 'controller.usergroup')
			->share('controller.usergroup', [$this, 'getControllerUsergroupService'], true);

		$container->alias(MenusController::class, 'controller.menus')
			->share('controller.menus', [$this, 'getControllerMenusService'], true);

		$container->alias(MenuController::class, 'controller.menu')
			->share('controller.menu', [$this, 'getControllerMenuService'], true);

		$container->alias(ItemsController::class, 'controller.items')
			->share('controller.items', [$this, 'getControllerItemsService'], true);

		$container->alias(ItemController::class, 'controller.item')
			->share('controller.item', [$this, 'getControllerItemService'], true);

		$container->alias(LoginController::class, 'controller.login')
			->share('controller.login', [$this, 'getControllerLoginService'], true);

		$container->alias(WrongCmsController::class, 'controller.wrong.cms')
			->share('controller.wrong.cms', [$this, 'getControllerWrongCmsService'], true);

		// Models
		$container->alias(DashboardModel::class, 'model.dashboard')
			->share('model.dashboard', [$this, 'getModelDashboardService'], true);

		$container->alias(UsersModel::class, 'model.users')
			->share('model.users', [$this, 'getModelUsersService'], true);

		$container->alias(UserModel::class, 'model.user')
			->share('model.user', [$this, 'getModelUserService'], true);

		$container->alias(UsergroupsModel::class, 'model.usergroups')
			->share('model.usergroups', [$this, 'getModelUsergroupsService'], true);

		$container->alias(UsergroupModel::class, 'model.usergroup')
			->share('model.usergroup', [$this, 'getModelUsergroupService'], true);

		$container->alias(MenusModel::class, 'model.menus')
			->share('model.menus', [$this, 'getModelMenusService'], true);

		$container->alias(MenuModel::class, 'model.menu')
			->share('model.menu', [$this, 'getModelMenuService'], true);

		$container->alias(ItemsModel::class, 'model.items')
			->share('model.items', [$this, 'getModelItemsService'], true);

		$container->alias(ItemModel::class, 'model.item')
			->share('model.item', [$this, 'getModelItemService'], true);

		// Views
		$container->alias(DashboardHtmlView::class, 'view.dashboard.html')
			->share('view.dashboard.html', [$this, 'getViewDashboardHtmlService'], true);

		$container->alias(UsersHtmlView::class, 'view.users.html')
			->share('view.users.html', [$this, 'getViewUsersHtmlService'], true);

		$container->alias(UserHtmlView::class, 'view.user.html')
			->share('view.user.html', [$this, 'getViewUserHtmlService'], true);

		$container->alias(UsergroupsHtmlView::class, 'view.usergroups.html')
			->share('view.usergroups.html', [$this, 'getViewUsergroupsHtmlService'], true);

		$container->alias(UsergroupHtmlView::class, 'view.usergroup.html')
			->share('view.usergroup.html', [$this, 'getViewUsergroupHtmlService'], true);

		$container->alias(MenusHtmlView::class, 'view.menus.html')
			->share('view.menus.html', [$this, 'getViewMenusHtmlService'], true);

		$container->alias(MenuHtmlView::class, 'view.menu.html')
			->share('view.menu.html', [$this, 'getViewMenuHtmlService'], true);

		$container->alias(ItemsHtmlView::class, 'view.items.html')
			->share('view.items.html', [$this, 'getViewItemsHtmlService'], true);

		$container->alias(ItemHtmlView::class, 'view.item.html')
			->share('view.item.html', [$this, 'getViewItemHtmlService'], true);
	}

	/**
	 * Get the controller resolver service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ControllerResolverInterface
	 */
	public function getControllerResolverService(Container $container): ControllerResolverInterface
	{
		return new ContainerControllerResolver($container);
	}

	/**
	 * Get the `controller.login` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  LoginController
	 */
	public function getControllerLoginService(Container $container): LoginController
	{
		return new LoginController(
			$container->get(DashboardHtmlView::class),
			$container->get(RendererInterface::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class)
		);
	}

	/**
	 * Get the `controller.wrong.cms` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  WrongCmsController
	 */
	public function getControllerWrongCmsService(Container $container): WrongCmsController
	{
		return new WrongCmsController(
			$container->get(Input::class),
			$container->get(AdminApplication::class)
		);
	}

	/**
	 * Get the `controller.dashboard` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  DashboardController
	 */
	public function getControllerDashboardService(Container $container): DashboardController
	{
		return new DashboardController(
			$container->get(DashboardHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class)
		);
	}

	/**
	 * Get the `model.dashboard` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  DashboardModel
	 */
	public function getModelDashboardService(Container $container): DashboardModel
	{
		return new DashboardModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.dashboard.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  DashboardHtmlView
	 */
	public function getViewDashboardHtmlService(Container $container): DashboardHtmlView
	{
		return new DashboardHtmlView(
			$container->get('model.dashboard'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.users` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsersController
	 */
	public function getControllerUsersService(Container $container): UsersController
	{
		return new UsersController(
			$container->get(UsersHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.users` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsersModel
	 */
	public function getModelUsersService(Container $container): UsersModel
	{
		return new UsersModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.users.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsersHtmlView
	 */
	public function getViewUsersHtmlService(Container $container): UsersHtmlView
	{
		return new UsersHtmlView(
			$container->get('model.users'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.user` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UserController
	 */
	public function getControllerUserService(Container $container): UserController
	{
		return new UserController(
			$container->get(UserModel::class),
			$container->get(UserHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.user` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UserModel
	 */
	public function getModelUserService(Container $container): UserModel
	{
		return new UserModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.user.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UserHtmlView
	 */
	public function getViewUserHtmlService(Container $container): UserHtmlView
	{
		return new UserHtmlView(
			$container->get('model.user'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.usergroups` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsergroupsController
	 */
	public function getControllerUsergroupsService(Container $container): UsergroupsController
	{
		return new UsergroupsController(
			$container->get(UsergroupsHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.usergroups` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsergroupsModel
	 */
	public function getModelUsergroupsService(Container $container): UsergroupsModel
	{
		return new UsergroupsModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.usergroups.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsergroupsHtmlView
	 */
	public function getViewUsergroupsHtmlService(Container $container): UsergroupsHtmlView
	{
		return new UsergroupsHtmlView(
			$container->get('model.usergroups'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.usergroup` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsergroupController
	 */
	public function getControllerUsergroupService(Container $container): UsergroupController
	{
		return new UsergroupController(
			$container->get(UsergroupModel::class),
			$container->get(UsergroupHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.usergroup` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsergroupModel
	 */
	public function getModelUsergroupService(Container $container): UsergroupModel
	{
		return new UsergroupModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.usergroup.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  UsergroupHtmlView
	 */
	public function getViewUsergroupHtmlService(Container $container): UsergroupHtmlView
	{
		return new UsergroupHtmlView(
			$container->get('model.usergroup'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.menus` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  MenusController
	 */
	public function getControllerMenusService(Container $container): MenusController
	{
		return new MenusController(
			$container->get(MenusHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.menus` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  MenusModel
	 */
	public function getModelMenusService(Container $container): MenusModel
	{
		return new MenusModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.menus.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  MenusHtmlView
	 */
	public function getViewMenusHtmlService(Container $container): MenusHtmlView
	{
		return new MenusHtmlView(
			$container->get('model.menus'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.menu` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  MenuController
	 */
	public function getControllerMenuService(Container $container): MenuController
	{
		return new MenuController(
			$container->get(MenuModel::class),
			$container->get(MenuHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.menu` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  MenuModel
	 */
	public function getModelMenuService(Container $container): MenuModel
	{
		return new MenuModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.menu.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  MenuHtmlView
	 */
	public function getViewMenuHtmlService(Container $container): MenuHtmlView
	{
		return new MenuHtmlView(
			$container->get('model.menu'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.items` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ItemsController
	 */
	public function getControllerItemsService(Container $container): ItemsController
	{
		return new ItemsController(
			$container->get(ItemsHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.items` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ItemsModel
	 */
	public function getModelItemsService(Container $container): ItemsModel
	{
		return new ItemsModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.items.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ItemsHtmlView
	 */
	public function getViewItemsHtmlService(Container $container): ItemsHtmlView
	{
		return new ItemsHtmlView(
			$container->get('model.items'),
			$container->get('renderer')
		);
	}

	/**
	 * Get the `controller.item` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ItemController
	 */
	public function getControllerItemService(Container $container): ItemController
	{
		return new ItemController(
			$container->get(ItemModel::class),
			$container->get(ItemHtmlView::class),
			$container->get(Input::class),
			$container->get(AdminApplication::class),
			$container->get(UserFactoryInterface::class)->getUser()
		);
	}

	/**
	 * Get the `model.item` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ItemModel
	 */
	public function getModelItemService(Container $container): ItemModel
	{
		return new ItemModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the `view.item.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  ItemHtmlView
	 */
	public function getViewItemHtmlService(Container $container): ItemHtmlView
	{
		return new ItemHtmlView(
			$container->get('model.item'),
			$container->get('renderer')
		);
	}
}
