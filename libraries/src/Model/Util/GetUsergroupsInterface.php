<?php
/**
 * @package    Kumwe CMS
 *
 * @created    23th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Model\Util;

/**
 * Class for all user groups
 *
 * @since  1.0.0
 */
interface  GetUsergroupsInterface
{
	/**
	 * Get all user groups
	 *
	 * @param   int|null  $id
	 *
	 * @return array
	 */
	public function getUsergroups(?int $id = null): array;

	/**
	 * Get the group default full access values
	 *
	 * @param   string  $access
	 *
	 * @return array
	 */
	public function getGroupDefaultsAccess(string $access = 'CRUD'): array;
}
