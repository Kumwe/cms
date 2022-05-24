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

use Joomla\Database\ParameterType;
use Kumwe\CMS\Utilities\StringHelper;
use RuntimeException;
use stdClass;

/**
 * Class for all user groups
 *
 * @since  1.0.0
 * @method getDb()
 */
trait GetUsergroupsTrait
{
	/**
	 * Get all user groups
	 *
	 * @param   int|null  $id user ID
	 *
	 * @return array
	 */
	public function getUsergroups(?int $id = null): array
	{
		$db = $this->getDb();

		$query = $db->getQuery(true)
			->select('g.*')
			->from($db->quoteName('#__usergroups', 'g'));

		if ($id > 0)
		{
			$query
				->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), 'g.id = m.group_id')
				->where($db->quoteName('m.user_id') . ' = :user_id')
				->bind(':user_id', $id, ParameterType::INTEGER);
		}

		try
		{
			$groups = $db->setQuery($query)->loadObjectList('id');
		}
		catch (RuntimeException $e)
		{
			throw new RuntimeException($e->getMessage(), 404);
		}

		if (is_array($groups) && count($groups) > 0)
		{
			foreach ($groups as $n => &$group)
			{
				$group->params = json_decode($group->params);
				// We set an empty default
				if (!is_array($group->params))
				{
					$group->params = $this->getGroupDefaultsAccess();
				}
			}
			return $groups;
		}

		return [];
	}

	/**
	 * Get the group default full access values
	 *
	 * @param   string  $access
	 *
	 * @return array
	 */
	public function getGroupDefaultsAccess(string $access = ''): array
	{
		return [
			(object) [
				'area' => 'user',
				'access' => $access
			],
			(object) [
				'area' => 'usergroup',
				'access' => $access
			],
			(object) [
				'area' => 'menu',
				'access' => $access
			],
			(object) [
				'area' => 'item',
				'access' => $access
			],
		];
	}
}
