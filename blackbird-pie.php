<?php
/*
Plugin Name: Blackbird Pie 2013 & oAuth
Plugin URI: http://themergency.com/plugins/twitter-blackbird-pie/
Description: Add embedded tweets to your site. Includes oAuth Twitter Feed for Developers by Storm Consultancy (Liam Gladdy)
Version: 0.5.4
Author: Felix Schwenzel & Brad Vincent & Storm Consultancy (Liam Gladdy)
Author URI: http://themergency.com
License: GPL2
*/

require('StormTwitter.class.php');
require('twitter-feed-for-developers-settings.php');

function getTweet($id = '') {

  $config['key'] = get_option('tdf_consumer_key');
  $config['secret'] = get_option('tdf_consumer_secret');
  $config['token'] = get_option('tdf_access_token');
  $config['token_secret'] = get_option('tdf_access_token_secret');
  $config['screenname'] = get_option('tdf_user_timeline');
  $config['cache_expire'] = intval(get_option('tdf_cache_expire'));
  if ($config['cache_expire'] < 1) $config['cache_expire'] = 3600;
  $config['directory'] = plugin_dir_path(__FILE__);
  
  $obj = new StormTwitter($config);
  $res = $obj->getTweet($id);
  update_option('tdf_last_error',$obj->st_last_error);
  return $res;
  
}

class BlackbirdPie {

    /**
     * Stores the Twitter handles for the users on the current blog.
     */
    var $handles = array();

    //constructor
    function BlackbirdPie() {
        define( 'BBP_NAME',  'blackbirdpie' );
        define( 'BBP_REGEX', '/^(http|https):\/\/twitter\.com\/(?:#!\/)?(\w+)\/status(es)?\/(\d+)$/' );
        define( 'BBP_DIR', plugin_dir_path( __FILE__ ) );
        define( 'BBP_URL', plugins_url( '/', __FILE__ ) );

        if(!class_exists('WP_Http'))
            include_once(ABSPATH . WPINC . '/class-http.php');

        //register shortcode
        add_shortcode(BBP_NAME, array(&$this, 'shortcode'));
        //register auto embed
        wp_embed_register_handler( BBP_NAME, BBP_REGEX, array(&$this, 'blackbirdpie_embed_handler'), 10 );

        add_action( 'wp_head', array( &$this, 'embed_head'), -1 );
        
        //setup twitter contact info in my profile screen
        add_filter( 'user_contactmethods', array(&$this, 'twitter_contactmethod'), 10, 1 );

        //setup WYSIWYG editor
        $this->add_editor_button();
    }

    function twitter_contactmethod( $contactmethods ) {
        if ( empty( $contactmethods['twitter'] ) ) {
            // Add Twitter
            $contactmethods['twitter'] = 'Twitter';
        }

        return $contactmethods;
    }

    /**
     * Insert the neccessary Twitter JavaScript and CSS into HTML <head> if posts with twitter links exist.
     * If tweets are present then queue the blackbird pie code.
     *
     * original code from twitter-blackbird-pie WordPress.com plugin
     */
    function embed_head() {
        global $posts;

        if ( is_feed() || !is_array( $posts ) )
            return;

        $load = false;
        foreach ( $posts as $post ) {

            //first check if the post contains a blackbirdpie shortcode
            if ( strpos( $post->post_content, '[blackbirdpie' ) >= 0 ) {
                $load = true;
                break;
            }
            else
            //then check if the post contains a twitter link
            if (  preg_match( '/(\n|\A)http(s|):\/\/twitter\.com(\/\#\!\/|\/)([a-zA-Z0-9_]{1,20})\/status(es)*\/(\d+)(\/|)/i', $post->post_content ) ) {
                $load = true;
                break;
            }
        }

        if ( $load ) {
            add_action( 'wp_enqueue_scripts', array( &$this, 'load_scripts'), 20 );
            add_action( 'wp_print_styles', array( &$this, 'load_styles'), 20 );
        }

        return;
    }

    /**
     * Loads the javascript needed by Twitter Blackbird Pie for loading the reply, retweet and favorite scripts
     *
     * original code from twitter-blackbird-pie WordPress.com plugin
     */
    function load_scripts() {
        wp_register_script( BBP_NAME . '-js', BBP_URL . 'js/blackbirdpie.js',  array(), '20110404' );
        wp_enqueue_script( BBP_NAME . '-js' );
    }

