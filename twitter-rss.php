<?php
/* https://github.com/stefansundin/twitter-rss.php
Twitter to RSS (Atom feed)
By: Stefan Sundin
Based on: https://github.com/jdelamater99/Twitter-RSS-Parser/
License: CC BY 3.0
*/

$consumer_key = "xxx";
$consumer_secret = "yyy";
$bearer_token = "zzz";

date_default_timezone_set("Europe/Stockholm");


// edit this function to be notified of script errors by email
function mail_error($error) {
  return; // comment this line out and edit this function if you want to use this
  $error["timestamp"] = strftime("%Y-%m-%d %T");
  $error["ip"] = $_SERVER["REMOTE_ADDR"];
  $error["uri"] = $_SERVER["REQUEST_URI"];
  $headers = <<<EOF
From: php@example.com
Content-Type: text/plain; charset=utf-8
EOF;
  mail("you@example.com", "twitter-rss.php error", print_r($error,true), $headers);
}

function log_exception($exception) {
  mail_error(array(
    "handler" => "exception",
    "type"    => $exception->getCode(),
    "message" => $exception->getMessage(),
    "file"    => $exception->getFile(),
    "line"    => $exception->getLine(),
    "trace"   => $exception->getTrace()
  ));
}

function log_error($errno, $errstr, $errfile, $errline) {
  if (!error_reporting()) return true;
  mail_error(array(
    "handler" => "error",
    "type"    => $errno,
    "message" => $errstr,
    "file"    => $errfile,
    "line"    => $errline
  ));
  return false;
}

function log_shutdown() {
  $error = error_get_last();
  if ($error != NULL) {
    $error["handler"] = "shutdown";
    mail_error($error);
  }
}

set_exception_handler("log_exception");
set_error_handler("log_error");
register_shutdown_function("log_shutdown");
// ini_set("display_errors", 0);


// die(var_dump(get_headers("http://t.co/hathHKRYCz")));
// stream_context_set_default(array("http"=>array("method"=>"GET","header"=>"Cookie: _accounts_session=...")));
// die(var_dump(array_filter(get_headers("http://t.co/Wr6gE5aiMj"), function($h){return stripos($h,"location") === 0;})));

if (isset($_GET["limits"])) {
  if (!isset($_GET["timezone"])) {
    header("Content-Type: text/html;charset=utf-8");
    echo <<<END
<script>
var d = new Date();
var dst = d.getTimezoneOffset() < Math.max(new Date(d.getFullYear(),0,1).getTimezoneOffset(),new Date(d.getFullYear(),6,1).getTimezoneOffset());
window.location.replace(window.location+'&timezone='+d.getTimezoneOffset()+(dst?"&dst":""));
</script><pre>
END;
  }
  else {
    $timezone = tz_offset_to_name((int) $_GET["timezone"], isset($_GET["dst"]));
    if ($timezone) date_default_timezone_set($timezone);
    header("Content-Type: text/plain;charset=utf-8");
  }
  echo "Timezone: ".str_replace("_"," ",date_default_timezone_get())."\n\n";
  $json = twitter_api("/application/rate_limit_status");
  foreach ($json["resources"] as $resource) {
    foreach ($resource as $endpoint => $info) {
      if ($info["remaining"] != $info["limit"]) {
        $diff = (new DateTime())->diff(new DateTime("@{$info["reset"]}"));
        $reset = date("Y-m-d H:i:s", $info["reset"]);
        echo <<<END
$endpoint
\t{$info["remaining"]} remaining of {$info["limit"]}
\tresetting {$reset} (in {$diff->i} minutes and {$diff->s} seconds)
\n
END;
      }

    }
  }
  die();
}

if (!isset($_GET["user"])) {
  die("Please specify user like twitter-rss.php?user=");
}
$user = $_GET["user"];


// setup database
try {
  $db = new PDO("sqlite:twitter-rss.db");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("CREATE TABLE IF NOT EXISTS urls (id INTEGER PRIMARY KEY, url STRING UNIQUE NOT NULL, resolved STRING, first_seen INTEGER, last_seen INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS tweets (id INTEGER PRIMARY KEY, tweet_id STRING UNIQUE NOT NULL, user STRING NOT NULL, date INTEGER, text STRING, error INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS ustream (id INTEGER PRIMARY KEY, channel_name STRING UNIQUE NOT NULL, channel_id INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS instagram (id INTEGER PRIMARY KEY, code STRING UNIQUE NOT NULL, type STRING)");
  $db->beginTransaction();
  register_shutdown_function("shutdown");
} catch (PDOException $e) {
  die("Database failed: ".$e->getMessage());
}

