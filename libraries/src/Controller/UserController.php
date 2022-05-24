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

use Exception;
use Joomla\Application\AbstractApplication;
use Joomla\Controller\AbstractController;
use Joomla\Input\Input;
use Joomla\Authentication\Password\BCryptHandler;
use Laminas\Diactoros\Response\HtmlResponse;
use Kumwe\CMS\Controller\Util\AccessInterface;
use Kumwe\CMS\Controller\Util\AccessTrait;
use Kumwe\CMS\Controller\Util\CheckTokenInterface;
use Kumwe\CMS\Controller\Util\CheckTokenTrait;
use Kumwe\CMS\Date\Date;
use Kumwe\CMS\Factory;
use Kumwe\CMS\Model\UserModel;
use Kumwe\CMS\User\User;
use Kumwe\CMS\User\UserFactoryInterface;
use Kumwe\CMS\View\Admin\UserHtmlView;

/**
 * Controller handling the requests
 *
 * @method         \Kumwe\CMS\Application\AdminApplication  getApplication()  Get the application object.
 * @property-read  \Kumwe\CMS\Application\AdminApplication $app              Application object
 */
class UserController extends AbstractController implements AccessInterface, CheckTokenInterface
{
	use AccessTrait, CheckTokenTrait;

	/**
	 * The view object.
	 *
	 * @var  UserHtmlView
	 */
	private $view;

	/**
	 * The model object.
	 *
	 * @var  UserModel
	 */
	private $model;

	/**
	 * @var BCryptHandler
	 */
	private $secure;

	/**
	 * @var User
	 */
	private $user;

	/**
	 * Constructor.
	 *
	 * @param   UserModel                 $model  The model object.
	 * @param   UserHtmlView              $view   The view object.
	 * @param   Input|null                $input  The input object.
	 * @param   User|null                 $user   The current user.
	 * @param   AbstractApplication|null  $app    The application object.
	 */
	public function __construct(
		UserModel           $model,
		UserHtmlView        $view,
		Input               $input = null,
		AbstractApplication $app = null,
		User                $user = null,
		BCryptHandler       $secure = null)
	{
		parent::__construct($input, $app);

		$this->model  = $model;
		$this->view   = $view;
		$this->user   = ($user) ?: Factory::getContainer()->get(UserFactoryInterface::class)->getUser();
		$this->secure = ($secure) ?: new BCryptHandler();
	}

