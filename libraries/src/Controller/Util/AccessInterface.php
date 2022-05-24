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

/**
 * Class for checking the user access
 *
 * @since  1.0.0
 */
interface  AccessInterface
{
	/**
	 * @param   string  $task
	 * @param   string  $default
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function allow(string $task, string $default = ''): bool;
}
