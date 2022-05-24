<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\View\Admin;

use Kumwe\CMS\Model\DashboardModel;
use Joomla\Renderer\RendererInterface;
use Joomla\View\HtmlView;

/**
 * HTML view class for the application
 */
class DashboardHtmlView extends HtmlView
{
	/**
	 * The id
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The page model object.
	 *
	 * @var  DashboardModel
	 */
	private $model;

	/**
	 * Instantiate the view.
	 *
	 * @param   DashboardModel     $model       The model object.
	 * @param   RendererInterface  $renderer    The renderer object.
	 */
	public function __construct(DashboardModel $model, RendererInterface $renderer)
	{
		parent::__construct($renderer);

		$this->model = $model;
	}

	/**
	 * Method to render the view
	 *
	 * @return  string  The rendered view
	 */
	public function render(): string
	{
		$this->setData(['page' => $this->id]);
		return parent::render();
	}

	/**
	 * Set the active view
	 *
	 * @param   string  $name  The active page name
	 *
	 * @return  void
	 */
	public function setActiveDashboard(string $name): void
	{
		$this->setLayout($this->model->getDashboard($name));
	}

	/**
	 * Set the active id
	 *
	 * @param   int  $id  The active id
	 *
	 * @return  void
	 */
	public function setActiveId(int $id): void
	{
		$this->id = $id;
	}
}
