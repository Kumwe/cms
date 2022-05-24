<?php
/**
 * @package    Joomla.Component.Builder
 *
 * @created    30th April, 2015
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @git        Joomla Component Builder <https://git.vdm.dev/joomla/Component-Builder-Pro>
 * @copyright  Copyright (C) 2015 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Utilities;


/**
 * Some array tricks helper
 * 
 * @since  3.0.9
 */
abstract class ArrayHelper
{
	/**
	 * Check if have an array with a length
	 *
	 * @input	array   The array to check
	 *
	 * @returns bool/int  number of items in array on success
	 * 
	 * @since  3.0.9
	 */
	public static function check($array, $removeEmptyString = false)
	{
		if (is_array($array) && ($nr = count((array)$array)) > 0)
		{
			// also make sure the empty strings are removed
			if ($removeEmptyString)
			{
				foreach ($array as $key => $string)
				{
					if (empty($string))
					{
						unset($array[$key]);
					}
				}
				return self::check($array, false);
			}
			return $nr;
		}
		return false;
	}

	/**
	 * Merge an array of array's
	 *
	 * @input	array   The arrays you would like to merge
	 *
	 * @returns array on success
	 * 
	 * @since  3.0.9
	 */
	public static function merge($arrays)
	{
		if(self::check($arrays))
		{
			$arrayBuket = array();
			foreach ($arrays as $array)
			{
				if (self::check($array))
				{
					$arrayBuket = array_merge($arrayBuket, $array);
				}
			}
			return $arrayBuket;
		}
		return false;
	}

}

