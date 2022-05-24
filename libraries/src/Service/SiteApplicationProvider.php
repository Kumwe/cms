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

use Joomla\Application\AbstractWebApplication;
use Joomla\Application\Controller\ContainerControllerResolver;
use Joomla\Application\Controller\ControllerResolverInterface;
use Joomla\Application\Web\WebClient;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

use Kumwe\CMS\Controller\WrongCmsController;
use Kumwe\CMS\Controller\PageController;
use Kumwe\CMS\Model\PageModel;
use Kumwe\CMS\Utilities\StringHelper;
use Kumwe\CMS\View\Site\PageHtmlView;
use Kumwe\CMS\Application\SiteApplication;

use Joomla\Input\Input;
use Joomla\Router\Route;
use Joomla\Router\Router;
use Joomla\Router\RouterInterface;
use Psr\Log\LoggerInterface;

/**
 * Site Application service provider
 * source: https://github.com/joomla/framework.joomla.org/blob/master/src/Service/ApplicationProvider.php
 */
class SiteApplicationProvider implements ServiceProviderInterface
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
		/*
		 * Application Classes
		 */

		// This service cannot be protected as it is decorated when the debug bar is available
		$container->alias(SiteApplication::class, AbstractWebApplication::class)
			->share(AbstractWebApplication::class, [$this, 'getSiteApplicationClassService']);

		/*
		 * Application Helpers and Dependencies
		 */

		// This service cannot be protected as it is decorated when the debug bar is available
		$container->alias(ContainerControllerResolver::class, ControllerResolverInterface::class)
			->share(ControllerResolverInterface::class, [$this, 'getControllerResolverService']);

		$container->share(WebClient::class, [$this, 'getWebClientService'], true);

		// This service cannot be protected as it is decorated when the debug bar is available
		$container->alias(RouterInterface::class, 'application.router')
			->alias(Router::class, 'application.router')
			->share('application.router', [$this, 'getApplicationRouterService']);

		/*
		 * MVC Layer
		 */

		// Controllers
		$container->alias(PageController::class, 'controller.page')
			->share('controller.page', [$this, 'getControllerPageService'], true);

		$container->alias(WrongCmsController::class, 'controller.wrong.cms')
			->share('controller.wrong.cms', [$this, 'getControllerWrongCmsService'], true);

		// Models
		$container->alias(PageModel::class, 'model.page')
			->share('model.page', [$this, 'getModelPageService'], true);

		// Views
		$container->alias(PageHtmlView::class, 'view.page.html')
			->share('view.page.html', [$this, 'getViewPageHtmlService'], true);
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

		/*
		 * CMS Admin Panels
		 */
		$router->get(
			'/wp-admin',
			WrongCmsController::class
		);

		$router->get(
			'/wp-admin/*',
			WrongCmsController::class
		);

		$router->get(
			'wp-login.php',
			WrongCmsController::class
		);

		/*
		 * Web routes
		 */
		$router->addRoute(new Route(['GET', 'HEAD'], '/', PageController::class));

		// dynamic pages
		$pages = '/:root';
		$router->get(
			$pages,
			PageController::class
		);
		// set a mad depth TODO: we should limit the menu depth to 6 or something
		$depth = range(1,20);
		foreach ($depth as $page)
		{
			$page = StringHelper::numbers($page);
			$pages .= "/:$page";
			$router->get(
				$pages,
				PageController::class
			);
		}

		return $router;
	}

	/**
	 * Get the `controller.page` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PageController
	 */
	public function getControllerPageService(Container $container): PageController
	{
		return new PageController(
			$container->get(PageHtmlView::class),
			$container->get(Input::class),
			$container->get(SiteApplication::class)
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
			$container->get(SiteApplication::class)
		);
	}

	/**
	 * Get the `model.page` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PageModel
	 */
	public function getModelPageService(Container $container): PageModel
	{
		return new PageModel($container->get(DatabaseInterface::class));
	}

	/**
	 * Get the WebApplication class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  SiteApplication
	 */
	public function getSiteApplicationClassService(Container $container): SiteApplication
	{
		$application = new SiteApplication(
			$container->get(ControllerResolverInterface::class),
			$container->get(RouterInterface::class),
			$container->get(Input::class),
			$container->get('config'),
			$container->get(WebClient::class)
		);

		$application->httpVersion = '2';

		// Inject extra services
		$application->setDispatcher($container->get(DispatcherInterface::class));
		$application->setLogger($container->get(LoggerInterface::class));

		return $application;
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
	 * Get the `view.page.html` service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  PageHtmlView
	 */
	public function getViewPageHtmlService(Container $container): PageHtmlView
	{
		$view = new PageHtmlView(
			$container->get('model.page'),
			$container->get('renderer')
		);

		$view->setLayout('page.twig');

		return $view;
	}

	/**
	 * Get the web client service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  WebClient
	 */
	public function getWebClientService(Container $container): WebClient
	{
		/** @var Input $input */
		$input          = $container->get(Input::class);
		$userAgent      = $input->server->getString('HTTP_USER_AGENT', '');
		$acceptEncoding = $input->server->getString('HTTP_ACCEPT_ENCODING', '');
		$acceptLanguage = $input->server->getString('HTTP_ACCEPT_LANGUAGE', '');

		return new WebClient($userAgent, $acceptEncoding, $acceptLanguage);
	}
}