$ratelimited = false;


// die(resolve_url("http://t.co/hathHKRYCz", true));



function tz_offset_to_name($offset, $dst) {
  $offset *= -60;
  foreach (timezone_abbreviations_list() as $abbr) {
    foreach ($abbr as $city) {
      if ($city['offset'] == $offset && $city['dst'] == $dst) {
        return $city['timezone_id'];
      }
    }
  }
  return false;
}

function shutdown() {
  global $db;
  $db->commit();
}

function twitter_auth() {
  global $consumer_key, $consumer_secret;

  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_HTTPHEADER => array(
      "Authorization: Basic ".base64_encode(rawurlencode($consumer_key).":".rawurlencode($consumer_secret)),
      "Content-Type: application/x-www-form-urlencoded;charset=UTF-8"
    ),
    CURLOPT_HEADER => false,
    CURLOPT_URL => "https://api.twitter.com/oauth2/token",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => "grant_type=client_credentials"
  ));
  $json = json_decode(curl_exec($curl), true);
  curl_close($curl);
  return $json;
}

function twitter_api($resource, $query=array()) {
  global $consumer_key, $consumer_secret, $bearer_token;
  $url = "https://api.twitter.com/1.1$resource.json?".http_build_query($query);
  $url = str_replace(array("&amp;","%25"), array("&","%"), $url);

  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_HTTPHEADER => array("Authorization: Bearer $bearer_token"),
    CURLOPT_HEADER => false,
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false
  ));
  $json = json_decode(curl_exec($curl), true);
  curl_close($curl);
  return $json;
}

function double_explode($del1, $del2, $str) {
  $res = array();
  if (empty($str)) {
    return $res;
  }
  $params = explode($del1, $str);
  foreach ($params as $param) {
    $part = explode($del2, $param, 2);
    if (empty($part[0]) && !isset($part[1])) {
      continue;
    }
    if (!isset($part[1])) {
      $part[1] = NULL;
    }
    $res[$part[0]] = $part[1];
  }
  return $res;
}

function httpsify($url) {
  // make sure we use https for certain domains
  // the purpose is, for now, mainly for embedding
  $host = parse_url($url, PHP_URL_HOST);
  if (preg_match("/([^\.]+\.[^\.]+)$/",$host,$matches) === 1) {
    // extract base domain (e.g. i.instagram.com -> instagram.com)
    $host = $matches[1];
  }
  if (in_array($host,explode(",","youtube.com,vimeo.com,ustream.tv,twitpic.com,imgur.com,pinterest.com,instagram.com,giphy.com,vine.co,flickr.com,spotify.com,indiegogo.com,kickstarter.com,soundcloud.com,twimg.com"))) {
    $url = preg_replace("/^http:\/\//", "https://", $url);
  }
  return $url;
}

function normalize_url($url) {
  // make protocol and host lowercase and make sure the path has a slash at the end
  // this is to reduce duplicates in db and unnecessary resolves
  if (preg_match("/^([a-z]+:\/\/[^\/]+)\/?(.*)$/i", $url, $matches) === 1) {
    return strtolower($matches[1])."/".$matches[2];
  }
  return $url;
}

