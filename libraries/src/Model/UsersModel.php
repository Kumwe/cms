<?php
/**
 * @package    Kumwe CMS
 *
 * @created    18th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Model;

use Joomla\Database\DatabaseDriver;
use Joomla\Model\DatabaseModelInterface;
use Joomla\Model\DatabaseModelTrait;
use Kumwe\CMS\Model\Util\GetUsergroupsInterface;
use Kumwe\CMS\Model\Util\GetUsergroupsTrait;

/**
 * Model class
 */
class UsersModel implements DatabaseModelInterface, GetUsergroupsInterface
{
	use DatabaseModelTrait, GetUsergroupsTrait;

	/**
	 * Instantiate the model.
	 *
	 * @param   DatabaseDriver  $db  The database adapter.
	 */
	public function __construct(DatabaseDriver $db)
	{
		$this->setDb($db);
	}

	/**
	 * Get all items
	 *
	 * @return  array
	 */
	public function getItems(): array
	{
		$db = $this->getDb();

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__users'));

		$users = $db->setQuery($query)->loadObjectList('id');

		// add groups
		if ($users)
		{
			foreach ($users as $id => &$user)
			{
				$user->groups = $this->getUsergroups($id);
			}
		}

		return $users;
	}

	public function setLayout(string $name): string
	{
		return $name . '.twig';
	}
}
