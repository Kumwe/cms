<?php
/**
 * @package    Kumwe CMS
 *
 * @created    20th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\View\Admin;

use Kumwe\CMS\Model\UsergroupsModel;
use Joomla\Renderer\RendererInterface;
use Joomla\View\HtmlView;

/**
 * HTML view class for the application
 */
class UsergroupsHtmlView extends HtmlView
{
	/**
	 * The model object.
	 *
	 * @var  UsergroupsModel
	 */
	private $model;

	/**
	 * Instantiate the view.
	 *
	 * @param   UsergroupsModel    $model       The model object.
	 * @param   RendererInterface  $renderer    The renderer object.
	 */
	public function __construct(UsergroupsModel $model, RendererInterface $renderer)
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
	 * Set the active view
	 *
	 * @param   string  $name  The active view name
	 *
	 * @return  void
	 */
	public function setActiveView(string $name): void
	{
		$this->setLayout($this->model->setLayout($name));
	}
}