	/**
	 * Execute the controller.
	 *
	 * @return  boolean
	 * @throws Exception
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
			// check that the user does not delete him/her self
			if ($this->allow('user') && $this->user->get('access.user.delete', false))
			{
				// get the current user being deleted
				/** @var \Kumwe\CMS\User\User $userBeingDeleted */
				$userBeingDeleted = Factory::getContainer()->get(UserFactoryInterface::class)->getUser($id);
				// get the current active user ID
				$user_id = $this->user->get('id', -1);
				// is this the same user account as the active user
				if ($user_id == $id)
				{
					$this->getApplication()->enqueueMessage('You can not delete your own account!', 'warning');
				}
				elseif ($userBeingDeleted->get('is_admin', false) && !$this->user->get('is_admin', false))
				{
					$this->getApplication()->enqueueMessage('You dont have the permission to delete an administrator account!', 'error');
				}
				elseif ($this->model->delete($id))
				{
					$this->getApplication()->enqueueMessage('User was deleted!', 'success');
				}
				else
				{
					$this->getApplication()->enqueueMessage('User could not be deleted!', 'error');
				}
			}
			else
			{
				$this->getApplication()->enqueueMessage('You do not have permission to delete this user!', 'error');
			}
			// go to set page
			$this->_redirect('users');

			return true;
		}

		// set the current user ID
		$user_id = $this->user->get('id', -1);

		if ('POST' === $method)
		{
			// always check the post token
			$this->checkToken();
			// get the post
			$post = $this->getInput()->getInputForRequestMethod();

			// we get all the needed items
			$tempItem               = $post->getArray(['groups' => 'INT']);
			$tempItem['id']         = $post->getInt('user_id', 0);
			$tempItem['name']       = $post->getString('name', '');
			$tempItem['username']   = $post->getUsername('username', '');
			$tempItem['password']   = $post->getString('password', '');
			$tempItem['password2']  = $post->getString('password2', '');
			$tempItem['email']      = $post->getString('email', '');
			$tempItem['block']      = $post->getInt('block', 1);
			$tempItem['sendEmail']  = $post->getInt('sendEmail', 1);
			$tempItem['activation'] = $post->getInt('activation', 0);

			// check permissions
			$update     = ($tempItem['id'] > 0 && $this->user->get('access.user.update', false));
			$create     = ($tempItem['id'] == 0 && $this->user->get('access.user.create', false));
			$selfUpdate = ($tempItem['id'] > 0 && $tempItem['id'] == $user_id);

			if ($create || $update || $selfUpdate)
			{
				$id = $this->setItem($tempItem);
			}
			else
			{
				// not allowed creating user
				if ($id == 0)
				{
					$this->getApplication()->enqueueMessage('You do not have permission to create users!', 'error');
				}
				// not allowed updating user
				if ($id > 0)
				{
					$this->getApplication()->enqueueMessage('You do not have permission to update the user details!', 'error');
				}
			}
		}

		// check permissions
		$read       = ($id > 0 && $this->user->get('access.user.read', false));
		$create     = ($id == 0 && $this->user->get('access.user.create', false));
		$selfUpdate = ($id > 0 && $id == $user_id);

		// check if user is allowed to access
		if ($this->allow('user') && ($read || $create || $selfUpdate))
		{
			// set values for view
			$this->view->setActiveId($id);
			$this->view->setActiveView('user');

			$this->getApplication()->setResponse(new HtmlResponse($this->view->render()));
		}
		else
		{
			// not allowed creating user
			if ($id == 0 && !$create)
			{
				$this->getApplication()->enqueueMessage('You do not have permission to create users!', 'error');
			}
			// not allowed updating user
			if ($id > 0 && !$read)
			{
				$this->getApplication()->enqueueMessage('You do not have permission to read the user details!', 'error');
			}

			// go to set page
			$this->_redirect('users');
		}

		return true;
	}

	/**
	 * Set an item
	 *
	 * @param   array  $tempItem
	 *
	 * @return  int
	 * @throws Exception
	 */
	protected function setItem(array $tempItem): int
	{
		$can_save = true;
		// check that we have a name
		if (empty($tempItem['name']))
		{
			// we show an error message
			$tempItem['name'] = '';
			$this->getApplication()->enqueueMessage('Name field is required.', 'error');
			$can_save = false;
		}
		// check that we have a username
		if (empty($tempItem['username']))
		{
			// we show an error message
			$tempItem['username'] = '';
			$this->getApplication()->enqueueMessage('Username field is required.', 'error');
			$can_save = false;
		}
		// check that we have an email TODO: check that we have a valid email
		if (empty($tempItem['email']))
		{
			// we show an error message
			$tempItem['email'] = '';
			$this->getApplication()->enqueueMessage('Email field is required.', 'error');
			$can_save = false;
		}
		// check passwords
		if (isset($tempItem['password2']) && $tempItem['password'] != $tempItem['password2'])
		{
			// we show an error message
			$tempItem['password']  = 'xxxxxxxxxx';
			$tempItem['password2'] = 'xxxxxxxxxx';
			$this->getApplication()->enqueueMessage('Passwords do not match.', 'error');
			$can_save = false;
		}
		unset ($tempItem['password2']);
		// do not set password that has not changed
		if ($tempItem['password'] === 'xxxxxxxxxx')
		{
			if ($tempItem['id'] == 0)
			{
				// we show an error message
				$tempItem['password']  = 'xxxxxxxxxx';
				$tempItem['password2'] = 'xxxxxxxxxx';
				$this->getApplication()->enqueueMessage('Passwords not set.', 'error');
				$can_save = false;
			}
			else
			{
				$tempItem['password'] = '';
			}
		}
		elseif (strlen($tempItem['password']) < 7)
		{
			// we show an error message
			$tempItem['password']  = 'xxxxxxxxxx';
			$tempItem['password2'] = 'xxxxxxxxxx';
			$this->getApplication()->enqueueMessage('Passwords must be longer than 6 characters.', 'error');
			$can_save = false;
		}
		else
		{
			// hash the password
			$tempItem['password'] = $this->secure->hashPassword($tempItem['password']);
		}

		// can we save the item
		if ($can_save)
		{
			// check that the user does not block him/her self
			$user_id      = $this->user->get('id', -1);
			$block_status = $tempItem['block'];
			// this user is the current user
			if ($user_id == $tempItem['id'])
			{
				// don't allow user to block self
				if ($tempItem['block'] != 0)
				{
					// we show a warning message
					$this->getApplication()->enqueueMessage('You can not block yourself!', 'warning');
					$tempItem['block'] = 0;
				}
				// don't allow user remove self from admin groups
				if ($this->user->get('is_admin', false))
				{
					$admin_groups = $this->user->get('is_admin_groups', []);
					if (is_array($admin_groups) && count($admin_groups) > 0)
					{
						$notice_set_groups = true;
						foreach ($admin_groups as $admin_group)
						{
							if (!is_array($tempItem['groups']) || !in_array($admin_group, $tempItem['groups']))
							{
								if ($notice_set_groups)
								{
									// we show a warning message
									$this->getApplication()->enqueueMessage('You can not remove yourself from the administrator group!', 'warning');
									$notice_set_groups = false;
								}
								$tempItem['groups'][] = $admin_group;
							}
						}
					}
					else
					{
						// we show an error message
						$this->getApplication()->enqueueMessage('There is a problem with the admin user groups, we can not save the user details.', 'error');
						$can_save = false;
					}
				}
			}

			// can we save the item
			if ($can_save)
			{
				// check that the user will have some groups left
				if (!is_array($tempItem['groups']) || count($tempItem['groups']) == 0)
				{
					// we show a warning message
					$this->getApplication()->enqueueMessage('You must select at least one group.', 'warning');
					// this user is the current user
					if ($user_id == $tempItem['id'])
					{
						$tempItem['groups'] = $this->user->get('groups_ids', []);
						// check if we still have no groups
						if (count($tempItem['groups']) == 0)
						{
							$can_save = false;
						}
					}
					else
					{
						$can_save = false;
					}
				}
			}

			// can we save the item
			if ($can_save)
			{
				// none admin restrictions TODO would like to move this to the database and not hard code it
				if (!$this->user->get('is_admin', false))
				{
					// with existing users
					if ($tempItem['id'] > 0)
					{
						// get the current user being saved
						/** @var \Kumwe\CMS\User\User $userBeingSaved */
						$userBeingSaved = Factory::getContainer()->get(UserFactoryInterface::class)->getUser($tempItem['id']);
						// don't allow block status change by none admin users
						$block = $userBeingSaved->get('block', 1);
						$current_posted_block = $tempItem['block'];
						// if the status changed we revert and give message
						// we allow block but not un-block
						if ($block != $current_posted_block && $current_posted_block == 0)
						{
							// we show a warning message
							$this->getApplication()->enqueueMessage('Only the administrator can update user access to system.', 'warning');
							$tempItem['block'] = 1;
						}
						// get current group to see if we must give a notice
						$groups = $userBeingSaved->get('groups_ids', []);
						$current_posted_groups = $tempItem['groups'];
						sort($groups);
						sort($current_posted_groups);
						// if the groups changes we give a message
						if ($groups !== $current_posted_groups)
						{
							// we show a warning message
							$this->getApplication()->enqueueMessage('Only the administrator can update user group selection.', 'warning');
						}
						// if the current user being saved is an admin account
						// we don't allow the following changes
						if ($userBeingSaved->get('is_admin', false))
						{
							// we do not allow password changes of admin accounts
							$tempItem['password'] = '';
							// we don't allow username changes
							$tempItem['username'] = $userBeingSaved->get('username', $tempItem['username']);
							// we don't allow change of status
							if ($block != $current_posted_block)
							{
								// we show an error message
								$this->getApplication()->enqueueMessage('Only the administrator can update another administrator account access to system.', 'error');
								$tempItem['block'] = $block;
							}
						}
					}
					else
					{
						// new users created by none admin must be blocked by default
						// since only admin can unblock any users
						$tempItem['block'] = 1;
					}
					// only admin can change groups
					// empty groups will not get updated
					$tempItem['groups'] = [];
				}

				$today = (new Date())->toSql();

				return $this->model->setItem(
					$tempItem['id'],
					$tempItem['name'],
					$tempItem['username'],
					$tempItem['groups'],
					$tempItem['email'],
					$tempItem['password'],
					$tempItem['block'],
					$tempItem['sendEmail'],
					$today,
					$tempItem['activation']);
			}
		}

		// add to model the post values
		$this->model->tempItem = $tempItem;

		return $tempItem['id'];
	}
}
