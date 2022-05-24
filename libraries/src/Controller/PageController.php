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
use Joomla\Uri\Uri;
use Kumwe\CMS\Utilities\StringHelper;
use Kumwe\CMS\View\Site\PageHtmlView;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;

/**
 * Controller handling the requests
 *
 * @method         \Kumwe\CMS\Application\SiteApplication  getApplication()  Get the application object.
 * @property-read  \Kumwe\CMS\Application\SiteApplication  $app              Application object
 */
class PageController extends AbstractController
{
	/**
	 * The view object.
	 *
	 * @var  PageHtmlView
	 */
	private $view;

	/**
	 * Constructor.
	 *
	 * @param   PageHtmlView              $view   The view object.
	 * @param   Input|null                $input  The input object.
	 * @param   AbstractApplication|null  $app    The application object.
	 */
	public function __construct(PageHtmlView $view, Input $input = null, AbstractApplication $app = null)
	{
		parent::__construct($input, $app);

		$this->view = $view;
	}

	/**
	 * Execute the controller.
	 *
	 * @return  boolean
	 */
	public function execute(): bool
	{
		// Disable all cache for now
		$this->getApplication()->allowCache(false);

		// get the root name
		$root = $this->getInput()->getString('root', '');
		// start building the full path
		$path = [];
		$path[] = $root;
		// set a mad depth TODO: we should limit the menu depth to 6 or something
		$depth = range(1,20);
		// load the whole path
		foreach ($depth as $page)
		{
			$page = StringHelper::numbers($page);
			// check if there is a value
			$result = $this->getInput()->getString($page, false);
			if ($result)
			{
				$path[] = $result;
			}
			else
			{
				// first false means we are at the end of the line
				break;
			}
		}
		// set the final path
		$path = implode('/', $path);

		// if for some reason the view value is administrator
		if ('administrator' === $root)
		{
			// get uri request to get host
			$uri = new Uri($this->getApplication()->get('uri.request'));

			// Redirect to the administrator area
			$this->getApplication()->setResponse(new RedirectResponse($uri->getScheme() . '://' . $uri->getHost() . '/administrator/', 301));
		}
		else
		{
			$this->view->setPage($path);

			$this->getApplication()->setResponse(new HtmlResponse($this->view->render()));
		}

		return true;
	}
}
