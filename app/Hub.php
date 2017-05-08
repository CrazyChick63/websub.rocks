<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\JsonResponse;
use ORM;
use Config;
use Rocks\Feed;
use p3k\HTTP;
use p3k;

class Hub {

  public function index(ServerRequestInterface $request, ResponseInterface $response) {
    p3k\session_setup();
    
    $response->getBody()->write(view('hub/index', [
      'title' => 'WebSub Rocks!',
    ]));
    return $response;
  }

  public function get_test(ServerRequestInterface $request, ResponseInterface $response, $args) {
    p3k\session_setup();
    $num = $args['num'];



    $response->getBody()->write(view('hub/'.$num, [
      'title' => 'WebSub Rocks!',
      'num' => $num,
    ]));
    return $response;
  }

  // Start a new test
  public function post_start(ServerRequestInterface $request, ResponseInterface $response, $args) {
    p3k\session_setup();
    $num = $args['num'];

    $params = $request->getParsedBody();
  
    // Generate a new token for this test
    $token = p3k\random_string(20);

    $http = new p3k\HTTP(Config::$useragent);
    $client = new p3k\WebSub\Client($http);      

    // If they provided a topic URL, then we first need to discover the hub
    if(isset($params['topic'])) {
      $endpoints = $client->discover($params['topic']);
      if(!$endpoints['hub']) {
        return new JsonResponse([
          'error' => 'missing_hub',
          'error_description' => 'We did not find a rel=hub advertised at the topic provided.'
        ]);
      }
      if(!$endpoints['self']) {
        return new JsonResponse([
          'error' => 'missing_self',
          'error_description' => 'We did not find a rel=self advertised at the topic provided.'
        ]);
      }

      $hub_url = $endpoints['hub'];
      $topic_url = $endpoints['self'];
      $publisher = 'remote';

    } elseif(isset($params['hub'])) {
      // If they did not provide a topic, and are testing an open hub, then we'll set up a new publisher that uses this hub

      $hub_url = $params['hub'];
      $topic_url = Config::$base.'hub/'.$num.'/pub/'.$token;
      $publisher = 'local';

      Feed::set_up_posts_in_feed($token);

    } else {
      return new JsonResponse([
        'error' => 'bad_request'
      ], 400);
    }

    // Store this hub with the token
    // TODO: update the existing hub for this user if they are logged in
    $hub = ORM::for_table('hubs')->create();
    $hub->user_id = is_logged_in() ? $_SESSION['user_id'] : 0;
    $hub->date_created = date('Y-m-d H:i:s');
    $hub->url = $hub_url;
    $hub->token = $token;
    $hub->topic = $topic_url;
    $hub->publisher = $publisher;

    $hub->secret = p3k\random_string(20);

    $hub->save();

    return new JsonResponse([
      'token' => $token,
    ]);
  }  

  // The user triggers the subscription request
  public function post_subscribe(ServerRequestInterface $request, ResponseInterface $response, $args) {
    p3k\session_setup();
    $num = $args['num'];

    $params = $request->getParsedBody();
    $token = $params['token'];

    $hub = ORM::for_table('hubs')->where('token', $token)->find_one();
    if(!$hub) {
      return new JsonResponse(['error'=>'not_found','error_description'=>'No hub was found for this token'], 404);
    }
    $hub_url = $hub->url;
    $topic_url = $hub->topic;

    $http = new p3k\HTTP(Config::$useragent);
    $client = new p3k\WebSub\Client($http);      

    // Start the subscription process at the hub
    $callback = Config::$base.'hub/'.$num.'/sub/'.$token;
    $subscription_params = [];
    if($hub->secret) 
      $subscription_params['secret'] = $hub->secret;
    $subscription = $client->subscribe($hub_url, $topic_url, $callback, $subscription_params);

    if($subscription['code'] == 202) {
      $result = 'Queued';
      $description = 'The hub accepted the subscription request and should now attempt to verify the subscription. After the hub verifies the subscription, the next step will appear below.';
      $status = 'success';
    } else {
      $result = 'Hub Error';
      $description = 'The hub did not accept the subscription request.';
      $status = 'error';
    }

    return new JsonResponse([
      'result' => $result,
      'status' => $status,
      'token' => $token,
      'description' => $description,
      'hub_response' => $subscription['body']
    ]);
  }


