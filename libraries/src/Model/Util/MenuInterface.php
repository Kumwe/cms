<?php
/**
 * @package    Kumwe CMS
 *
 * @created    18th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Model\Util;

/**
 * Class for getting menu items
 *
 * @since  1.0.0
 */
interface  MenuInterface
{
	/**
	 * Get all menu items
	 *
	 * @param   int $active
	 *
	 * @return array
	 */
	public function getMenus(int $active = 0): array;
}
