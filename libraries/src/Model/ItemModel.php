<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
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

/**
 * Model class
 */
class ItemModel implements DatabaseModelInterface
{
	use DatabaseModelTrait;

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
	 * @param   string  $introtext
	 * @param   string  $fulltext
	 * @param   int     $state
	 * @param   string  $created
	 * @param   int     $createdBy
	 * @param   string  $createdByAlias
	 * @param   string  $modified
	 * @param   int     $modifiedBy
	 * @param   string  $publishUp
	 * @param   string  $publishDown
	 * @param   string  $metakey
	 * @param   string  $metadesc
	 * @param   string  $metadata
	 * @param   int     $featured
	 *
	 * @return  int
	 * @throws \Exception
	 */
	public function setItem(
		int    $id,
		string $title,
		string $fulltext,
		int    $state,
		string $created,
		int    $createdBy,
		string $createdByAlias,
		string $modified,
		int    $modifiedBy,
		string $publishUp,
		string $publishDown,
		string $metakey,
		string $metadesc,
		string $metadata,
		int    $featured): int
	{
		$db = $this->getDb();

		// extract the intro text
		$introtext = '';
		if (strpos($fulltext, '<p>intro-text</p>'))
		{
			$bucket    = explode('<p>intro-text</p>', $fulltext);
			$introtext = array_shift($bucket);
			$fulltext  = implode('', $bucket);
		}

		$data = [
			'title'            => (string) $title,
			'introtext'        => (string) $introtext,
			'fulltext'         => (string) $fulltext,
			'state'            => (int) $state,
			'created'          => (string) $created,
			'created_by'       => (int) $createdBy,
			'created_by_alias' => (string) $createdByAlias,
			'modified'         => (string) $modified,
			'modified_by'      => (int) $modifiedBy,
			'publish_up'       => (string) (empty($publishUp)) ? '0000-00-00 00:00:00' : (new Date($publishUp))->toSql(),
			'publish_down'     => (string) (empty($publishDown)) ? '0000-00-00 00:00:00' : (new Date($publishDown))->toSql(),
			'metakey'          => (string) $metakey,
			'metadesc'         => (string) $metadesc,
			'metadata'         => (string) $metadata,
			'featured'         => (int) $featured
		];

		// if we have ID update
		if ($id > 0)
		{
			$data['id'] = (int) $id;
			// remove what can not now be set
			unset($data['created']);
			unset($data['created_by']);
			// change to object
			$data = (object) $data;

			try
			{
				$db->updateObject('#__item', $data, 'id');
			}
			catch (\RuntimeException $exception)
			{
				throw new \RuntimeException($exception->getMessage(), 404);
			}

			return $id;

		}
		else
		{
			// remove what can not now be set
			$data['modified']    = '0000-00-00 00:00:00';
			$data['modified_by'] = 0;
			// we don't have any params for now
			$data['params'] = '';
			// change to object
			$data = (object) $data;

			try
			{
				$db->insertObject('#__item', $data);
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
		}
		// to be sure ;)
		$default->today_date = (new Date())->toSql();
		$default->post_key   = "?task=create";
		$default->state      = 1;

		// we return the default if id not correct
		if (!is_numeric($id))
		{
			return $default;
		}

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__item'))
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
			// check if we have intro text we add it to full text
			if (!empty($result->introtext))
			{
				$result->fulltext = $result->introtext . '<p>intro-text</p>' . $result->fulltext;
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
	public function linked(int $id): bool
	{
		$db = $this->getDb();
		// first check if this item is linked to menu
		$query = $db->getQuery(true)
			->select($db->quoteName('title'))
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('item_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		try
		{
			$menu = $db->setQuery($query)->loadResult();
		}
		catch (\RuntimeException $e)
		{
			// not linked... or something
			return false;
		}

		if ($menu)
		{
			return true;
		}

		return false;
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
			->delete($db->quoteName('#__item'))
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
}