function resolve_url($url, $force=false) {
  global $db;

  $original_url = $url = normalize_url($url);
  #ini_set("user_agent", "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:22.0) Gecko/20100101 Firefox/22.0"); // wp.me
  // t.co uses a HTML redirect if a web browser user agent is used (this is a problem if $url redirect to another t.co, which happens on twitter but is really just silly if you think about it, these url shorterners are redirecting to each other like 2-5 times before you arrive at your url, talk about slowing down the web unnecessarily)

  // try to get resolved url from db
  if (!$force) {
    $stmt = $db->prepare("SELECT resolved FROM urls WHERE url=?");
    $stmt->execute(array($url));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== FALSE) {
      $stmt = $db->prepare("UPDATE urls SET last_seen=? WHERE url=?");
      $stmt->execute(array(time(), $url));
      return $row["resolved"];
    }
  }

  // get the headers
  $headers = @get_headers($url);
  if ($headers === FALSE) {
    // maybe badly configured dns (e.g. nasa.gov), try adding the stupid www prefix
    $wwwurl = str_replace("://", "://www.", $url);
    $headers = @get_headers($wwwurl);
    if ($headers === FALSE) {
      // it didn't work
      $stmt = $db->prepare("INSERT OR REPLACE INTO urls VALUES (NULL,?,?,?,?)");
      $stmt->execute(array($original_url, $url, time(), time()));
      return $url;
    }
    // it worked
    $url = $wwwurl;
  }

  // go through the headers
  foreach ($headers as $header) {
    $parts = explode(":", $header, 2);
    if (strtolower($parts[0]) != "location") {
      continue;
    }
    $location = trim($parts[1]);

    if ($location[0] == "/") {
      // relative redirect
      $location = preg_replace("/^([a-zA-Z]+:\/\/[^\/]+)(.*)$/", "$1$location", $url);
    }

    if (stripos($location,"://www.youtube.com/das_captcha") !== FALSE
     || stripos($location,"://www.nytimes.com/glogin") !== FALSE
     || stripos($location,"://www.facebook.com/unsupportedbrowser") !== FALSE
     || stripos($location,"://play.spotify.com/error/browser-not-supported.php") !== FALSE
     || stripos($location,"://www.linkedin.com/uas/login") !== FALSE
     || stripos($location,"://www.theaustralian.com.au/remote/check_cookie.html") !== FALSE
    // TODO: Stop at blogspot country TLD redirect?
    ) {
       // Stop at these redirections: (usually the last redirection, so we usually get the intended url anyway)
      // YouTube captcha, will happen if the script is generating a lot of resolve_url() requests that lead to YouTube
      // nytimes.com has a bad reaction if it can't set cookies, and redirection loops ensues, just stop this madness
      // Facebook redirects to unsupportedbrowser if it can't identify a known user agent
      // Spotify is a little worse, as open.spotify.com doesn't even try to redirect to play.spotify.com if it's an unsupported user agent
      // LinkedIn redirects you to the login page for e.g. job searches
      break;
    }

    $url = $location;
  }

  // store resolved url in db
  $stmt = $db->prepare("INSERT OR REPLACE INTO urls VALUES (NULL,?,?,?,?)");
  $stmt->execute(array($original_url, $url, time(), time()));

  return $url;
}

function get_tweet($tweet_id, $force=false, $user=null) {
  global $db, $ratelimited;

  // try to get tweet from db
  if (!$force) {
    $stmt = $db->prepare("SELECT * FROM tweets WHERE tweet_id=?");
    $stmt->execute(array($tweet_id));
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($t !== FALSE) {
      if ($t["error"] != null) {
        return false;
      }
      return process_tweet($t);
    }
  }

  // don't even try if we're ratelimited
  if ($ratelimited) {
    return false;
  }

  // get the tweet
  $tweet = twitter_api("/statuses/show", array("id" => $tweet_id));
  if (isset($tweet["error"]) || isset($tweet["errors"])) {
    if (in_array($tweet["errors"][0]["code"], array(179,34))) {
      // 179: Sorry, you are not authorized to see this status.
      // 34: Sorry, that page does not exist
      $stmt = $db->prepare("INSERT OR REPLACE INTO tweets VALUES (NULL,?,?,NULL,NULL,?)");
      $stmt->execute(array($tweet_id, $user, $tweet["errors"][0]["code"]));
      return false;
    }
    else if ($tweet["errors"][0]["code"] == 88) {
      // rate limit exceeded
      $ratelimited = true;
    }
    return false;
  }

  $t = parse_tweet($tweet);

  // store tweet in db
  $stmt = $db->prepare("INSERT OR REPLACE INTO tweets VALUES (NULL,?,?,?,?,NULL)");
  $stmt->execute(array($t["tweet_id"], $t["user"], $t["date"], $t["text"]));

  return process_tweet($t);
}

