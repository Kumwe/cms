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
use Joomla\Model\DatabaseModelInterface;
use Joomla\Model\DatabaseModelTrait;
use Kumwe\CMS\Model\Util\MenuInterface;
use Kumwe\CMS\Model\Util\PageInterface;
use Kumwe\CMS\Model\Util\HomeMenuInterface;
use Kumwe\CMS\Model\Util\HomeMenuTrait;
use Kumwe\CMS\Model\Util\SiteMenuTrait;
use Kumwe\CMS\Model\Util\SitePageTrait;

/**
 * Model class
 */
class PageModel implements DatabaseModelInterface, MenuInterface, PageInterface, HomeMenuInterface
{
	use DatabaseModelTrait, HomeMenuTrait, SiteMenuTrait, SitePageTrait;

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
     * Method to get all date needed for the view
     *
     * @param string|null $page
     *
     * @return  array  The data needed
     */
    public function getData(?string $page): array
    {
        // set the defaults
        $data = (object) [
            // main title
            'title' => 'Error',
            // menus
            'menus' => [],
            // menu ID
            'menu_id' => 0,
            // is this the home page
            'menu_home' => false,
            // home page title
            'home_menu_title' => 'Home'
        ];
        // we check if we have a home page
        $home_page = $this->getHomePage();
        // get the page data
        if (empty($page) && isset($home_page->item_id) && $home_page->item_id > 0)
        {
            // this is the home menu
            $data = $this->getPageItemById($home_page->item_id);
            $data->menu_home = true;
        }
        elseif (!empty($page))
        {
            $data = $this->getPageItemByPath($page);
        }
        // load the home menu title
        if (isset($home_page->title))
        {
            $data->home_menu_title = $home_page->title;
        }
        // check if we found any data
        if (isset($data->id))
        {
            // check if we have intro text we add it to full text
            if (!empty($data->introtext))
            {
                $data->fulltext = $data->introtext . $data->fulltext;
            }
        }

        // set the menus if possible
        if (isset($data->menu_id) && $data->menu_id > 0)
        {
            $data->menus = $this->getMenus($data->menu_id);
        }

        return (array) $data;
    }
}