    /**
     * Loads the CSS needed by Twitter Blackbird Pie for the CSS sprites
     *
     * original code from twitter-blackbird-pie WordPress.com plugin
     */
    function load_styles() {
        wp_register_style( BBP_NAME . '-css', BBP_URL . 'css/blackbirdpie.css',  array(), '20110416' );
        wp_enqueue_style( BBP_NAME . '-css' );
		wp_enqueue_style	( 'font-awesome', BBP_URL . 'css/font-awesome.css' );
		wp_enqueue_style	( 'font-awesome-ie7', BBP_URL . 'css/font-awesome-ie7.css' );
    }
	
    function add_editor_button() {
        // Don't bother doing this stuff if the current user lacks permissions
        if ( ! current_user_can('edit_posts') && ! current_user_can('edit_pages') )
            return;

        // Add only in Rich Editor mode
        if ( get_user_option('rich_editing') == 'true' ) {
            add_filter( 'mce_external_plugins', array(&$this, 'add_myplugin_tinymce_plugin') );
            add_filter( 'teeny_mce_buttons', array(&$this, 'register_myplugin_button') );
            add_filter( 'mce_buttons', array(&$this, 'register_myplugin_button') );
        }
    }
	
    function register_myplugin_button($buttons) {
        array_push($buttons, 'separator', BBP_NAME);
        return $buttons;
    }
	 
    // Load the TinyMCE plugin : editor_plugin.js (wp2.5)
    function add_myplugin_tinymce_plugin($plugin_array) {
        $plugin_array[BBP_NAME] = BBP_URL . 'tinymce/editor_plugin_blackbirdpie.js';
        return $plugin_array;
    }
	
    /*
     * Calculates a textual representation of how long ago a date was.
     * Supports "less than a minute ago", "1 minute ago or x minutes ago", and "about 1 hour ago" or "about x hours ago".
     * If the date is older then this then we just display the date format according to the WordPress blog
     *
     * Based on ago() from Blackbird Pie v 0.3.2 and http://www.php.net/manual/en/function.time.php#96097
     *
     * code from WordPress.com blackbird pie plugin
     *
     * @param string $date The past date we are calculting the text string for
     * @return string The textual representation of the date
     */
    function how_long_ago( $date ) {
        $current = time();
        $difference = $current - $date;

        if ( strtotime( '-1 min', $current ) < $date)
            $output = 'less than a minute ago';
        elseif ( strtotime( '-1 hour', $current ) < $date )
            $output = ( floor($difference / 60 ) == 1 ) ? '1 minute ago' : floor( $difference / 60 ) . ' minutes ago';
        elseif ( strtotime( '-1 day', $current ) < $date )
            $output = ( floor( $difference / 60 / 60 ) == 1 ) ? 'about 1 hour ago' : 'about ' . floor( $difference / 60 / 60 ) . ' hours ago';
        else
            $output = date( get_option('date_format') . ' ' . get_option( 'time_format' ), ( $date + ( get_option('gmt_offset') * 3600 ) ) );

        return $output;
    }
	
    function shortcode( $atts ) {

        // Extract the attributes
        extract(shortcode_atts(array(
            "id" => false,
            "url" => false,
            "width" => false,
            "user" => false
              ), $atts));
		
        return $this->render_tweet( $id, $url, $user, $width );
    }
 
