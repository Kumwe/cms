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

use Joomla\Controller\AbstractController;
use Laminas\Diactoros\Response\TextResponse;

/**
 * Controller class to display a message to individuals looking for the wrong CMS
 *
 * @method         \Kumwe\CMS\Application\SiteApplication  getApplication()  Get the application object.
 * @property-read  \Kumwe\CMS\Application\SiteApplication  $app              Application object
 */
class WrongCmsController extends AbstractController
{
	/**
	 * Execute the controller.
	 *
	 * @return  boolean
	 */
	public function execute(): bool
	{
		// Enable browser caching
		$this->getApplication()->allowCache(true);

		$response = new TextResponse("This isn't the what you're looking for.", 404);

		$this->getApplication()->setResponse($response);

		return true;
	}
}