  // The hub sends the verification challenge here
  public function get_subscriber(ServerRequestInterface $request, ResponseInterface $response, $args) {
    p3k\session_setup();
    $num = $args['num'];
    $token = $args['token'];

    $hub = ORM::for_table('hubs')->where('token', $token)->find_one();

    if(!$hub) {
      return new JsonResponse(['error'=>'not_found','error_description'=>'No hub was found for this token'], 404);
    }

    $params = $request->getQueryParams();

    // Verify the hub sent the correct challenge

    if(!isset($params['hub_mode'])) {
      return self::verify_error('The verification request was missing the hub.mode parameter');
    }
    if($params['hub_mode'] != 'subscribe') {
      return self::verify_error('The hub.mode parameter was not set to "subscribe"');
    }

    if(!isset($params['hub_topic'])) {
      return self::verify_error('The verification request was missing the hub.topic parameter');
    }
    if($params['hub_topic'] != $hub->topic) {
      return self::verify_error('The hub.topic parameter was incorrect');
    }

    if(!isset($params['hub_challenge'])) {
      return self::verify_error('The verification request was missing the hub.challenge parameter');
    }

    streaming_publish($token, [
      'type' => 'verify_success',
      'description' => 'The hub sent the verification request'
    ]);

    $response->getBody()->write($params['hub_challenge']);
  }

  private static function verify_error($token, $description) {
    streaming_publish($token, [
      'type' => 'verify_error',
      'description' => $description
    ]);
    return new JsonResponse(['error'=>'bad_request','error_description'=>$description], 404);
  }


  // The hub gets the content of the topic here
  public function get_publisher(ServerRequestInterface $request, ResponseInterface $response, $args) {
    p3k\session_setup();
    $num = $args['num'];
    $token = $args['token'];

    $posts = Feed::get_posts_in_feed($token);

    if(!$posts) {
      return new JsonResponse(['error'=>'no_posts'], 404);
    }

    $hub = ORM::for_table('hubs')->where('token', $token)->find_one();

    if(!$hub) {
      return new JsonResponse(['error'=>'not_found'], 404);
    }

    $self_url = Config::$base.'hub/'.$num.'/pub/'.$token;
    $hub_url = $hub->url;


    $response = $response
      ->withHeader('Link', '<'.$self_url.'>; rel="self"')
      ->withAddedHeader('Link', '<'.$hub_url.'>; rel="hub"');

    $response->getBody()->write(view('hub/feed', [
      'title' => 'WebSub Rocks!',
      'num' => $num,
      'token' => $token,
      'posts' => $posts,
      'link_tag' => '',
    ]));
    return $response;
  }

  // For public hubs, the user will trigger a new post be added here
  public function post_publisher(ServerRequestInterface $request, ResponseInterface $response, $args) {
    p3k\session_setup();
    $num = $args['num'];
    $token = $args['token'];

    $hub = ORM::for_table('hubs')->where('token', $token)->find_one();

    if(!$hub) {
      return new JsonResponse(['error'=>'not_found','error_description'=>'No hub was found for this token'], 404);
    }

    $posts = Feed::get_posts_in_feed($token);
    $ids = array_column($posts, 'id');
    $post = ORM::for_table('quotes')
      ->where_not_in('id', $ids)->order_by_expr('RAND()')
      ->limit(1)->find_one();

    // Add a new post to the blog
    $data = Feed::add_post_to_feed($token, $post);

    // Notify the hub of new content
    $http = new p3k\HTTP(Config::$useragent);
    $http->post($hub->url, http_build_query([
      'hub.mode' => 'publish',
      'hub.topic' => $hub->topic,
    ]));

    return new JsonResponse([
      'result' => 'published'
    ]);
  }