	function render_tweet( $id, $url, $user, $width ) {
	
        //extract the status ID from $id (incase someone incorrectly used a shortcode like [blackbirdpie id="http://twitter..."])
        if ($id) {
            if (preg_match(BBP_REGEX, $id, $matches)) {
                $id = $matches[4];
            }
        }

        //extract the status ID from $url
        if ($url) {
            if (preg_match(BBP_REGEX, $url, $matches)) {
                $id = $matches[4];
            }
        }

        if ($id) {

            //are we inside the loop?
            global $wp_query;
            if ($wp_query->in_the_loop) {
                global $post;
                $post_id = $post->ID;
            }

            if ($post_id > 0) {
                //try and get the tweet data from the post
                $args = get_post_meta( $post_id, '_'.BBP_NAME.'-'.$id );
            }

            if ( empty($args) ) {
                //we need to get the tweet json data from twitter API
                $data = $this->get_tweet_details($id);

                if ( !empty($data->text) ) {

					// real urls instead of t.co stuff
					if ( count( $data->entities->urls ) ) {
						foreach ( $data->entities->urls as $url ) {
							$data->text = str_replace( $url->url, '<a href="'.$url->expanded_url.'">'.$url->display_url.'</a>', $data->text );
						}
					}
					// media url
					if ( ( $data->entities->media['0']->media_url != "" ) ) {
							$data->text = str_replace( $data->entities->media['0']->url, '<a href="'.$data->entities->media['0']->expanded_url.'">'.$data->entities->media['0']->display_url.'</a>', $data->text );
					}

                    //fix for non english tweets
					$data->text = stripslashes(BlackbirdPie::autolink($data->text));
					$data->text = preg_replace("/(\r\n)|(\r)|(\n)/","<br />", $data->text);
					$data->text = BlackbirdPie::UTF8entities($data->text);
	
					$data->user->screen_name = addslashes(BlackbirdPie::UTF8entities($data->user->screen_name));
					$data->user->name = addslashes(BlackbirdPie::UTF8entities($data->user->name));

                    $timeStamp = strtotime($data->created_at);
                    
					$media_url = $data->entities->media['0']->media_url;
					if (($media_url != "") AND ($data->entities->media['0']->type == "photo")) {
                
        	        $media = "<div class='tw-media' style='display:block;clear:both;padding-top:10px;'>
            	    <img src='{$media_url}' style='max-width:100%' />
                	</div>";
					}
					else {
						$media = "";
					}
                    

                    $args = array(
                        'id' => $id,
                        'screen_name' => stripslashes($data->user->screen_name),
                        'real_name' => stripslashes($data->user->name),
//                        'tweet_text' => $data->text,
//                        'tweet_text' => stripslashes($this->autolink($data->text)),
                        'tweet_text' => stripslashes($data->text),
                        'source' => $data->source,

                        'profile_pic' => $data->user->profile_image_url,
                        'profile_bg_color' => $data->user->profile_background_color,
                        'profile_bg_tile' => $data->user->profile_background_tile,
                        'profile_bg_image' => $data->user->profile_background_image_url,
                        'profile_text_color' => $data->user->profile_text_color,
                        'profile_link_color' => $data->user->profile_link_color,
						'profile_use_background_image' => $data->user->profile_use_background_image,
						'media_code' => $media,
                        'time_stamp' => $timeStamp,
                        'utc_offset' => $data->user->utc_offset
                    );

                    // save the tweet JSON data into a custom field
                    if ($post_id > 0) {
                        update_post_meta($post_id, '_'.BBP_NAME.'-'.$id, $args);
                    }
                } //endif http_code == "200"
                else {
                    return 'There was a problem connecting to Twitter.';
                }
            } //endif $args is set
            else {
                $args = $args[0];
            }
            
            if ( !has_filter('bbp_create_tweet') )
                add_filter('bbp_create_tweet', array( &$this, 'create_tweet_html' ));

            return apply_filters('bbp_create_tweet', $args);
        }

        return 'There was a problem with the blakbirdpie shortcode';		
	}
 
    function create_tweet_html( $tweet_details, $options = array()) {
        global $post;
        
        /* PROFILE DATA */
        $name = $tweet_details['screen_name'];                      //the twitter username
        $real_name = $tweet_details['real_name'];                   //the user's real name
        $profile_pic = esc_url($tweet_details['profile_pic']);      //url to the profile image
        if ( !$tweet_details['profile_bg_tile'] ) 
            $profile_bg_tile_HTML = " background-repeat:no-repeat"; //profile background tile
        $profile_link_color = $tweet_details['profile_link_color']; //link color
        $profile_text_color = $tweet_details['profile_text_color']; //text color
        $profile_bg_color = $tweet_details['profile_bg_color'];     //background color
		if ($tweet_details['profile_use_background_image']) {
	        $profile_bg_image = esc_url($tweet_details['profile_bg_image']);     //background image
		}
        $profile_url = esc_url("http://twitter.com/intent/user?screen_name={$name}"); //the URL to the twitter profile

        /* GENERAL INFO */
        $id = $tweet_details['id'];                                     //id of the actual tweet
        $url = esc_url( "http://twitter.com/{$name}/status/{$id}" ); //the URL to the tweet on twitter.com

        /* TIME INFO */
        $time = $tweet_details['time_stamp'];                       //the time of the tweet
        $date = date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 
                $time + ( get_option('gmt_offset') * 3600 )  );     //the local time based on the GMT offset
        $time_ago = $this->how_long_ago( $time );                   //the friendly version of the time e.g. "1 minute ago"

