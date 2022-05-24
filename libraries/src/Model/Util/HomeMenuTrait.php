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
 * Trait for getting home menu
 *
 * @since  1.0.0
 */
trait HomeMenuTrait
{
	/**
	 * @return \stdClass
	 */
	public function getHomePage(): \stdClass
	{
		$db = $this->getDb();

		$query = $db->getQuery(true)
			->select('a.*')
			->from($db->quoteName('#__menu', 'a'))
			->where($db->quoteName('a.parent_id') . ' = 0')
			->where($db->quoteName('a.published') . ' = 1')
			->where($db->quoteName('a.home') . ' = 1')
			->setLimit(1);

		try
		{
			$home = $db->setQuery($query)->loadObject();
		}
		catch (\RuntimeException $e)
		{
			return new \stdClass();
		}

		if ($home)
		{
			return $home;
		}

		return new \stdClass();
	}
}
