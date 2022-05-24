<?php
/**
 * @package    Kumwe CMS
 *
 * @created    14th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Service;

use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

use Joomla\Input\Input;

/**
 * Input service provider
 */
class InputProvider implements ServiceProviderInterface
{
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container): void
	{
		$container->share(Input::class, [$this, 'getInputClassService'], true);
	}

	/**
	 * Get the Input class service
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  Input
	 */
	public function getInputClassService(Container $container): Input
	{
		return new Input($_REQUEST);
	}
}
