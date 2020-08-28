<?php
/**
 * Copyright (c) 2020 TASoft Applications, Th. Abplanalp <info@tasoft.ch>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Skyline\Navigation\Compiler;


use Skyline\Compiler\CompilerConfiguration;
use Skyline\Compiler\CompilerContext;
use Skyline\Expose\Compiler\AbstractAnnotationCompiler;

class NavigationAnnotationCompiler extends AbstractAnnotationCompiler
{
	private $menuFile;
	private $mainMenu = [];

	public function __construct(string $compilerID, string $menuFile = "", bool $excludeMagicMethods = true)
	{
		parent::__construct($compilerID, $excludeMagicMethods);
		$this->menuFile = $menuFile;
	}

	private function &_createMenuItem($menuItem, &$menu) {
		$mm = &$this->mainMenu;
		$path = '';

		foreach(explode("/", $menuItem) as $item) {
			if($item) {
				$path .= "/$item";
				if(!isset($mm[$item]['@path'])) {
					$mm[$item] = [
						'@path' => $path
					];
				}
				$menu = &$mm;
				$mm = &$mm[$item];
			}
		}
		return $mm;
	}

	/**
	 * @inheritDoc
	 */
	public function compile(CompilerContext $context)
	{
		$dir = $context->getSkylineAppDirectory(CompilerConfiguration::SKYLINE_DIR_COMPILED);
		if(file_exists("$dir/access-control.php"))
			$accessControl = require "$dir/access-control.php";
		else
			$accessControl = [];

		$separator = 1;
		$canNotInterpret = function($msg) use (&$controller, &$method, $context) {
			$context->getLogger()->logWarning( " ** $msg at $controller::" . $method->getName());
		};

		foreach($this->yieldClasses("ACTIONCONTROLLER") as $controller) {
			$list = $this->findClassMethods($controller, self::OPT_PUBLIC_OBJECTIVE);

			if ($list) {
				foreach ($list as $name => $method) {
					$annots = $this->getAnnotationsOfMethod($method, false);
					if ($annots && isset($annots["route"])) {
						if($menu = @$annots["menu"]) {
							$menuItemOK = false;

							foreach($menu as $m) {
								if(preg_match("/^\s*path\s+/i", $m, $ms)) {
									$m = substr($m, strlen($ms[0]));
									$menuItem = &$this->_createMenuItem($m, $theMenu);
									$menuItemOK = true;
									continue;
								} elseif(preg_match("/^\s*action\s*/i", $m, $ms)) {
									if(!$menuItemOK)
										$canNotInterpret(" ** Can not assign action. Use @menu path first");
									else {
										$action = substr($m, strlen($ms[0]));
										$mn = $method->getName();

										if(!$action) {
											try {
												$action = eval("return $controller::$mn;");
											} catch (\Throwable $exception) {}
										}
										if($action) {
											$menuItem["@action"] = $action;
											$acl = $accessControl[ "$controller::$mn" ] ?? "";
											if($acl)
												$menuItem["@access"] = $acl;
										}
										
									}
								} elseif(preg_match("/^\s*options\s+(\d+)/i", $m, $ms)) {
									if(!$menuItemOK)
										$canNotInterpret(" ** Can not assign options. Use @menu path first");
									else {
										$menuItem["@options"] = $ms[1] * 1;
									}
								} elseif(preg_match("/^\s*separator(?:\s+(before|after)|)\s*$/i", $m, $ms)) {
									if(!$menuItemOK)
										$canNotInterpret(" ** Can not add separator. Use @menu path first");
									else {
										$menuItem["@separator"] = $ms[1] ?: 'before';
									}
								} elseif(preg_match("/^\s*select\s*/i", $m, $ms)) {
									if(!$menuItemOK)
										$canNotInterpret(" ** Can not add select regex. Use @menu path first");
									else {
										$menuItem["@select"] = trim(substr($m, strlen($ms[0])));
									}
								} elseif(preg_match("/^\s*tag\s*(\d+)/i", $m, $ms)) {
									if(!$menuItemOK)
										$canNotInterpret(" ** Can not add tag. Use @menu path first");
									else {
										$menuItem["@tag"] = $ms[1] * 1;
									}
								} else {
									$canNotInterpret(" ** Can not interpret $m ");
								}
							}
						}
					}
				}
			}
		}

		$mapItems = function($menuInfo, &$meta) {
			$items = [];
			foreach($menuInfo as $key => $value) {
				if($key[0] == '@') {
					$meta[substr($key, 1)] = $value;
				} else {
					$items[$key] = $value;
				}
			}
			return $items;
		};

		$createMenu = function($menuName, $menuInfo, &$contents, int $indent = 1) use ($mapItems, &$createMenu, &$separator) {
			$tab = str_repeat("\t", $indent);
			$export = function($value) { return var_export($value, true); };

			$items = $mapItems($menuInfo, $meta);

			if($meta["path"]) {
				$contents .= sprintf("(new Menu(%s, %s))\n$tab", $export($meta['path']), $export($menuName));
				if($items) {
					foreach($items as $itemName => $item) {
						$itemMeta = NULL;
						$subitems = $mapItems($item, $itemMeta);

						if(@$itemMeta["separator"] == 'before')
							$contents .= sprintf("->addItem( (new NavigationBarItem('separator-%d', '-'))\n$tab\t->setSeparatorItem(true) )\n$tab", $separator++);

						if(@$itemMeta["access"])
							$contents .= sprintf("->addItem( (new MenuItemPromise( (new NavigationBarItem(%s, %s))", $export($itemMeta["path"]), $export($itemName));
						else
							$contents .= sprintf("->addItem( (new NavigationBarItem(%s, %s))", $export($itemMeta["path"]), $export($itemName));

						if(@$itemMeta["action"] && @$itemMeta["select"]) {
							$contents .= sprintf("\n$tab\t->setAction( new RegexStringAction(%s, %s) )", $export($itemMeta["action"]), $export($itemMeta["select"]));
						} elseif(@$itemMeta["action"]) {
							$contents .= sprintf("\n$tab\t->setAction( new LiteralStringAction(%s) )", $export($itemMeta["action"]));
						}

						if(@$itemMeta["tag"])
							$contents .= sprintf("\n$tab\t->setTag( %d )", $itemMeta["tag"]);

						if($subitems) {
							$contents .= "\n$tab\t->setSubmenu(\n$tab\t";
							$createMenu($itemName, $item, $contents, $indent+1);
							$contents .= ")\n$tab";
						}

						if(@$itemMeta["access"]) {
							$contents .= sprintf(")\n{$tab})->setAccessControl(unserialize(%s))\n{$tab}->getMenuItem()", $export(serialize($itemMeta["access"])));
						}

						$contents .= ")\n$tab";

						if(@$itemMeta["separator"] == 'after')
							$contents .= sprintf("->addItem( (new NavigationBarItem('separator-%d', '-'))\n$tab\t->setSeparatorItem(true) )\n$tab", $separator++);
					}
				}
			}

		};

		foreach(array_keys($this->mainMenu) as $menuName) {
			$contents = "<?php
use Skyline\Navigation\NavigationBarItem;
use Skyline\Navigation\Menu;
use Skyline\Navigation\MenuItemPromise;
use TASoft\MenuService\Action\LiteralStringAction;
use TASoft\MenuService\Action\RegexStringAction;

return ";
			$createMenu($menuName, $this->mainMenu[$menuName], $contents);

			$contents = trim($contents) . ";";

			file_put_contents("$dir/menu-$menuName.menu.php", $contents);
		}
	}
}