<?php

namespace Skyline\Navigation;


use TASoft\MenuService\MenuItemInterface;

class Menu extends \TASoft\MenuService\Menu
{
	public function addItem(MenuItemInterface $item = NULL)
	{
		if($item)
			parent::addItem($item);
		return $this;
	}
}