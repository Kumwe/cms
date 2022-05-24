<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Controller\Util;

use Joomla\Uri\Uri;

/**
 * Trait for checking the user access
 *
 * @since  1.0.0
 */
trait AccessTrait
{
	/**
	 * When access not allowed this is the path to redirect to
	 *
	 * @var string
	 */
	private $noAccessRedirect = '';

	/**
	 * Check if user is allowed to access this area
	 *
	 * @param   string  $task
	 * @param   string  $default
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function allow(string $task = 'post', string $default = ''): bool
	{
		// our little access controller TODO: we can do better
		$has_access = false;

		/** @var \Kumwe\CMS\Application\AdminApplication $app */
		$app = $this->getApplication();

		/** @var \Kumwe\CMS\User\UserFactory $userFactory */
		$userFactory = $app->getUserFactory();

		// user actions [logout]
		if ('logout' === $task)
		{
			if ($userFactory->logout())
			{
				$this->noAccessRedirect = '/';
				// clear the message queue
				$app->getMessageQueue(true);
			}
		}
		// check if this is a user valid
		elseif ($userFactory->active())
		{
			$has_access = true;
		}
		// user actions [access, signup]
		elseif ('access' === $task || 'signup' === $task)
		{
			if ('access' === $task)
			{
				if ($userFactory->login())
				{
					$has_access = true;
				}
			}
			else
			{
				if ($userFactory->create())
				{
					$has_access = true;
				}
				else
				{
					$this->noAccessRedirect = '/?account=signup';
				}
			}

			// we by default always load the dashboard
			$this->view->setActiveDashboard($default);
		}

		return $has_access;
	}

	/**
	 * @param   string|null  $target
	 *
	 * @return void
	 */
	private function _redirect(string $target = null)
	{
		// get uri request to get host
		$uri = new Uri($this->getApplication()->get('uri.request'));

		// get redirect path
		$redirect = (!empty($target)) ? $target : $this->noAccessRedirect;
		// fix the path
		$path = $uri->getPath();
		$path = substr($path, 0, strripos($path, '/')) . '/' . $redirect;
		// redirect to the set area
		$this->getApplication()->redirect($uri->getScheme() . '://' . $uri->getHost() . $path );
	}
}
