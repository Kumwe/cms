<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Controller;

use Joomla\Application\AbstractApplication;
use Joomla\Controller\AbstractController;
use Joomla\Input\Input;
use Joomla\Renderer\RendererInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Kumwe\CMS\View\Admin\DashboardHtmlView;

/**
 * Controller handling the requests
 *
 * @method         \Kumwe\CMS\Application\SiteApplication  getApplication()  Get the application object.
 * @property-read  \Kumwe\CMS\Application\SiteApplication  $app              Application object
 */
class LoginController extends AbstractController
{
	/**
	 * The template renderer.
	 *
	 * @var  RendererInterface
	 */
	private $renderer;

	/**
	 * The view object.
	 *
	 * @var  DashboardHtmlView
	 */
	private $view;

	/**
	 * Constructor.
	 *
	 * @param   DashboardHtmlView    $view   The view object.
	 * @param   RendererInterface    $renderer  The template renderer.
	 * @param   Input                $input     The input object.
	 * @param   AbstractApplication  $app       The application object.
	 */
	public function __construct(DashboardHtmlView $view, RendererInterface $renderer, Input $input = null, AbstractApplication $app = null)
	{
		parent::__construct($input, $app);

		$this->view = $view;
		$this->renderer = $renderer;
	}

	/**
	 * Execute the controller.
	 *
	 * @return  boolean
	 * @throws \Exception
	 */
	public function execute(): bool
	{
		// Do not Enable browser caching
		$this->getApplication()->allowCache(false);

		$task = $this->getInput()->getString('account', null);

		/** @var \Kumwe\CMS\Application\AdminApplication $app */
		$app = $this->getApplication();

		/** @var \Kumwe\CMS\User\UserFactory $userFactory */
		$userFactory = $app->getUserFactory();

		// if the user is logged in we go to dashboard
		if ($userFactory->active())
		{
			$this->view->setActiveDashboard('dashboard');
			$this->view->setActiveId(0);
			$this->getApplication()->setResponse(new HtmlResponse($this->view->render()));
		}
		elseif ('signup' === $task)
		{
			$this->getApplication()->setResponse(new HtmlResponse($this->renderer->render('signup.twig')));
		}
		else
		{
			$this->getApplication()->setResponse(new HtmlResponse($this->renderer->render('login.twig')));
		}

		return true;
	}
}
