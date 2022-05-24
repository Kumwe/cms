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

use Joomla\Database\ParameterType;

/**
 * Trait for getting page data
 *
 * @since  1.0.0
 */
trait SitePageTrait
{
	/**
	 * Get page data
	 *
	 * @param   string  $path  The page path
	 *
	 * @return  \stdClass
	 *
	 * @throws  \RuntimeException
	 */
	public function getPageItemByPath(string $path): \stdClass
	{
		$db = $this->getDb();

		$query = $db->getQuery(true)
			->select('i.*')
			->select($db->quoteName(array('m.id'), array('menu_id')))
			->from($db->quoteName('#__menu', 'm'))
			->join('INNER', $db->quoteName('#__item', 'i'), 'm.item_id = i.id')
			->where($db->quoteName('i.state') . ' >= 1')
			->where($db->quoteName('m.published') . ' = 1')
			->where($db->quoteName('m.path') . ' = :path')
			->bind(':path', $path)
			->setLimit(1);

		try
		{
			$page = $db->setQuery($query)->loadObject();
		}
		catch (\RuntimeException $e)
		{
			return new \stdClass();
		}

		if ($page)
		{
			return $page;
		}

		return new \stdClass();
	}

	/**
	 * Get page data
	 *
	 * @param   int  $item  The item id
	 *
	 * @return  \stdClass
	 *
	 * @throws  \RuntimeException
	 */
	public function getPageItemById(int $item): \stdClass
	{
		$db = $this->getDb();

		$query = $db->getQuery(true)
			->select('i.*')
			->select($db->quoteName(array('m.id'), array('menu_id')))
			->from($db->quoteName('#__item', 'i'))
			->join('INNER', $db->quoteName('#__menu', 'm'), 'i.id = m.item_id')
			->where($db->quoteName('m.published') . ' = 1')
			->where($db->quoteName('i.state') . ' >= 1')
			->where($db->quoteName('i.id') . ' = :id')
			->bind(':id', $item, ParameterType::INTEGER)
			->setLimit(1);

		try
		{
			$page = $db->setQuery($query)->loadObject();
		}
		catch (\RuntimeException $e)
		{
			return new \stdClass();
		}

		if ($page)
		{
			return $page;
		}

		return new \stdClass();
	}
}
