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
use Kumwe\CMS\Model\Util\MenuInterface;
use Kumwe\CMS\Model\Util\SelectMenuTrait;
use Kumwe\CMS\Model\Util\UniqueInterface;
use Kumwe\CMS\Model\Util\UniqueMenuAliasTrait;

/**
 * Model class
 */
class MenuModel implements DatabaseModelInterface, UniqueInterface, MenuInterface
{
	use DatabaseModelTrait, UniqueMenuAliasTrait, SelectMenuTrait;

	/**
	 * Active id
	 *
	 * @var int
	 */
	public $id = 0;

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
	 * @param   string  $alias
	 * @param   int     $itemId
	 * @param   string  $path
	 * @param   int     $published
	 * @param   string  $publishUp
	 * @param   string  $publishDown
	 * @param   string  $position
	 * @param   int     $home
	 * @param   int     $parent
	 *
	 * @return  int
	 * @throws \Exception
	 */
	public function setItem(
		int    $id,
		string $title,
		string $alias,
		int    $itemId,
		string $path,
		int    $published,
		string $publishUp,
		string $publishDown,
		string $position,
		int    $home,
		int    $parent): int
	{
		$db = $this->getDb();

		// set the alias if not set
		$alias = (empty($alias)) ? $title : $alias;
		$alias = $this->unique($id, $alias, $parent);
		// set the path
		$path = $this->getPath($alias, $parent);

		$data = [
			'title'        => (string) $title,
			'alias'        => (string) $alias,
			'path'         => (string) $path,
			'item_id'      => (int) $itemId,
			'published'    => (int) $published,
			'publish_up'   => (string) (empty($publishUp)) ? '0000-00-00 00:00:00' : (new Date($publishUp))->toSql(),
			'publish_down' => (string) (empty($publishDown)) ? '0000-00-00 00:00:00' : (new Date($publishDown))->toSql(),
			'home'         => (int) $home,
			'parent_id'    => (int) $parent
		];

		// we set position in params
		$data['params'] = json_encode(['position' => $position]);

		// if we have ID update
		if ($id > 0)
		{
			// set active ID
			$data['id'] = (int) $id;
			$this->id   = (int) $id;
			// change to object
			$data = (object) $data;

			try
			{
				$db->updateObject('#__menu', $data, 'id');
			}
			catch (\RuntimeException $exception)
			{
				throw new \RuntimeException($exception->getMessage(), 404);
			}
		}
		else
		{
			// change to object
			$data = (object) $data;

			try
			{
				$db->insertObject('#__menu', $data);
			}
			catch (\RuntimeException $exception)
			{
				throw new \RuntimeException($exception->getMessage(), 404);
			}

			$id = $db->insertid();
		}

		// check if we have another home set
		if ($data->home == 1)
		{
			$this->setHome($id);
		}

		return $id;
	}

	/**
	 * Get all published items
	 *
	 * @return  array
	 */
	public function getItems(): array
	{
		$db = $this->getDb();

		$query = $db->getQuery(true)
			->select($db->quoteName(array('id', 'title')))
			->from($db->quoteName('#__item'))
			->where('state = 1');

		return $db->setQuery($query)->loadObjectList('id');
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
		}
		// to be sure ;)
		$default->today_date = (new Date())->toSql();
		$default->post_key   = "?task=create";
		$default->published  = 1;
		$default->home       = 0;

		// we return the default if id not correct
		if (!is_numeric($id))
		{
			return $default;
		}

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__menu'))
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
			$result->today_date = $default->today_date;
			// set the position
			$result->params = json_decode($result->params);

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
			->delete($db->quoteName('#__menu'))
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
	 * Make sure that we have only one home
	 *
	 * @param $id
	 *
	 * @return bool
	 */
	private function setHome($id): bool
	{
		$db = $this->getDb();
		// Purge the session
		$query = $db->getQuery(true)
			->update($db->quoteName('#__menu'))
			->set($db->quoteName('home') . ' = 0')
			->where($db->quoteName('id') . ' != :id')
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
	 * get path
	 *
	 * @param   string  $alias
	 * @param   int     $parent
	 *
	 * @return string
	 */
	private function getPath(string $alias, int $parent): string
	{
		// alias bucket
		$bucket = [];
		$bucket[] = $alias;
		$parent = $this->getParent($parent);
		// make sure to get all path aliases TODO: we should limit the menu depth to 6 or something
		while (isset($parent->alias))
		{
			// load the alias
			$bucket[] = $parent->alias;
			// get the next parent
			$parent = $this->getParent($parent->parent_id);
		}
		// now return the path
		return implode('/', array_reverse($bucket));
	}

	/**
	 * get parent
	 *
	 * @param   int     $id
	 *
	 * @return \stdClass
	 */
	private function getParent(int $id): \stdClass
	{
		if ($id > 0)
		{
			$db    = $this->getDb();
			$query = $db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__menu'))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $id)
				->setLimit(1);

			try
			{
				$parent = $db->setQuery($query)->loadObject();
			}
			catch (\RuntimeException $e)
			{
				// we ignore this and just return an empty object
			}

			// return only if found
			if (isset($parent) && $parent instanceof \stdClass)
			{
				return $parent;
			}
		}
		return new \stdClass();
	}
}
