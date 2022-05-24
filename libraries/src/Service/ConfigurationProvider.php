<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Service;

use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Registry\Registry;

/**
 * Configuration service provider
 */
class ConfigurationProvider implements ServiceProviderInterface
{
	/**
	 * Configuration instance
	 *
	 * @var  Registry
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param   string  $file  Path to the config file.
	 *
	 * @throws  \RuntimeException
	 */
	public function __construct(string $file)
	{
		// Verify the configuration exists and is readable.
		if (!is_readable($file))
		{
			throw new \RuntimeException('Configuration file does not exist or is unreadable.');
		}

		// load the class
		include_once $file;
		$this->config = new Registry(new \LConfig());

		// Set database values based on config values
		$this->config->loadObject( (object) [
		    'database' => [
				'driver' => $this->config->get('dbtype'),
				'host' => $this->config->get('host'),
				'port' => $this->config->get('port', ''),
				'user' => $this->config->get('user'),
				'password' => $this->config->get('password'),
				'database' => $this->config->get('db'),
				'prefix' => $this->config->get('dbprefix')
			]
		]);
	}

	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container): void
	{
		$container->share(
			'config',
			function (): Registry
			{
				return $this->config;
			},
			true
		);
	}
}
