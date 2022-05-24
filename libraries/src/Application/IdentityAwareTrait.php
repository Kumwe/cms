<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2005 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Kumwe\CMS\Application;

use Kumwe\CMS\User\User;
use Kumwe\CMS\User\UserFactoryInterface;

/**
 * Trait for application classes which are identity (user) aware
 *
 * @since  1.0.0
 */
trait IdentityAwareTrait
{
	/**
	 * The application identity object.
	 *
	 * @var    User
	 * @since  1.0.0
	 */
	protected $identity;

	/**
	 * UserFactoryInterface
	 *
	 * @var    UserFactoryInterface
	 * @since  1.0.0
	 */
	private $userFactory;

	/**
	 * Get the application identity.
	 *
	 * @return  User
	 *
	 * @since   1.0.0
	 */
	public function getIdentity(): User
	{
		return $this->identity;
	}

	/**
	 * Allows the application to load a custom or default identity.
	 *
	 * @param   User  $identity  An optional identity object. If omitted, a null user object is created.
	 *
	 * @return  IdentityAwareInterface
	 *
	 * @since   1.0.0
	 */
	public function loadIdentity(User $identity = null): IdentityAwareInterface
	{
		$this->identity = $identity ?: $this->userFactory->loadUserById(0);

		return $this;
	}

	/**
	 * Set the user factory to use.
	 *
	 * @param   UserFactoryInterface  $userFactory  The user factory to use
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 */
	public function setUserFactory(UserFactoryInterface $userFactory)
	{
		$this->userFactory = $userFactory;
	}

	/**
	 * Get the user factory to use.
	 *
	 * @return  UserFactoryInterface
	 *
	 * @since   1.0.0
	 */
	public function getUserFactory(): UserFactoryInterface
	{
		return $this->userFactory;
	}
}

