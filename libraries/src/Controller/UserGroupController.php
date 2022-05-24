<?php
/**
 * @package    Kumwe CMS
 *
 * @created    20th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Controller;

use Joomla\Application\AbstractApplication;
use Joomla\Controller\AbstractController;
use Joomla\Input\Input;
use Kumwe\CMS\Controller\Util\AccessInterface;
use Kumwe\CMS\Controller\Util\AccessTrait;
use Kumwe\CMS\Controller\Util\CheckTokenInterface;
use Kumwe\CMS\Controller\Util\CheckTokenTrait;
use Kumwe\CMS\Factory;
use Kumwe\CMS\Model\UsergroupModel;
use Kumwe\CMS\User\User;
use Kumwe\CMS\User\UserFactoryInterface;
use Kumwe\CMS\View\Admin\UsergroupHtmlView;
use Laminas\Diactoros\Response\HtmlResponse;

/**
 * Controller handling the requests
 *
 * @method         \Kumwe\CMS\Application\AdminApplication  getApplication()  Get the application object.
 * @property-read  \Kumwe\CMS\Application\AdminApplication $app              Application object
 */
class UserGroupController extends AbstractController implements AccessInterface, CheckTokenInterface
{
	use AccessTrait, CheckTokenTrait;

	/**
	 * The view object.
	 *
	 * @var  UsergroupHtmlView
	 */
	private $view;

	/**
	 * The model object.
	 *
	 * @var  UsergroupModel
	 */
	private $model;

	/**
	 * @var User
	 */
	private $user;

	/**
	 * Constructor.
	 *
	 * @param   UsergroupModel            $model  The model object.
	 * @param   UsergroupHtmlView         $view   The view object.
	 * @param   Input|null                $input  The input object.
	 * @param   AbstractApplication|null  $app    The application object.
	 */
	public function __construct(
		UsergroupModel      $model,
		UsergroupHtmlView   $view,
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
			if ($this->allow('usergroup') && $this->user->get('access.usergroup.delete', false))
			{
				// TODO not ideal to hard code any ID
				if ($id == 1)
				{
					$this->getApplication()->enqueueMessage('This is the administrator user group that can not be deleted.', 'error');
				}
				elseif ($id > 0 && $this->model->linked($id))
				{
					$this->getApplication()->enqueueMessage('This user group is still in use and can therefore no be deleted.', 'error');
				}
				elseif ($this->model->delete($id))
				{
					$this->getApplication()->enqueueMessage('User group was deleted!', 'success');
				}
				else
				{
					$this->getApplication()->enqueueMessage('User group could not be deleted!', 'error');
				}
			}
			else
			{
				$this->getApplication()->enqueueMessage('You do not have permission to delete this user group!', 'error');
			}
			// go to set page
			$this->_redirect('usergroups');

			return true;
		}

		if ('POST' === $method)
		{
			// check permissions
			$update = ($id > 0 && $this->user->get('access.usergroup.update', false));
			$create = ($id == 0 && $this->user->get('access.usergroup.create', false));

			// TODO not ideal to hard code any ID
			if ($id == 1 && $update)
			{
				$this->getApplication()->enqueueMessage('This is the administrator user group that can not change.', 'error');
			}
			elseif ( $create || $update )
			{
				$id = $this->setItem();
			}
			else
			{
				// not allowed creating user group
				if ($id == 0)
				{
					$this->getApplication()->enqueueMessage('You do not have permission to create user groups!', 'error');
				}
				// not allowed updating user group
				if ($id > 0)
				{
					$this->getApplication()->enqueueMessage('You do not have permission to update the user group details!', 'error');
				}
			}
		}

		// check permissions
		$read = ($id > 0 && $this->user->get('access.usergroup.read', false));
		$create = ($id == 0 && $this->user->get('access.usergroup.create', false));

		// check if user is allowed to access
		if ($this->allow('usergroup') && ( $read || $create ))
		{
			// set values for view
			$this->view->setActiveId($id);
			$this->view->setActiveView('usergroup');

			$this->getApplication()->setResponse(new HtmlResponse($this->view->render()));
		}
		else
		{
			// not allowed creating user group
			if ($id == 0 && !$create)
			{
				$this->getApplication()->enqueueMessage('You do not have permission to create user groups!', 'error');
			}
			// not allowed read user group
			if ($id > 0 && !$read)
			{
				$this->getApplication()->enqueueMessage('You do not have permission to read the user group details!', 'error');
			}

			// go to set page
			$this->_redirect('items');
		}

		return true;
	}

	/**
	 * Set an item
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
		$tempItem          = $post->getArray(['params' => 'STRING']);;
		$tempItem['id']    = $post->getInt('usergroup_id', 0);
		$tempItem['title'] = $post->getString('title', '');

		$can_save = true;
		// check that we have a name
		if (empty($tempItem['title']))
		{
			// we show a warning message
			$tempItem['name'] = '';
			$this->getApplication()->enqueueMessage('User group name field is required.', 'error');
			$can_save = false;
		}
		// set the params
		$build_params = $this->model->getGroupDefaultsAccess();
		if (isset($tempItem['params']) && is_array($tempItem['params']) && count($tempItem['params']))
		{
			$only = 'CRUD';
			foreach ($build_params as $n => &$item)
			{
				if (isset($tempItem['params'][$item->area]) && strlen($tempItem['params'][$item->area]))
				{
					$array_of_access = str_split(strtoupper($tempItem['params'][$item->area]));
					$access_keeper = [];
					if ($array_of_access)
					{
						foreach ($array_of_access as $char)
						{
							if (strpos($only, $char) === false)
							{
								$this->getApplication()->enqueueMessage("User group access in ({$item->area} area) had a wrong key ({$char}) so we removed it. Please only use the keys prescribed.", 'warning');
							}
							else
							{
								$access_keeper[] = $char;
							}
						}
					}
					$item->access = implode($access_keeper);
				}
			}
		}
		// update the params
		$tempItem['params'] = $build_params;

		// can we save the item
		if ($can_save)
		{
			return $this->model->setItem(
				$tempItem['id'],
				$tempItem['title'],
				$tempItem['params']);
		}

		// add to model the post values
		$this->model->tempItem = $tempItem;

		return $tempItem['id'];
	}
}
