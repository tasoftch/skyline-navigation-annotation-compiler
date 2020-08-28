<?php

namespace Skyline\Navigation;


use Skyline\CMS\Security\Tool\UserTool;
use Skyline\CMS\Security\SecurityTrait;
use TASoft\Service\ServiceManager;

class MenuItemPromise
{
	use SecurityTrait;

	private $menuItem;
	private $accessControl;

	/**
	 * MenuItemPromise constructor.
	 * @param $menuItem
	 */
	public function __construct($menuItem)
	{
		$this->menuItem = $menuItem;
	}

	public function setAccessControl($accessControl) {
		$this->accessControl = $accessControl;
		return $this;
	}

	public function getMenuItem() {
		if(isset($this->accessControl["l"])) {
			if(!$this->getIdentityService()->getIdentityWithReliability($this->getRequest(), $this->accessControl["l"]))
				return NULL;
		}
		if(isset($this->accessControl["t"])) {
			if(!($id = $this->getIdentity()) || !in_array($id->getToken(), $this->accessControl["t"]))
				return NULL;
		}

		if($users = $this->accessControl["u"] ?? []) {
			if($user = $this->getUser()) {
				if($users && !in_array($user->getUsername(), $users))
					return NULL;
			} else
				return NULL;
		}

		if($roles = $this->accessControl["r"] ?? []) {
			/** @var UserTool $uTool */
			$uTool = ServiceManager::generalServiceManager()->get(UserTool::SERVICE_NAME);

			if(!$uTool->hasRoles($roles))
				return NULL;
		}

		if($groups = $this->accessControl["g"] ?? []) {
			/** @var UserTool $uTool */
			$uTool = ServiceManager::generalServiceManager()->get(UserTool::SERVICE_NAME);

			$ok = false;
			foreach($groups as $group) {
				if($uTool->isMember($group)) {
					$ok = true;
					break;
				}
			}
			if(!$ok)
				return NULL;
		}
		
		return $this->menuItem;
	}
}