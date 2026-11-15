<?php
namespace OCA\Joplix\Controller;
use OCA\Joplix\AppInfo\Application;
use OCP\AppFramework\Controller; use OCP\AppFramework\Http\TemplateResponse; use OCP\AppFramework\Http\ContentSecurityPolicy; use OCP\IRequest; use OCP\IUserSession; use OCP\IGroupManager;
class PageController extends Controller {
 private IUserSession $userSession; private IGroupManager $groupManager;
 public function __construct(string $AppName,IRequest $request,IUserSession $userSession,IGroupManager $groupManager){ parent::__construct($AppName,$request); $this->userSession=$userSession; $this->groupManager=$groupManager; }
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function index(): TemplateResponse {
  // Keeps Chrome and Edge from offering to translate the page, which would also rewrite the rendered note.
  \OCP\Util::addHeader('meta',['name'=>'google','content'=>'notranslate']);
  $u=$this->userSession->getUser(); $isAdmin=$u!==null && $this->groupManager->isAdmin($u->getUID()); $response=new TemplateResponse(Application::APP_ID,'main',['isAdmin'=>$isAdmin]);
  // Attachment previews play audio and video from blob: URLs built in the browser (js/app.js previewResource);
  // Nextcloud's default policy only allows media from 'self'.
  $csp=new ContentSecurityPolicy(); $csp->addAllowedMediaDomain('blob:'); $response->setContentSecurityPolicy($csp);
  return $response; }
}
