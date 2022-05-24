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
trait CheckTokenTrait
{
	/**
	 * Check the token of the form
	 *
	 * @return bool
	 */
	public function checkToken(): bool
	{
		$token = $this->getApplication()->getSession()->getToken();
		$form_token = $this->getInput()->getString($token, 0);

		if ($form_token == 0)
		{
			exit('Invalid form token');
		}
		return true;
	}
}