  // a WebSub delivery notification
  public function post_subscriber(ServerRequestInterface $request, ResponseInterface $response, $args) {
    p3k\session_setup();
    $num = $args['num'];
    $token = $args['token'];

    $hub = ORM::for_table('hubs')->where('token', $token)->find_one();

    if(!$hub) {
      return new JsonResponse(['error'=>'not_found','error_description'=>'No hub was found for this token'], 404);
    }

    $http = new p3k\HTTP(Config::$useragent);

    // Fetch the topic URL so we know what the notification should look like
    $topic = $http->get($hub->topic);

    // Check for notification payload
    $notification_body = $request->getBody()->__toString();

    if(trim($notification_body) == '') {
      streaming_publish($token, [
        'type' => 'notification',
        'error' => 'empty_payload',
        'description' => 'The notification body did not include any content. Make sure the hub sends the contents of the topic URL in the notification payload. This is known as a "fat ping".'
      ]);
      return $response;
    }

    // Make sure it matches what's expected
    if($hub->publisher == 'remote') {
      // Allow slight differences in the body for remote feeds
      // in order to allow cookie/csrf/other per-request differences
      similar_text($notification_body, $topic['body'], $percent);
      $invalid = $percent < 5;
    } else {
      $invalid = ($notification_body != $topic['body']);
    }
    if($invalid) {
      streaming_publish($token, [
        'type' => 'notification',
        'error' => 'body_mismatch',
        'description' => 'The notification body did not match the contents of the topic URL.',
      ]);
      return $response;
    }

    $content_type_debug = 'Topic Content-Type: '.$topic['headers']['Content-Type']."\n"
      . "Content-Type sent:  ".$request->getHeaderLine('Content-type')."\n";

    // Make sure they sent a content type header that matches the source
    if($request->getHeaderLine('Content-Type') != $topic['headers']['Content-Type']) {
      streaming_publish($token, [
        'type' => 'notification',
        'error' => 'content_type_mismatch',
        'description' => 'The content-type of the notification sent did not match the content-type of the topic URL. The hub must send a content-type header that matches the topic URL.',
        'debug' => $content_type_debug
      ]);
      return $response;
    }

    // Check for presence of or absence of signature
    $sent_signature = $request->getHeaderLine('X-Hub-Signature');
    $signature_debug = '';

    if($hub->secret == '') {
      // Make sure the hub did not send a signature
      if($sent_signature) {
        streaming_publish($token, [
          'type' => 'notification',
          'error' => 'signature',
          'description' => 'The hub sent a signature, but the subscriber was not expecting one.'
        ]);
        return $response;
      }
    } else {
      // Check that the hub sent a signature
      if(!$sent_signature) {
        streaming_publish($token, [
          'type' => 'notification',
          'error' => 'signature',
          'description' => 'The hub did not send a signature, but the subscriber sent a secret during the subscription process. Hubs must support sending a signature when the subscription was made with a secret.'
        ]);
        return $response;
      }

      // Compute the signature and make sure it matches what the hub sent
      $verified = p3k\WebSub\Client::verify_signature($notification_body, $sent_signature, $hub->secret);
      $signature_debug = "Signature: ".$sent_signature."\n";
      if(!$verified) {
        streaming_publish($token, [
          'type' => 'notification',
          'error' => 'signature_mismatch',
          'description' => 'The signature sent by the hub did not match what we expected. Check that you are using a valid hashing algorithm and computing the signature correctly.',
          'debug' => $signature_debug
        ]);
        return $response;
      }
    }

    streaming_publish($token, [
      'type' => 'notification',
      'error' => false,
      'description' => 'Great! Your hub sent a valid WebSub notification payload to the subscriber!',
      'debug' => $content_type_debug.$signature_debug
    ]);
    return $response;
  }


}

