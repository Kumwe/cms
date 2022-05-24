<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\User;

use Joomla\Registry\Registry;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Kumwe\CMS\Factory;
use Kumwe\CMS\Utilities\StringHelper;
use stdClass;
use Exception;

/**
 * User class.  Handles all application interaction with a user
 *
 * @since  1.0.0
 */
class User extends Registry
{
	/**
	 * Constructor activating the default information of the language
	 *
	 * @param   integer  $identifier  The primary key of the user to load (optional).
	 *
	 * @throws Exception
	 * @since   1.1.0
	 */
	public function __construct($identifier = 0)
	{
		// Load the user if it exists
		if (!empty($identifier))
		{
			$data = $this->load($identifier);
			// not a guest
			$data->guest = 0;
		}
		else
		{
			// Initialise guest
			$data = (object) ['id' => 0, 'sendEmail' => 0, 'block' => 1, 'aid' => 0, 'guest' => 1, 'groups' => []];
		}
		// set the data
		parent::__construct($data);
	}

	/**
	 * Method to load a User object by user id number
	 *
	 * @param   int  $id  The user id of the user to load
	 *
	 * @return stdClass  on success
	 *
	 * @throws Exception
	 * @since   1.0.0
	 */
	protected function load(int $id): stdClass
	{
		// Get the database
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		// Initialise some variables
		$query = $db->getQuery(true)
			->select('u.*')
			->from($db->quoteName('#__users', 'u'))
			->where($db->quoteName('u.id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER)
			->setLimit(1);
		$db->setQuery($query);

		$user = $db->loadObject();

		if ($user instanceof stdClass && isset($user->id))
		{
			// start admin details
			$user->is_admin = false;
			$user->is_admin_groups = [];
			// start access
			$user->access = new stdClass();
			// Initialise some variables
			$query = $db->getQuery(true)
				->select($db->quoteName(array('g.id', 'g.title', 'g.params')))
				->from($db->quoteName('#__user_usergroup_map', 'm'))
				->join('INNER', $db->quoteName('#__usergroups', 'g'), 'g.id = m.group_id')
				->where($db->quoteName('m.user_id') . ' = :user_id')
				->bind(':user_id', $user->id, ParameterType::INTEGER);
			$db->setQuery($query);

			$groups = $db->loadObjectList();

			if (is_array($groups) && count($groups) > 0)
			{
				// group bucket of id's
				$groups_ids = [];
				foreach ($groups as $group)
				{
					// add group ID
					$groups_ids[] = $group->id;
					// convert params to object
					$params = json_decode($group->params);
					// set the access
					if (is_array($params) && count($params) > 0)
					{
						$counter = 0;
						$checker = 0;
						foreach ($params as $param)
						{
							// prep the area string
							$area = StringHelper::safe($param->area);
							// only tart object if not already set
							if(empty($user->access->{$area}))
							{
								// start object
								$user->access->{$area} = new stdClass();
							}
							// make sure we have upper case
							$param->access = strtoupper($param->access);
							// full access to area
							if ($param->access === 'CRUD')
							{
								$checker++;
								// add the full permissions
								$user->access->{$area}->create = true;
								$user->access->{$area}->read = true;
								$user->access->{$area}->update = true;
								$user->access->{$area}->delete = true;
							}
							else
							{
								// this user has fewer permissions
								// set them one at a time
								if (strpos($param->access, 'C') !== false)
								{
									$user->access->{$area}->create = true;
								}
								if (strpos($param->access, 'R') !== false)
								{
									$user->access->{$area}->read = true;
								}
								if (strpos($param->access, 'U') !== false)
								{
									$user->access->{$area}->update = true;
								}
								if (strpos($param->access, 'D') !== false)
								{
									$user->access->{$area}->delete = true;
								}
							}
							$counter++;
						}
						// if this group has full access
						if ($counter == $checker)
						{
							// we need to know when this is an admin user
							$user->is_admin = true;
							// we load the ids to use in user update, so we can prevent
							// admin users from removing themselves from the admin group
							$user->is_admin_groups[] = $group->id;
						}
					}
					unset($group->params);
				}
				// keep the group details
				$user->groups = $groups;
				$user->groups_ids = $groups_ids;
			}

			return $user;
		}
		return (object) ['id' => 0, 'sendEmail' => 0, 'block' => 1, 'aid' => 0, 'guest' => 1, 'groups' => []];
	}
}
