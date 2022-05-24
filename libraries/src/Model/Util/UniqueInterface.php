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

/**
 * Class for getting unique string
 *
 * @since  1.0.0
 */
interface  UniqueInterface
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
	public function unique(int $id, string $value, int $parent = -1, string $key = 'alias', string $spacer = '-'): string;

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
	public function exist(int $id, string $value, string $key = 'alias', int $parent = -1): bool;
}
