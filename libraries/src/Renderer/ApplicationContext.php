<?php
/**
 * Joomla! Framework Website
 *
 * @copyright  Copyright (C) 2014 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace Kumwe\CMS\Renderer;

use Joomla\Application\AbstractApplication;
use Joomla\Application\AbstractWebApplication;
use Symfony\Component\Asset\Context\ContextInterface;

/**
 * Joomla! application aware context
 * source: https://github.com/joomla/framework.joomla.org/blob/master/src/Renderer/ApplicationContext.php
 */
class ApplicationContext implements ContextInterface
{
	/**
	 * Application object
	 *
	 * @var  AbstractApplication
	 */
	private $app;

	/**
	 * Constructor
	 *
	 * @param   AbstractApplication  $app  The application object
	 */
	public function __construct(AbstractApplication $app)
	{
		$this->app = $app;
	}

	/**
	 * Gets the base path.
	 *
	 * @return  string  The base path
	 */
	public function getBasePath()
	{
		return rtrim($this->app->get('uri.base.path'), '/');
	}

	/**
	 * Checks whether the request is secure or not.
	 *
	 * @return  boolean
	 */
	public function isSecure()
	{
		if ($this->app instanceof AbstractWebApplication)
		{
			return $this->app->isSslConnection();
		}

		return false;
	}
}