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
use Joomla\Database\ParameterType;
use Joomla\Model\DatabaseModelInterface;
use Joomla\Model\DatabaseModelTrait;
use Kumwe\CMS\Date\Date;
use Kumwe\CMS\Model\Util\GetUsergroupsInterface;
use Kumwe\CMS\Model\Util\GetUsergroupsTrait;
use Exception;
use RuntimeException;
use stdClass;

/**
 * Model class
 */
class UserModel implements DatabaseModelInterface, GetUsergroupsInterface
{
	use DatabaseModelTrait, GetUsergroupsTrait;

	/**
	 * @var array
	 */
	public $tempItem;

	/**
	 * Instantiate the model.
	 *
	 * @param   DatabaseDriver|null  $db  The database adapter.
	 */
	public function __construct(DatabaseDriver $db = null)
	{
		$this->setDb($db);
	}

	/**
	 * Add an item
	 *
	 * @param   int     $id
	 * @param   string  $name
	 * @param   string  $username
	 * @param   array   $groups
	 * @param   string  $email
	 * @param   string  $password
	 * @param   int     $block
	 * @param   int     $sendEmail
	 * @param   string  $registerDate
	 * @param   int     $activation
	 *
	 * @return  int
	 * @throws Exception
	 */
	public function setItem(
		int    $id,
		string $name,
		string $username,
		array  $groups,
		string $email,
		string $password,
		int    $block,
		int    $sendEmail,
		string $registerDate,
		int    $activation): int
	{
		$db = $this->getDb();

		$data = [
			'name'         => (string) $name,
			'username'     => (string) $username,
			'email'        => (string) $email,
			'block'        => (int) $block,
			'sendEmail'    => (int) $sendEmail,
			'registerDate' => (string) (empty($registerDate)) ? (new Date())->toSql() : (new Date($registerDate))->toSql(),
			'activation'   => (int) $activation
		];

		// only update password if set
		if (!empty($password) && strlen($password) > 6)
		{
			$data['password'] = (string) $password;
		}

		// if we have ID update
		if ($id > 0)
		{
			$data['id'] = (int) $id;
			// we remove registration date when we update the user
			unset($data['registerDate']);
			// change to object
			$data = (object) $data;

			try
			{
				$db->updateObject('#__users', $data, 'id');
			}
			catch (RuntimeException $exception)
			{
				throw new RuntimeException($exception->getMessage(), 404);
			}
		}
		else
		{
			// we don't have any params for now
			$data['params'] = '';
			// change to object
			$data = (object) $data;

			try
			{
				$db->insertObject('#__users', $data);
			}
			catch (RuntimeException $exception)
			{
				throw new RuntimeException($exception->getMessage(), 404);
			}

			$id = $db->insertid();
		}

		// update the group linked to this user
		// only if there are groups
		if (count($groups) > 0)
		{
			try
			{
				$this->setGroups($id, $groups);
			}
			catch (RuntimeException $exception)
			{
				throw new RuntimeException($exception->getMessage(), 404);
			}
		}

		return $id;
	}

	/**
	 * Add groups for this user
	 *
	 * @param   int    $id
	 * @param   array  $groups
	 *
	 * @return  bool
	 * @throws Exception
	 */
	private function setGroups(int $id, array $groups): bool
	{
		$db = $this->getDb();
		// add the new groups
		$query = $db->getQuery(true)
			->insert($db->quoteName('#__user_usergroup_map'))
			->columns($db->quoteName(['user_id', 'group_id']));
		// Insert values.
		foreach ($groups as $group)
		{
			$query->values(implode(',', [(int) $id, (int) $group]));
		}
		// execute the update/change
		try
		{
			// delete link to groups
			if ($this->deleteGroups($id))
			{
				// add the new groups
				$db->setQuery($query)->execute();
			}
		}
		catch (RuntimeException $e)
		{
			throw new RuntimeException($e->getMessage(), 404);
		}

		return true;
	}

	/**
	 * Get an item
	 *
	 * @param   int|null  $id
	 *
	 * @return stdClass
	 * @throws Exception
	 */
	public function getItem(?int $id): stdClass
	{
		$db = $this->getDb();
		// default object (use posted values if set)
		if (is_array($this->tempItem))
		{
			$default = (object) $this->tempItem;
		}
		else
		{
			$default = new stdClass();
		}
		// to be sure ;)
		$default->today_date = (new Date())->toSql();
		$default->post_key   = "?task=create";
		$default->block      = 0;
		$default->activation = 1;
		$default->sendEmail  = 1;
		// always remove password
		$default->password = 'xxxxxxxxxx';
		$default->password2 = 'xxxxxxxxxx';

		// we return the default if id not correct
		if (!is_numeric($id))
		{
			return $default;
		}

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__users'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER)
			->setLimit(1);

		try
		{
			$result = $db->setQuery($query)->loadObject();
		}
		catch (RuntimeException $e)
		{
			// we ignore this and just return an empty object
		}

		if (isset($result) && $result instanceof stdClass && isset($result->id))
		{
			$result->post_key   = "?id=$id&task=edit";
			$result->today_date = $default->today_date;
			// always remove password
			$result->password = $default->password;
			$result->password2 = $default->password2;

			// Initialise some variables
			$query = $db->getQuery(true)
				->select('m.group_id')
				->from($db->quoteName('#__user_usergroup_map', 'm'))
				->where($db->quoteName('m.user_id') . ' = :user_id')
				->bind(':user_id', $result->id, ParameterType::INTEGER);

			try
			{
				// we just load the ID's
				$result->groups = $db->setQuery($query)->loadColumn();
			}
			catch (RuntimeException $e)
			{
				// we ignore this and just return result
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
	 * @throws Exception
	 */
	public function delete(int $id): bool
	{
		$db = $this->getDb();
		// Delete the user from the database
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__users'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);
		try
		{
			// delete link to groups
			if ($this->deleteGroups($id))
			{
				// delete user
				$db->setQuery($query)->execute();
			}
		}
		catch (RuntimeException $e)
		{
			throw new RuntimeException($e->getMessage(), 404);
		}

		return true;
	}

	/**
	 * delete all groups form this user
	 *
	 * @param   int  $id
	 *
	 * @return  bool
	 * @throws Exception
	 */
	private function deleteGroups(int $id): bool
	{
		$db = $this->getDb();
		// Delete the user from the database
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__user_usergroup_map'))
			->where($db->quoteName('user_id') . ' = :user_id')
			->bind(':user_id', $id, ParameterType::INTEGER);
		try
		{
			$db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			throw new RuntimeException($e->getMessage(), 404);
		}

		return true;
	}
}