        /* SOURCE of the tweet */
        $source = $tweet_details['source'];
        preg_match( '`<a href="(http(s|)://[\w#!$&+,\/:;=?@.-]+)[^\w#!$&+,\/:;=?@.-]*?" rel="nofollow">(.*?)</a>`i', $source, $matches );
        if( ! empty( $matches[1] ) || ! empty( $matches[3]) ) {
            $source = '<a href="' . esc_url( $matches[1] ). '" rel="nofollow" target="blank">' . esc_html( $matches[3] ) . '</a>';
            $source_short = esc_html( $matches[3] );
        }
        else
            $source = esc_html( $source );

        //the plugin's base URL
        $base_url = BBP_URL;

        // Tweet Action Urls
		$emty = "emty";
        $retweet_url = esc_url( "https://twitter.com/intent/retweet?tweet_id={$id}" );
        $reply_url = esc_url( "https://twitter.com/intent/tweet?in_reply_to={$id}" );
        $media_code = $tweet_details['media_code'];
        $favorite_url = esc_url( "https://twitter.com/intent/favorite?tweet_id={$id}" );
        $tweet = $tweet_details['tweet_text'];
        
        //thanks to beezeee for this code
        $handle = get_user_meta( $post->post_author, 'twitter', true );
        // If we have a Twitter handle for this post author then we can mark them as 'related' to this tweet
        if ( $handle and trim($handle) != '' ) {
          $retweet_url .= "&related=" . $handle;
          $reply_url .= "&related=" . $handle;
          $favorite_url .= "&related=" . $handle;
        }

		
        $tweetHTML = "<style type='text/css'>
            #bbpBox_$id a { text-decoration:none; color:#{$profile_link_color}; }
            #bbpBox_$id a:hover { text-decoration:underline !important; background-color:transparent !important; }
        </style>
        <div id='bbpBox_$id' class='bbpBox' style='border:1px solid #e6e6e6;padding:20px; margin:5px 0; background-color:#{$profile_bg_color}; background-image:url({$profile_bg_image});{$profile_bg_tile_HTML}'>
            <div style='background:#fff; padding:10px; margin:0; min-height:48px; color:#{$profile_text_color}; color:#333; -moz-border-radius:5px; -webkit-border-radius:5px;'>

				<div style='margin-bottom:15px;'>
                <div style='float:left; padding:0; margin:0;'>
                    <a href='{$profile_url}'>
                        <img style='width:48px; height:48px; padding-right:7px; border:none; background:none; margin:0' src='{$profile_pic}' />
                    </a>
                </div>
                <div style='float:left; padding:0; margin:0'>
                    <a style='font-weight:bold;font-size:20px;line-height:24px;font-family:Helvetica Neue,Helvetica,Arial,Sans-serif;' href='{$profile_url}'>{$real_name}</a>
                    <div style='margin:0; padding-top:2px;font-size:14px;font-family:Helvetica Neue,Helvetica,Arial,Sans-serif;color:#999999;'>@{$name}</div>
                </div>
                <div style='clear:both'></div>
				</div>
				<div style='width:100%; font-size:18px; line-height:22px;font-family:Georgia,Palatino,Helvetica Neue,Helvetica,Arial,sans-serif !important;'>{$tweet}</div>
                {$media_code}

                <div class='bbp-actions' style='color:#{$profile_link_color};font-size:11px; width:100%; padding:5px 0; margin:0 0 5px 0; font-family:Helvetica Neue,Helvetica,Arial,Sans-serif;'>
                    <i class='icon-twitter'> </i>
                    <a title='{$date} via {$source_short}' href='{$url}' target='_blank'>{$time_ago}</a>&nbsp;&nbsp;
                    <span style='margin: 0 .2em 0 1em;' class='icon-reply'></span><a href='{$reply_url}' class='bbp-action bbp-reply-action'>
                        <strong>Reply</strong>
                    </a>&nbsp;
                    <span style='margin: 0 .2em 0 1em;' class='icon-retweet'></span><a href='{$retweet_url}' class='bbp-action bbp-retweet-action'>
                        <strong>Retweet</strong>
                    </a>&nbsp;
                    <span style='margin: 0 .2em 0 1em;' class='icon-star-empty'></span><a href='{$favorite_url}' class='bbp-action bbp-favorite-action'>
                        <strong>Favorite</strong>
                    </a>&nbsp;
                </div>

            </div>
        </div>";

        //remove any extra spacing and line breaks
        $tweetHTML = preg_replace( '/\s*[\r\n\t]+\s*/', '', $tweetHTML );

