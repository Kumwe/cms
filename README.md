# Content Management System

Content Management of Pages in the Kumwe CMS

# Tutorial

[![](https://git.vdm.dev/Kumwe/cms/raw/branch/master/media/images/tutorial_thumb.jpg "View Tutorial")](https://www.youtube.com/watch?v=43_V9OxUAdE)

## To install this CMS

1. Import the SQL tables into your database found in /sql/install.sql
2. Copy the /config.php.example file to /config.php
3. Update the /config.php to reflect your CMS details
4. Copy the /htaccess.txt file to /.htaccess
5. **Remove** the /installation folder from you root directory

## To install all composer libraries

0. Make sure you have [composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos) installed on your system.
1. In your terminal go to the root folder of your Kumwe website where you will find the composer.json file.
2. Run the following command `composer install` to install all PHP packages.

## To create an account

1. Open [hostname:]/administrator
2. Click on link that says [Create Account] __FIRST account will get admin access, but there rest created will need admin approval__
3. Fill in your details [done]

## To login to admin/staff area again

1. Open [hostname:]/administrator
2. Add you username and password
3. Click login [done]

## To add Items

> Items get linked to menus and are the text of your pages

1. Login to [hostname:]/administrator
2. Click on items menu [hostname:]/administrator/index.php/items
3. Here you can update, delete and create items

## To add menus

> Menus link to items, and mange the menus of your site

1. Login to [hostname:]/administrator
2. Click on menus menu [hostname:]/administrator/index.php/menus
3. Here you can update, delete and create menus (pages) that link to items

## To set site home page

> Home page is the first page you see when you open your public website

1. Inside the menu edit/create view [hostname:]/administrator/index.php/menu
2. You can select one to be the home page

# Just for fun... ((ewɘ))yn

### License & Copyright
- Written by [Llewellyn van der Merwe](https://github.com/Llewellynvdm), March 2022
- Copyright (C) 2022. All Rights Reserved
- License [GNU/GPL Version 2](http://www.gnu.org/licenses/gpl-2.0.html)
