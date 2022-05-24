<?php
/**
 * @package    Kumwe CMS
 *
 * @created    21th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Model;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Model\DatabaseModelInterface;
use Joomla\Model\DatabaseModelTrait;
use Kumwe\CMS\Model\Util\GetUsergroupsInterface;
use Kumwe\CMS\Model\Util\GetUsergroupsTrait;

/**
 * Model class
 */
class UsergroupModel implements DatabaseModelInterface, GetUsergroupsInterface
{
	use DatabaseModelTrait, GetUsergroupsTrait;

	/**
	 * @var array
	 */
	public $tempItem;

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
	 * Add an item
	 *
	 * @param   int     $id
	 * @param   string  $title
	 * @param   array   $params
	 *
	 * @return  int
	 */
	public function setItem(
		int    $id,
		string $title,
		array $params): int
	{
		$db = $this->getDb();

		if (count($params) > 0)
		{
			$params = json_encode($params);
		}
		else
		{
			$params = '';
		}

		$data = [
			'title'   => (string) $title,
			'params'   => (string) $params
		];

		// if we have ID update
		if ($id > 0)
		{
			$data['id'] = (int) $id;
			// change to object
			$data = (object) $data;

			try
			{
				$db->updateObject('#__usergroups', $data, 'id');
			}
			catch (\RuntimeException $exception)
			{
				throw new \RuntimeException($exception->getMessage(), 404);
			}

			return $id;

		}
		else
		{
			// change to object
			$data = (object) $data;

			try
			{
				$db->insertObject('#__usergroups', $data);
			}
			catch (\RuntimeException $exception)
			{
				throw new \RuntimeException($exception->getMessage(), 404);
			}

			return $db->insertid();
		}
	}

	/**
	 * Get an item
	 *
	 * @param   int|null  $id
	 *
	 * @return \stdClass
	 * @throws \Exception
	 */
	public function getItem(?int $id): \stdClass
	{
		$db = $this->getDb();
		// default object (use posted values if set)
		if (is_array($this->tempItem))
		{
			$default = (object) $this->tempItem;
		}
		else
		{
			$default = new \stdClass();
			$default->params = $this->getGroupDefaultsAccess();
		}

		// we return the default if id not correct
		if (!is_numeric($id))
		{
			return $default;
		}

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__usergroups'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER)
			->setLimit(1);

		try
		{
			$result = $db->setQuery($query)->loadObject();
		}
		catch (\RuntimeException $e)
		{
			// we ignore this and just return an empty object
		}

		if (isset($result) && $result instanceof \stdClass)
		{
			$result->post_key   = "?id=$id&task=edit";

			// make sure to set the params
			$result->params = json_decode($result->params);
			// We set an empty default
			if (!is_array($result->params))
			{
				$result->params = $this->getGroupDefaultsAccess();
			}

			return $result;
		}

		return $default;
	}

	/**
	 * @param   string  $name
	 *
	 * @return string
	 */
	public function setLayout(string $name): string
	{
		return $name . '.twig';
	}

	/**
	 * @param   int  $id
	 *
	 * @return bool
	 */
	public function delete(int $id): bool
	{
		$db = $this->getDb();
		// Purge the session
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__usergroups'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);
		try
		{
			$db->setQuery($query)->execute();
		}
		catch (\RuntimeException $e)
		{
			// delete failed
			return false;
		}

		return true;
	}

	/**
	 * @param   int  $id
	 *
	 * @return bool
	 */
	public function linked(int $id): bool
	{
		$db = $this->getDb();
		// first check if this item is linked to menu
		$query = $db->getQuery(true)
			->select($db->quoteName('user_id'))
			->from($db->quoteName('#__user_usergroup_map'))
			->where($db->quoteName('group_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		try
		{
			$users = $db->setQuery($query)->loadColumn();
		}
		catch (\RuntimeException $e)
		{
			// not linked... or something
			return false;
		}

		if ($users)
		{
			return true;
		}

		return false;
	}
}
