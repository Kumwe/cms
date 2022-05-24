<?php
/**
 * @package    Kumwe CMS
 *
 * @created    21th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Model\Util;

/**
 * Class for getting the home page
 *
 * @since  1.0.0
 */
interface  HomeMenuInterface
{
	/**
	 * @return \stdClass
	 */
	public function getHomePage(): \stdClass;
}