        return $tweetHTML;
    }

    /**
     * Converts a normal line of text containing twitter functionality (@username, #hashtag)
     * into a 'linked' line of text
     * Supports @usernames, #hashtags, and @lists/lists.
     * Normal auto linking of links is already handled by WordPress
     *
     * code from twitter-black-pie WordPress.com plugin
     *
     * @param string $tweet The text to convert
     * @return string The linked text
     */
    function autolink( $tweet ) {
        $tweet = make_clickable( $tweet );

        // Autolink hashtags (example: #wordpress will link to the search apge)
        $tweet = preg_replace(
                '/(^|[^0-9A-Z&\/]+)(#|\xef\xbc\x83)([0-9A-Z_]*[A-Z_]+[a-z0-9_\xc0-\xd6\xd8-\xf6\xf8\xff]*)/iu',
                '${1}<a href="http://twitter.com/search?q=%23${3}" title="#${3}">${2}${3}</a>',
                $tweet
        );

        // Autolink just usernames (example: @justinshreve)
        $tweet = preg_replace(
                '/([^a-zA-Z0-9_]|^)([@\xef\xbc\xa0]+)([a-zA-Z0-9_]{1,20})(\/[a-zA-Z][a-zA-Z0-9\x80-\xff-]{0,79})?/u',
                '${1}<a href="http://twitter.com/intent/user?screen_name=${3}" class="twitter-action">@${3}</a>',
                $tweet
        );
/*
        // Autolink lists (example: @justinshreve/wordpress-people)
        $tweet = preg_replace(
                '$([@|＠])([a-z0-9_]{1,20})(/[a-z][a-z0-9\x80-\xFF-]{0,79})?$i',
                '${1}<a href="http://twitter.com/${2}${3}">${2}${3}</a>',
                $tweet
        );
*/
        return $tweet;
    }

	/*
	found here : http://www.php.net/manual/en/function.htmlentities.php#92105
	*/
	function UTF8entities($content) {
		$contents = $this->unicode_string_to_array($content);
		$swap = "";
		$iCount = count($contents);
		for ($o=0;$o<$iCount;$o++) {
			$contents[$o] = $this->unicode_entity_replace($contents[$o]);
			$swap .= $contents[$o];
		}
		if ( function_exists('mb_convert_encoding') )
			return mb_convert_encoding( $swap, "UTF-8" ); //not really necessary, but why not.
		else
			return utf8_encode( $swap );
	}

	function unicode_string_to_array( $string ) { //adjwilli
		if ( function_exists('mb_strlen') )
			$strlen = mb_strlen($string);
		else
			$strlen = strlen($string);
		while ($strlen) {
			$array[] = mb_substr( $string, 0, 1, "UTF-8" );
			$string = mb_substr( $string, 1, $strlen, "UTF-8" );
			if ( function_exists('mb_strlen') )
				$strlen = mb_strlen( $string );
			else
				$strlen = strlen( $string );
		}
		return $array;
	}

	function unicode_entity_replace($c) { //m. perez 
		$h = ord($c{0});    
		if ($h <= 0x7F) { 
			return $c;
		} else if ($h < 0xC2) { 
			return $c;
		}
		
		if ($h <= 0xDF) {
			$h = ($h & 0x1F) << 6 | (ord($c{1}) & 0x3F);
			$h = "&#" . $h . ";";
			return $h; 
		} else if ($h <= 0xEF) {
			$h = ($h & 0x0F) << 12 | (ord($c{1}) & 0x3F) << 6 | (ord($c{2}) & 0x3F);
			$h = "&#" . $h . ";";
			return $h;
		} else if ($h <= 0xF4) {
			$h = ($h & 0x0F) << 18 | (ord($c{1}) & 0x3F) << 12 | (ord($c{2}) & 0x3F) << 6 | (ord($c{3}) & 0x3F);
			$h = "&#" . $h . ";";
			return $h;
		}
	}	

    function get_tweet_details($id) {

		$result = getTweet($id);
		$result = json_encode($result);
        return json_decode($result);
    }

    function blackbirdpie_embed_handler( $matches, $attr, $url, $rawattr ) {
        return $this->shortcode( array( 'url' => $url ) );
    }
}

if (!function_exists('json_decode')) {
    function json_decode($content, $assoc=false) {
        require_once ( dirname(__FILE__) . '/includes/json.php' );
        if ($assoc) {
            $json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
        }
        else {
            $json = new Services_JSON;
        }
        return $json->decode($content);
    }
}

add_action("init", create_function('', 'global $BlackbirdPie; $BlackbirdPie = new BlackbirdPie();'));

?>