function parse_tweet($tweet) {
  $t = array(
    "tweet_id" => $tweet["id_str"],
    "user"     => $tweet["user"]["screen_name"],
    "date"     => strtotime($tweet["created_at"]),
    "text"     => unscramble_text($tweet["text"]),
  );

  $entities = $tweet["entities"]["urls"];
  if (isset($tweet["extended_entities"]) && isset($tweet["extended_entities"]["media"])) {
    $entities = array_merge($entities, $tweet["extended_entities"]["media"]);
  }
  if (isset($tweet["entities"]["media"])) {
    $entities = array_merge($entities, $tweet["entities"]["media"]);
  }

  // replace links
  foreach ($entities as $entity) {
    $expanded_url = $entity["expanded_url"];
    if (isset($entity["video_info"])) {
      // Twitter video
      // pick highest bitrate of whatever variant have video/ its content type
      usort($entity["video_info"]["variants"], function($a, $b) {
        if (strpos($a["content_type"],"video/") === 0 && strpos($b["content_type"],"video/") === 0) {
          // both $a and $b are video/
          return $b["bitrate"]-$a["bitrate"];
        }
        else if (strpos($a["content_type"],"video/") === 0 && strpos($b["content_type"],"video/") !== 0) {
          // $a is video/ but $b is not
          return -1;
        }
        else if (strpos($b["content_type"],"video/") === 0 && strpos($a["content_type"],"video/") !== 0) {
          // $b is video/ but $a is not
          return 1;
        }
        else {
          // neither $a or $b is video/
          return 0;
        }
      });
      $expanded_url = $entity["video_info"]["variants"][0]["url"];
    }
    else if (isset($entity["media_url_https"])) {
      // Twitter uploaded picture
      $expanded_url = $entity["media_url_https"].":large"; // use large picture
    }
    else {
      if (preg_match("/^https?:\/\/twitter.com\/[^\/]+\/status\/\d+\/photo\/\d+/",$expanded_url,$matches) === 1) {
        // Twitter uploaded gif picture
        // these are not included in the media entities, instead it is a url entity to a twitter photo, where an mp4 file is included with a video tag
        $html = @file_get_contents($expanded_url);
        if (preg_match('/ video-src="([^"]+)"/',$html,$matches) === 1) {
          $expanded_url = $matches[1];
        }
      }
    }
    $t["text"] = str_replace($entity["url"], $expanded_url, $t["text"]);
  }

  return $t;
}

function unscramble_text($text) {
  // $text = htmlspecialchars($text, ENT_NOQUOTES|ENT_XML1, 'UTF-8', false);
  $text = htmlspecialchars_decode($text, ENT_QUOTES);
  $text = str_replace("\n", " ", $text);
  return $text;
}

