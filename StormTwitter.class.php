<?php
/*
* Version 2.0.3
* The base class for the storm twitter feed for developers.
* This class provides all the things needed for the wordpress plugin, but in theory means you don't need to use it with wordpress.
* What could go wrong?
*/

require_once('oauth/twitteroauth.php');

class StormTwitter {

  private $defaults = array(
    'directory' => '',
    'key' => '',
    'secret' => '',
    'token' => '',
    'token_secret' => '',
    'screenname' => '',
    'cache_expire' => 3600      
  );
  
  public $st_last_error = false;
  
  function __construct($args = array()) {
    $this->defaults = array_merge($this->defaults, $args);
  }
  
  function __toString() {
    return print_r($this->defaults, true);
  }
  

  function getTweet($id) {
    $key = $this->defaults['key'];
    $secret = $this->defaults['secret'];
    $token = $this->defaults['token'];
    $token_secret = $this->defaults['token_secret'];
    
    $cachename = $id;
        
    if (empty($key)) return array('error'=>'Missing Consumer Key - Check Settings');
    if (empty($secret)) return array('error'=>'Missing Consumer Secret - Check Settings');
    if (empty($token)) return array('error'=>'Missing Access Token - Check Settings');
    if (empty($token_secret)) return array('error'=>'Missing Access Token Secret - Check Settings');
    
    $connection = new TwitterOAuth($key, $secret, $token, $token_secret);
    $result = $connection->get(
    'https://api.twitter.com/1.1/statuses/show.json',
	array(
		'id'        => $id,
		'lang'		=> "de"
	),
  	true
    );
    
    if (is_file($this->getCacheLocation())) {
      $cache = json_decode(file_get_contents($this->getCacheLocation()),true);
    }
    
    if (!isset($result['errors'])) {
      $cache[$cachename]['time'] = time();
      $cache[$cachename]['tweets'] = $result;
      $file = $this->getCacheLocation();
      file_put_contents($file,json_encode($cache));
    } else {
      if (is_array($results) && isset($result['errors'][0]) && isset($result['errors'][0]['message'])) {
        $last_error = '['.date('r').'] Twitter error: '.$result['errors'][0]['message'];
        $this->st_last_error = $last_error;
      } else {
        $last_error = '['.date('r').'] Twitter returned an invalid response. It is probably down.';
        $this->st_last_error = $last_error;
      }
    }
    return $result;
  
  }
    
  
  private function getCacheLocation() {
    return $this->defaults['directory'].'.tweetcache';
  }
    
  private function checkValidCache($screenname,$options) {
    $file = $this->getCacheLocation();
    if (is_file($file)) {
      $cache = file_get_contents($file);
      $cache = @json_decode($cache,true);
      
      if (!isset($cache)) {
        unlink($file);
        return false;
      }
      
      // Delete the old cache from the first version, before we added support for multiple usernames
      if (isset($cache['time'])) {
        unlink($file);
        return false;
      }
      
      $cachename = $screenname."-".$this->getOptionsHash($options);
      
      //Check if we have a cache for the user.
      if (!isset($cache[$cachename])) return false;
      
      if (!isset($cache[$cachename]['time']) || !isset($cache[$cachename]['tweets'])) {
        unset($cache[$cachename]);
        file_put_contents($file,json_encode($cache));
        return false;
      }
      
      if ($cache[$cachename]['time'] < (time() - $this->defaults['cache_expire'])) {
        $result = $this->oauthGetTweets($screenname,$options);
        if (!isset($result['errors'])) {
          return $result;
        }
      }
      return $cache[$cachename]['tweets'];
    } else {
      return false;
    }
  }
  
}  
