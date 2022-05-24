<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Application;

use Kumwe\CMS\User\User;
use Kumwe\CMS\User\UserFactoryInterface;

interface IdentityAwareInterface
{
	/**
	 * Get the application identity.
	 *
	 * @return  User
	 *
	 * @since   1.0.0
	 */
	public function getIdentity(): User;

	/**
	 * Allows the application to load a custom or default identity.
	 *
	 * @param   User  $identity  An optional identity object. If omitted, a null user object is created.
	 *
	 * @return  $this
	 *
	 * @since   1.0.0
	 */
	public function loadIdentity(User $identity = null): IdentityAwareInterface;

	/**
	 * Set the user factory to use.
	 *
	 * @param   UserFactoryInterface  $userFactory  The user factory to use
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 */
	public function setUserFactory(UserFactoryInterface $userFactory);

	/**
	 * Get the user factory to use.
	 *
	 * @return  UserFactoryInterface
	 *
	 * @since   1.0.0
	 */
	public function getUserFactory(): UserFactoryInterface;
}