function process_tweet($t) {
  global $db;

  $t["text"] = str_replace(array("<",">"), array("&amp;lt;","&amp;gt;"), $t["text"]);
  $t["title"] = $t["text"];
  $t["embeds"] = array();

  // Resolve urls and embed things
  // This regex is pretty close to Twitter's regex, but more relaxed since it allows invalid domain names
  // The important part is pretty much deciding which characters can't be at the end of the url
  // A nice way to test Twitter's url detection is to compose a new tweet and see when the url turns blue
  $url_regex = "/\bhttps?:\/\/[a-z0-9\/\-+=_#%\.~?\[\]@!$&'()*,;:\|]+(?<![%\.~?\[\]@!$&'()*,;:])/i";
  $t["text"] = preg_replace_callback($url_regex, function($matches) use (&$t, $db) {
    $url = $matches[0];
    $expanded_url = httpsify(resolve_url($url));
    $expanded_url_noslash = preg_replace("/\/$/", "", $expanded_url);
    $host = preg_replace("/^www\./", "", parse_url($expanded_url, PHP_URL_HOST)); // remove www. if present
    $path = parse_url($expanded_url, PHP_URL_PATH);
    $paths = explode("/", trim($path,"/"));
    $query = array_merge(
      double_explode("&", "=", parse_url($expanded_url, PHP_URL_QUERY)),
      double_explode("&", "=", parse_url($expanded_url, PHP_URL_FRAGMENT))
    );

    // embed linked tweets
    // if ($host == "twitter.com" && count($paths) >= 3 && $paths[1] == "status" && ctype_digit($paths[2])) {
    //   $t2 = get_tweet($paths[2]);
    //   $posted = date("c", $t2["date"]);
    //   $t["embeds"][] = array("<a href=\"https://twitter.com/{$t2["user"]}/status/{$t2["tweet_id"]}\" title=\"$posted\" rel=\"noreferrer\">{$t2["user"]}</a>: {$t2["text"]}", "text");
    //   #$t["embeds"] = array_merge($t["embeds"], $t2["embeds"]);
    // }

    // embed YouTube
    if (($host == "youtube.com" || $host == "m.youtube.com") && (isset($query["v"]) || isset($query["list"]))) {
      $embed_url = "https://www.youtube.com/embed/".(isset($query["v"])?$query["v"]:"")."?rel=0".(isset($query["list"])?"&list={$query["list"]}":"");
      if (isset($query["t"]) && preg_match("/(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/",$query["t"],$matches)) {
        $start = (isset($matches[1])?(int)$matches[1]*60*60:0) + (isset($matches[2])?(int)$matches[2]*60:0) + (isset($matches[3])?(int)$matches[3]:0);
        $embed_url .= "&start=".$start;
      }
      $t["embeds"][] = array("<iframe width=\"853\" height=\"480\" src=\"$embed_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
    }

    // embed Vimeo
    if ($host == "vimeo.com" && preg_match("/\/(\d+)/",$path,$matches) === 1) {
      $t["embeds"][] = array("<iframe width=\"853\" height=\"480\" src=\"https://player.vimeo.com/video/{$matches[1]}\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
    }

    // embed Ustream
    // resolve and cache ustream channel ids in db
    if ($host == "ustream.tv" && !in_array($paths[0],explode(",",",blog,contact-us,copyright-policy,forgot-password,forgot-username,howto,information,login-signup,new,our-company,platform,premium-membership,press,privacy-policy,producer,services,terms,user,ustream-pro"))
     && !($paths[0] == "channel" && !isset($paths[1]))) {
      if ($paths[0] == "recorded" && isset($paths[1]) && ctype_digit($paths[1])) {
        $t["embeds"][] = array("<iframe width=\"640\" height=\"392\" src=\"https://www.ustream.tv/embed$path?v=3&wmode=direct\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
      }
      else {
        $channel_name = strtolower(rawurldecode(($paths[0] == "channel")?$paths[1]:$paths[0]));
        $stmt = $db->prepare("SELECT channel_id FROM ustream WHERE channel_name=?");
        $stmt->execute(array($channel_name));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== FALSE) {
          $channel_id = $row["channel_id"];
        }
        else {
          $code = @file_get_contents($expanded_url); // we could maybe use the ustream API here, but that requires a key so this is fine
          if (preg_match("/ name=\"ustream:channel_id\" content=\"(\d+)\"/",$code,$matches) === 1) {
            $channel_id = $matches[1];
          }
          else {
            $channel_id = NULL;
          }
          $stmt = $db->prepare("INSERT OR REPLACE INTO ustream VALUES (NULL,?,?)");
          $stmt->execute(array($channel_name, $channel_id));
        }
        if ($channel_id != NULL) {
          $t["embeds"][] = array("<iframe width=\"640\" height=\"392\" src=\"https://www.ustream.tv/embed/$channel_id?v=3&wmode=direct\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
        }
      }
    }

    // embed TwitPic
    if ($host == "twitpic.com" && preg_match("/\/([a-z0-9]+)/",$path,$matches) === 1) {
      $t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"https://twitpic.com/show/large/{$matches[1]}.jpg\" /></a>", "picture");
    }

    // embed imgur
    if ($host == "imgur.com" && !in_array($paths[0],explode(",",",random,signin,register,user,blog,help,removalrequest,tos,apps")) && ($paths[0] != "gallery" || isset($paths[1]))) {
      $embed_url = "https://i.imgur.com/".($paths[0] == "gallery"?$paths[1]:$paths[0]).".jpg";
      $t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"$embed_url\" /></a>", "picture");
    }
    if ($host == "i.imgur.com" && !empty($paths[0])) {
      $t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"$expanded_url\" /></a>", "picture");
    }

    // embed pinterest
    // pinterest embeds using JavaScript, so encapsulate that in a simple website. bah!
    if ($host == "pinterest.com" && !in_array($paths[0],explode(",",",join,login,popular,all,gifts,videos,_,search,about,fashionweek"))) {
      if ($paths[0] == "pin") {
        if (isset($paths[1]) && ctype_digit($paths[1])) {
          $t["embeds"][] = array("<iframe width=\"270\" height=\"500\" src=\"https://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedPin&url=$expanded_url\" frameborder=\"0\" allowfullscreen></iframe>", "picture");
        }
      }
      else if (count($paths) == 1) {
        $t["embeds"][] = array("<iframe width=\"600\" height=\"280\" src=\"https://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedUser&url=$expanded_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
      }
      else if (count($paths) >= 2 && !in_array($paths[1],explode(",","boards,pins,likes,followers,following"))) {
        $t["embeds"][] = array("<iframe width=\"600\" height=\"280\" src=\"https://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedBoard&url=$expanded_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
      }
    }

    if (count($paths) >= 2) {
      // embed pbs.twimg (twitter pics)
      if ($host == "pbs.twimg.com") {
        if ($paths[0] == "media") {
          // Twitter uploaded picture
          $t["embeds"][] = array("<a href=\"$url\" title=\"$url\" rel=\"noreferrer\"><img src=\"$expanded_url\" /></a>", "picture");
        }
        else if ($paths[0] == "tweet_video") {
          // Twitter uploaded gif
          $t["embeds"][] = array("<iframe width=\"640\" height=\"530\" src=\"$expanded_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
        }
      }

      // embed amp.twimg
      if ($host == "amp.twimg.com" && $paths[0] == "v") {
        $t["embeds"][] = array("<iframe width=\"640\" height=\"530\" src=\"$expanded_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
      }

      // embed video.twimg
      if ($host == "video.twimg.com" && $paths[0] == "ext_tw_video") {
        $t["embeds"][] = array("<iframe width=\"640\" height=\"530\" src=\"$expanded_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
      }

      // embed Instagram
      // find out if it's an image or video, embed with img tag if photo, use iframe otherwise
      if (preg_match("/^(i\.)?instagram\.com$/",$host) === 1 && $paths[0] == "p") {
        $code = $paths[1];
        $stmt = $db->prepare("SELECT type FROM instagram WHERE code=?");
        $stmt->execute(array($code));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== FALSE) {
          $type = $row["type"];
        }
        else {
          // oembed api does not work with i.instagram.com, and must use http://
          // in case of error, store type as NULL and iframe will be used
          $oembed_url = preg_replace(array("/^https:/","/i\.instagram\.com/"), array("http:","instagram.com"), $expanded_url);
          $json = json_decode(@file_get_contents("https://api.instagram.com/oembed?url=$oembed_url"), true);
          $type = $json["type"];
          $stmt = $db->prepare("INSERT OR REPLACE INTO instagram VALUES (NULL,?,?)");
          $stmt->execute(array($code, $type));
        }
        if ($type == "photo") {
          $t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"https://instagram.com/p/{$paths[1]}/media/?size=l\" /></a>", "picture");
        }
        else {
          $t["embeds"][] = array("<iframe src=\"$expanded_url_noslash/embed/\" width=\"612\" height=\"710\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
        }
      }

      // embed giphy
      if ($host == "giphy.com" && $paths[0] == "gifs") {
        $t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"https://media.giphy.com/media/{$paths[1]}/giphy.gif\" /></a>", "picture");
      }

      // embed Vine
      if ($host == "vine.co" && $paths[0] == "v") {
        $t["embeds"][] = array("<iframe width=\"600\" height=\"600\" src=\"https://vine.co/v/{$paths[1]}/card\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
      }

      // embed PHHHOTO
      if ($host == "phhhoto.com" && $paths[0] == "i") {
        $t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"https://s3.amazonaws.com/phhhoto-gifs/{$paths[1]}/original/hh.gif\" /></a>", "picture");
      }

      // embed ow.ly
      if ($host == "ow.ly" && $paths[0] == "i") {
        $t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"http://static.ow.ly/photos/normal/{$paths[1]}.jpg\" /></a>", "picture");
      }

      // embed Flickr
      if ($host == "flickr.com" && $paths[0] == "photos"
       && ((count($paths) == 3 && ctype_digit($paths[2])) || (count($paths) >= 4 && $paths[2] == "sets") || (count($paths) >= 5 && $paths[3] == "in"))) {
        $t["embeds"][] = array("<iframe width=\"800\" height=\"534\" src=\"$expanded_url_noslash/player/\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
      }

      // embed Spotify
      if (($host == "play.spotify.com" || $host == "open.spotify.com")) {
        if (in_array($paths[0],explode(",","album,artist,track"))) {
          $t["embeds"][] = array("<iframe width=\"300\" height=\"380\" src=\"https://embed.spotify.com/?uri=spotify:{$paths[0]}:{$paths[1]}\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "audio");
        }
        else if (count($paths) >= 4 && $paths[0] == "user" && $paths[2] == "playlist") {
          $t["embeds"][] = array("<iframe width=\"300\" height=\"380\" src=\"https://embed.spotify.com/?uri=spotify:{$paths[0]}:{$paths[1]}:{$paths[2]}:{$paths[3]}\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "audio");
        }
      }

      // embed TwitLonger
      if ($host == "twitlonger.com" && $paths[0] == "show") {
        $t["embeds"][] = array("<iframe width=\"850\" height=\"500\" src=\"$expanded_url\" frameborder=\"0\" allowfullscreen></iframe>", "text");
      }

      // embed Indiegogo
      if ($host == "indiegogo.com" && $paths[0] == "projects") {
        $t["embeds"][] = array("<iframe width=\"240\" height=\"510\" src=\"https://www.indiegogo.com/project/{$paths[1]}/widget\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "money");
      }
    }

    // embed Kickstarter
    if ($host == "kickstarter.com" && count($paths) >= 3 && $paths[0] == "projects") {
      $t["embeds"][] = array("<iframe width=\"220\" height=\"380\" src=\"https://www.kickstarter.com/projects/{$paths[1]}/{$paths[2]}/widget/card.html\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "money");
    }

    // embed SoundCloud
    // SoundCloud urls from @SoundCloud sometimes ends with "/s-..." which fails the embed (appears to serve a tracking purpose)
    if ($host == "soundcloud.com"
     && !in_array($paths[0],explode(",",",apps,community-guidelines,creators,dashboard,explore,imprint,jobs,logout,messages,pages,people,premium,press,pro,search,settings,stream,terms-of-use,upload,you"))
     && (!isset($paths[1]) || !in_array($paths[1],explode(",","activity,comments,favorites,followers,following,groups,likes,tracks")))
     && ($paths[0] != "groups" || !isset($paths[2]) || !in_array($paths[2],explode(",","tracks,discussion,info,dropbox")))
    ) {
      $embed_url = preg_replace("/\/s-[^\/]+$/","",$expanded_url);
      $height = isset($paths[1])?166:450;
      $t["embeds"][] = array("<iframe width=\"853\" height=\"$height\" src=\"https://w.soundcloud.com/player/?url=$embed_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "audio");
    }

    return "<a href=\"$expanded_url\" title=\"$url\" rel=\"noreferrer\">$expanded_url</a>";
  }, $t["text"]);

  // embed Spotify plain text uri
  preg_replace_callback("/spotify:(?:(?:album|artist|track):(?:[a-zA-Z0-9]+)|user:(?:[a-zA-Z0-9]+):playlist:(?:[a-zA-Z0-9]+))/", function($matches) use (&$t) {
    $t["embeds"][] = array("<iframe width=\"300\" height=\"380\" src=\"https://embed.spotify.com/?uri={$matches[0]}\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "audio");
  }, $t["text"]);

  // Title
  $t["title"] = preg_replace_callback($url_regex, function($matches) {
    $host = preg_replace("/^www\./", "", parse_url(resolve_url($matches[0]), PHP_URL_HOST)); // remove www. if present
    return "[$host]";
  }, $t["title"]);

  return $t;
}

function array_unique_embeds($embeds) {
  $codes = Array();
  $res = Array();
  foreach ($embeds as $embed) {
    $code = $embed[0];
    if (!in_array($code,$codes)) {
      $res[] = $embed;
      $codes[] = $code;
    }
  }
  return $res;
}


set_time_limit(2*60); // resolving all the urls can take quite a bit of time...

if (isset($_GET["all"])) {
  if (empty($_GET["all"])) {
    #http_response_code(301);
    header("Location: ".preg_replace("/([\?&])all=?/", "$1all=".time(), $_SERVER["REQUEST_URI"]));
    die();
  }
  if (time() < ((int)$_GET["all"])+30*60) {
    $getall = true;
    set_time_limit(30*60); // this will take forever
  }
}


if (strlen($bearer_token) < 10) {
  $json = twitter_auth();
  header("Content-Type: text/plain;charset=utf-8");
  die("\$bearer_token = \"{$json["access_token"]}\";");
}


// header("Content-Type: text/plain;charset=utf-8");
#die(var_dump(twitter_api("/application/rate_limit_status")));
#die(var_dump(twitter_api("/users/lookup", array("screen_name" => $user))));
// die(var_dump(twitter_api("/statuses/show", array("id" => "612335003972689920"))));
// die(var_dump(get_tweet("612335003972689920", true)));


$query = array(
  "screen_name" => $user,
  "count" => 200, // max 200
  "include_rts" => true, // include retweets
  #"max_id" => "31053515254140928",
  #"trim_user" => true,
  #"exclude_replies" => true,
  #"contributor_details" => true,
);

$tweets = twitter_api("/statuses/user_timeline", $query);
#die(var_dump($tweets));



if (isset($tweets["error"])) {
  http_response_code(503);
  die($tweets["error"]);
}
if (isset($tweets["errors"])) {
  http_response_code(429);
  die("{$tweets["errors"][0]["message"]} ({$tweets["errors"][0]["code"]})");
}

$user = @$tweets[0]["user"]["screen_name"] ?: $user;
$updated = date("c", strtotime(@$tweets[0]["created_at"]));


header("Content-Type: application/atom+xml;charset=utf-8");

echo <<<END
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <id>https://twitter.com/$user</id>
  <title>@$user</title>
  <updated>$updated</updated>
  <link href="https://twitter.com/$user" />

END;

while (true) {
  foreach ($tweets as $tweet) {
    $id = $tweet["id_str"];
    $updated = date("c", strtotime($tweet["created_at"]));

    if (isset($tweet["retweeted_status"])) {
      $t = process_tweet(parse_tweet($tweet["retweeted_status"]));
      $title = "$user: RT @{$t["user"]}: {$t["title"]}";
      $content = "$user: RT @{$t["user"]}: {$t["text"]}";
    }
    else {
      $t = process_tweet(parse_tweet($tweet));
      $title = "$user: {$t["title"]}";
      $content = "$user: {$t["text"]}";
    }

    if (isset($tweet["in_reply_to_screen_name"]) && isset($tweet["in_reply_to_status_id_str"])) {
      $url = "https://twitter.com/{$tweet["in_reply_to_screen_name"]}/status/{$tweet["in_reply_to_status_id_str"]}";
      $irt = get_tweet($tweet["in_reply_to_status_id_str"], false, $tweet["in_reply_to_screen_name"]);
      if ($irt) {
        $posted = date("c", $irt["date"]);
        $content .= "\n<br/><br/>\nIn reply to: <a href=\"$url\" title=\"$posted\" rel=\"noreferrer\">{$irt["user"]}</a>: {$irt["text"]}";
        $t["embeds"] = array_merge($t["embeds"], $irt["embeds"]);
      }
      else {
        // probably ratelimited or deleted tweet
        $content .= "\n<br/><br/>\nIn reply to: <a href=\"$url\" rel=\"noreferrer\">{$irt["user"]}</a>";
      }
      $title .= " &#8644;";
    }

    $t["embeds"] = array_unique_embeds($t["embeds"]);
    foreach ($t["embeds"] as $embed) {
      $content .= "\n<br/><br/>\n{$embed[0]}";
      if (stripos($embed[0],"src=\"http://") !== FALSE) {
        $content .= "<br/>\n<small>This embed does not use https. If it isn't displayed, make sure your browser/reader does not block mixed content.</small>";
      }

      $icons = array("video" => "&#x1F3AC;", "picture" => "&#x1F3A8;", "audio" => "&#x1F3BC;", "text" => "&#x1F4D7;", "money" => "&#x1f4b0;");
      $title .= " {$icons[$embed[1]]}";
    }

    $content .= "\n<br/><br/>\nRetweeted {$tweet["retweet_count"]} times. Favorited by {$tweet["favorite_count"]} people.";

    // escape stuff
    $title = str_replace("<", "&lt;", $title);
    $content = str_replace("<", "&lt;", $content);
    $title = preg_replace("/&(?!([a-z][a-z0-9]*|(#\d+));)/i", "&amp;", $title);
    $content = preg_replace("/&(?!([a-z][a-z0-9]*|(#\d+));)/i", "&amp;", $content);

    if (isset($_GET["short"])) {
      $content = "";
    }

    echo <<<END

  <entry>
    <id>https://twitter.com/$user/status/$id</id>
    <link href="https://twitter.com/$user/status/$id" />
    <updated>$updated</updated>
    <author><name>$user</name></author>
    <title type="html">$title</title>
    <content type="html">
$content
    </content>
  </entry>

END;

    flush();
  }

  if (!isset($getall) || count($tweets) == 0) {
    break;
  }

  $query["max_id"] = bcsub($tweets[count($tweets)-1]["id_str"], "1");
  $tweets = twitter_api("/statuses/user_timeline", $query);
}

echo <<<END
</feed>

END;
