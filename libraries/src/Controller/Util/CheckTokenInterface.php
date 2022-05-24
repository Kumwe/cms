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
 * Class for checking the form had a token
 *
 * @since  1.0.0
 */
interface  CheckTokenInterface
{
	/**
	 * Check the token of the form
	 *
	 * @return bool
	 */
	public function checkToken(): bool;
}
