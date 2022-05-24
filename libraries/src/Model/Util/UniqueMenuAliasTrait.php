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
 * Trait for getting unique string
 *
 * @since  1.0.0
 */
trait UniqueMenuAliasTrait
{
	/**
	 * Get a unique string
	 *
	 * @param   int     $id
	 * @param   string  $value
	 * @param   int     $parent
	 * @param   string  $key
	 * @param   string  $spacer
	 *
	 * @return string
	 */
	public function unique(int $id, string $value, int $parent = -1, string $key = 'alias', string $spacer = '-'): string
	{
		// start building the value
		$value = str_replace($spacer, ' ', $value);
		$value = preg_replace('/\s+/', $spacer, strtolower(preg_replace("/[^A-Za-z0-9\- ]/", '', $value)));
		// set a counter
		$counter = 2;
		// set original tracker
		$original = $value;
		// check if we found any with the same alias
		while ($this->exist($id, $value, $key, $parent))
		{
			$value = $original . '-' . $counter;
			$counter++;
		}
		// return the unique value (on this parent layer)
		return $value;
	}

	/**
	 * Check if an any key exist with same parent
	 *
	 * @param   int     $id
	 * @param   string  $value
	 * @param   string  $key
	 * @param   int     $parent
	 *
	 * @return bool
	 */
	public function exist(int $id, string $value, string $key = 'alias', int $parent = -1): bool
	{
		$db = $this->getDb();
		$query = $db->getQuery(true)
			->select('id')
			->from($db->quoteName('#__menu'))
			->where($db->quoteName($key) . " = :$key")
			->bind(":$key", $value)
			->setLimit(1);

		// only add the id item exist
		if ($parent >= 0)
		{
			$query
				->where($db->quoteName('parent_id') . ' = :parent_id')
				->bind(':parent_id', $parent, ParameterType::INTEGER);
		}

		// only add the id item exist
		if ($id > 0)
		{
			$query
				->where($db->quoteName('id') . ' != :id')
				->bind(':id', $id, ParameterType::INTEGER);
		}

		try
		{
			$result = $db->setQuery($query)->loadResult();
		}
		catch (\RuntimeException $e)
		{
			// we ignore this and just return an empty object
		}

		if (isset($result) && $result > 0)
		{
			return true;
		}
		return false;
	}
}
