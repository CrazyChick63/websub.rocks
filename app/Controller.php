<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ORM;
use IndieAuth;
use Config;
use p3k;

class Controller {

  private function _redirectURI() {
    return Config::$base.'endpoints/callback';
  }

  public function index(ServerRequestInterface $request, ResponseInterface $response) {
    p3k\session_setup();
    
    $response->getBody()->write(view('index', [
      'title' => 'WebSub Rocks!',
    ]));
    return $response;
  }

  public function implementation_reports(ServerRequestInterface $request, ResponseInterface $response) {
    return $response->withHeader('Location', 'https://github.com/w3c/websub/tree/master/implementation-reports')->withStatus(302);
  }

}
