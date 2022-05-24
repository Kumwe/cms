<?php
/**
 * @package    Kumwe CMS
 *
 * @created    18th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\View\Admin;

use Kumwe\CMS\Model\MenusModel;
use Joomla\Renderer\RendererInterface;
use Joomla\View\HtmlView;

/**
 * HTML view class for the application
 */
class MenusHtmlView extends HtmlView
{
	/**
	 * The model object.
	 *
	 * @var  MenusModel
	 */
	private $model;

	/**
	 * Instantiate the view.
	 *
	 * @param   MenusModel         $model       The model object.
	 * @param   RendererInterface  $renderer    The renderer object.
	 */
	public function __construct(MenusModel $model, RendererInterface $renderer)
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
		$this->setData(['list' => $this->model->getItems()]);
		return parent::render();
	}

	/**
	 * Set the active id
	 *
	 * @param   string  $name  The active id
	 *
	 * @return  void
	 */
	public function setActiveView(string $name): void
	{
		$this->setLayout($this->model->setLayout($name));
	}
}
