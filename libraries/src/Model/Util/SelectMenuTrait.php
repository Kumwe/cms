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

use Joomla\Database\ParameterType;

/**
 * Trait for getting menu items
 *
 * @since  1.0.0
 */
trait SelectMenuTrait
{
	/**
	 * Get all menu items
	 *
	 * @param   int $active
	 *
	 * @return array
	 */
	public function getMenus(int $active = 0): array
	{
		$db = $this->getDb();

		if (empty($active) && !empty($this->id))
		{
			$active = $this->id;
		}

		$query = $db->getQuery(true)
			->select($db->quoteName(array('id', 'title')))
			->from($db->quoteName('#__menu'))
			->where('published = 1')
			->where('home = 0');

		// we need to remove the active menu
		if ($active > 0)
		{
			$query
				->where($db->quoteName('id') . ' != :id')
				->bind(':id', $active, ParameterType::INTEGER);
		}

		return $db->setQuery($query)->loadObjectList('id');
	}
}
