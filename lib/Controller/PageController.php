<?php
namespace OCA\Joplix\Controller;
use OCP\AppFramework\Controller; use OCP\AppFramework\Http\TemplateResponse; use OCP\IRequest; use OCP\IUserSession; use OCP\IGroupManager;
class PageController extends Controller {
 private IUserSession $userSession; private IGroupManager $groupManager;
 public function __construct(string $AppName,IRequest $request,IUserSession $userSession,IGroupManager $groupManager){ parent::__construct($AppName,$request); $this->userSession=$userSession; $this->groupManager=$groupManager; }
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function index(): TemplateResponse { $u=$this->userSession->getUser(); $isAdmin=$u!==null && $this->groupManager->isAdmin($u->getUID()); return new TemplateResponse('joplix','main',['isAdmin'=>$isAdmin]); }
}
