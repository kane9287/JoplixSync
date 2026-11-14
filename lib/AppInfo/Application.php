<?php
namespace OCA\Joplix\AppInfo;
use OCP\AppFramework\App;
class Application extends App { public const APP_ID='joplix'; public function __construct(array $urlParams=[]){ parent::__construct(self::APP_ID,$urlParams); } }
