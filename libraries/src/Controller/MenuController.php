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
use Laminas\Diactoros\Response\HtmlResponse;
use Kumwe\CMS\Controller\Util\AccessInterface;
use Kumwe\CMS\Controller\Util\AccessTrait;
use Kumwe\CMS\Controller\Util\CheckTokenInterface;
use Kumwe\CMS\Controller\Util\CheckTokenTrait;
use Kumwe\CMS\Factory;
use Kumwe\CMS\Filter\InputFilter;
use Kumwe\CMS\Model\MenuModel;
use Kumwe\CMS\User\User;
use Kumwe\CMS\User\UserFactoryInterface;
use Kumwe\CMS\View\Admin\MenuHtmlView;

/**
 * Controller handling the requests
 *
 * @method         \Kumwe\CMS\Application\AdminApplication  getApplication()  Get the application object.
 * @property-read  \Kumwe\CMS\Application\AdminApplication $app              Application object
 */
class MenuController extends AbstractController implements AccessInterface, CheckTokenInterface
{
	use AccessTrait, CheckTokenTrait;

	/**
	 * The view object.
	 *
	 * @var  MenuHtmlView
	 */
	private $view;

	/**
	 * The model object.
	 *
	 * @var  MenuModel
	 */
	private $model;

	/**
	 * @var InputFilter
	 */
	private $inputFilter;

	/**
	 * @var User
	 */
	private $user;

	/**
	 * Constructor.
	 *
	 * @param   MenuModel                 $model  The model object.
	 * @param   MenuHtmlView              $view   The view object.
	 * @param   Input|null                $input  The input object.
	 * @param   AbstractApplication|null  $app    The application object.
	 */
	public function __construct(
		MenuModel           $model,
		MenuHtmlView        $view,
		Input               $input = null,
		AbstractApplication $app = null,
		User                $user = null)
	{
		parent::__construct($input, $app);

		$this->model = $model;
		$this->view  = $view;
		$this->user  = ($user) ?: Factory::getContainer()->get(UserFactoryInterface::class)->getUser();
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

		$method = $this->getInput()->getMethod();
		$task   = $this->getInput()->getString('task', '');
		$id     = $this->getInput()->getInt('id', 0);

		// if task is delete
		if ('delete' === $task)
		{
			if ($this->allow('menu') && $this->user->get('access.menu.delete', false))
			{
				if ($this->model->delete($id))
				{
					$this->getApplication()->enqueueMessage('Menu was deleted!', 'success');
				}
				else
				{
					$this->getApplication()->enqueueMessage('Menu could not be deleted!', 'error');
				}
			}
			else
			{
				$this->getApplication()->enqueueMessage('You do not have permission to delete this menu!', 'error');
			}
			// go to set page
			$this->_redirect('menus');

			return true;
		}

		if ('POST' === $method)
		{
			// check permissions
			$update = ($id > 0 && $this->user->get('access.menu.update', false));
			$create = ($id == 0 && $this->user->get('access.menu.create', false));

			if ( $create || $update )
			{
				$id = $this->setItem();
			}
			else
			{
				// not allowed creating menu
				if ($id == 0)
				{
					$this->getApplication()->enqueueMessage('You do not have permission to create menus!', 'error');
				}
				// not allowed updating menu
				if ($id > 0)
				{
					$this->getApplication()->enqueueMessage('You do not have permission to update the menu details!', 'error');
				}
			}
		}

		// check permissions
		$read = ($id > 0 && $this->user->get('access.menu.read', false));
		$create = ($id == 0 && $this->user->get('access.menu.create', false));

		// check if user is allowed to access
		if ($this->allow('menu') && ( $read || $create ))
		{
			// set values for view
			$this->view->setActiveId($id);
			$this->view->setActiveView('menu');

			$this->getApplication()->setResponse(new HtmlResponse($this->view->render()));
		}
		else
		{
			// not allowed creating menu
			if ($id == 0 && !$create)
			{
				$this->getApplication()->enqueueMessage('You do not have permission to create menus!', 'error');
			}
			// not allowed read menu
			if ($id > 0 && !$read)
			{
				$this->getApplication()->enqueueMessage('You do not have permission to read the menu details!', 'error');
			}

			// go to set page
			$this->_redirect('menus');
		}

		return true;
	}

	/**
	 * Set an item
	 *
	 *
	 * @return  int
	 * @throws \Exception
	 */
	protected function setItem(): int
	{
		// always check the post token
		$this->checkToken();
		// get the post
		$post = $this->getInput()->getInputForRequestMethod();

		// we get all the needed items
		$tempItem                 = [];
		$tempItem['id']           = $post->getInt('menu_id', 0);
		$tempItem['title']        = $post->getString('title', '');
		$tempItem['alias']        = $post->getString('alias', '');
		$tempItem['path']         = $post->getString('path', '');
		$tempItem['item_id']      = $post->getInt('item_id', 0);
		$tempItem['published']    = $post->getInt('published', 1);
		$tempItem['publish_up']   = $post->getString('publish_up', '');
		$tempItem['publish_down'] = $post->getString('publish_down', '');
		$tempItem['position']     = $post->getString('position', 'center');
		$tempItem['home']         = $post->getInt('home', 0);
		$tempItem['parent_id']    = $post->getInt('parent_id', 0);

		// check that we have a Title
		$can_save = true;
		if (empty($tempItem['title']))
		{
			// we show a warning message
			$tempItem['title'] = '';
			$this->getApplication()->enqueueMessage('Title field is required.', 'error');
			$can_save = false;
		}
		// we actually can also not continue if we don't have content
		if (empty($tempItem['item_id']) || $tempItem['item_id'] == 0)
		{
			// we show a warning message
			$tempItem['item_id'] = 0;
			$this->getApplication()->enqueueMessage('Item field is required.', 'error');
			$can_save = false;
		}

		// can we save the item
		if ($can_save)
		{
			return $this->model->setItem(
				$tempItem['id'],
				$tempItem['title'],
				$tempItem['alias'],
				$tempItem['item_id'],
				$tempItem['path'],
				$tempItem['published'],
				$tempItem['publish_up'],
				$tempItem['publish_down'],
				$tempItem['position'],
				$tempItem['home'],
				$tempItem['parent_id']);
		}

		// add to model the post values
		$this->model->tempItem = $tempItem;

		return $tempItem['id'];
	}
}
