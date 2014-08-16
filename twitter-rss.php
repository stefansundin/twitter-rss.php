<?php
/* https://gist.github.com/stefansundin/5951213
Twitter to RSS (Atom feed)
By: Stefan Sundin https://github.com/stefansundin
Based on: https://github.com/jdelamater99/Twitter-RSS-Parser/
License: CC BY 3.0


Steps:
1. Create a Twitter account.
2. Create an app on https://dev.twitter.com/apps
3. Copy consumer key and consumer secret to variables below.
4. Create an access token on the bottom of the app page.
5. Copy the access token and its secret to the variables below.
6. Set up the feeds in your favorite reader, using twitter-rss.php?user=
7. Make sure the url resolution database is created. Otherwise you can try: touch twitter-rss.db; chmod 666 twitter-rss.db


To get history:
1. Request twitter-rss.php?user=github&all
2. You will be redirected to twitter-rss.php?user=github&all=<timestamp>
3. This url can be used to fetch all tweets for 30 minutes, after that, it will no longer have any effect.
   The reason for this is to prevent your feed reader from using up the Twitter API limit.
4. Add this url to your feed reader before the timer runs out. You may have to get a new url since resolving the urls probably took a while.
5. Note that the API limits the number of tweets you can get to about 3200 tweets.


To clean up the database (remove urls not seen in the last three days):
sqlite3 twitter-rss.db "DELETE FROM urls WHERE last_seen < strftime('%s','now','-3 days'); VACUUM;"

You may want to create an index (I have not seen any significant gains from it yet, so it's not done automatically):
CREATE INDEX url ON urls (url)

Note:
It seems that links created in 2011 and earlier don't always have their urls as entities (they are not even autolinked when viewing them on twitter.com).
Old tweets don't escape ampersands either.
180 requests can be done per 15 minutes.
TODO: Make sure we don't hit the limit.
Check your limits by going to twitter-rss.php?limits
The PHP extensions php_curl, php_pdo_sqlite, and php_openssl must be enabled.

https://dev.twitter.com/docs/api/1.1/get/statuses/user_timeline
https://dev.twitter.com/docs/rate-limiting/1.1
https://dev.twitter.com/docs/tweet-entities
http://creativecommons.org/licenses/by/3.0/
*/

$consumer_key = "xxx";
$consumer_secret = "yyy";
$access_token = "zzz";
$access_token_secret = "xyz";

date_default_timezone_set("Europe/Stockholm");

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
			// var_dump($info)
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


// setup url resolution db
try {
	$db = new PDO("sqlite:twitter-rss.db");
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec("CREATE TABLE IF NOT EXISTS urls (id INTEGER PRIMARY KEY, url STRING UNIQUE, resolved STRING, first_seen INTEGER, last_seen INTEGER)");
	$db->exec("CREATE TABLE IF NOT EXISTS tweets (id INTEGER PRIMARY KEY, tweet_id STRING UNIQUE, user STRING, date INTEGER, text STRING, error INTEGER)");
	$db->exec("CREATE TABLE IF NOT EXISTS ustream (id INTEGER PRIMARY KEY, channel_name STRING UNIQUE, channel_id INTEGER)");
	$db->exec("CREATE TABLE IF NOT EXISTS instagram (id INTEGER PRIMARY KEY, code STRING UNIQUE NOT NULL, type STRING NOT NULL)");
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

function twitter_api($resource, $query=array()) {
	global $consumer_key, $consumer_secret, $access_token, $access_token_secret;
	$url = "https://api.twitter.com/1.1$resource.json";
	$oauth = array(
		"oauth_consumer_key" => $consumer_key,
		"oauth_token" => $access_token,
		"oauth_nonce" => (string) mt_rand(),
		"oauth_timestamp" => time(),
		"oauth_signature_method" => "HMAC-SHA1",
		"oauth_version" => "1.0"
	);
	$oauth = array_map("rawurlencode", $oauth); // must be encoded before sorting
	//$query = array_map("rawurlencode", $query);
	$arr = array_merge($oauth, $query); // combine the values THEN sort
	asort($arr); // secondary sort (value)
	ksort($arr); // primary sort (key)

	// http_build_query automatically encodes, but our parameters are already encoded, and must be by this point, so we undo the encoding step
	$querystring = urldecode(http_build_query($arr, "", "&"));

	// generate the hash
	$base_string = "GET&".rawurlencode($url)."&".rawurlencode($querystring);
	$key = rawurlencode($consumer_secret)."&".rawurlencode($access_token_secret);
	$signature = rawurlencode(base64_encode(hash_hmac("sha1", $base_string, $key, true)));
	$oauth["oauth_signature"] = $signature;
	ksort($oauth); // probably not necessary, but twitter's demo does it
	$auth = "OAuth ".urldecode(http_build_query($oauth, "", ", "));

	// encode the query params
	$url .= "?".http_build_query($query);
	$url = str_replace(array("&amp;","%25"), array("&","%"), $url);

	// make the request
	$feed = curl_init();
	curl_setopt_array($feed, array(
		CURLOPT_HTTPHEADER => array("Authorization: $auth"),
		CURLOPT_HEADER => false,
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_SSL_VERIFYPEER => false
	));
	$json = json_decode(curl_exec($feed), true);
	curl_close($feed);
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

function normalize_url($url) {
	// make protocol and host lowercase and make sure the path has a slash at the end
	// this is to reduce duplicates in db and unnecessary resolves
	if (preg_match("/^([a-z]+:\/\/[^\/]+)\/?(.*)$/i", $url, $matches) > 0) {
		return strtolower($matches[1])."/".$matches[2];
	}
	return $url;
}

function resolve_url($url, $force=false) {
	global $db;
	$original_url = $url = normalize_url($url);
	#ini_set("user_agent", "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:22.0) Gecko/20100101 Firefox/22.0"); // wp.me
	// t.co uses a HTML redirect if a web browser user agent is used (this is a problem if $url redirect to another t.co, which happens on twitter but is really just silly if you think about it, these url shorterners are redirecting to each other like 2-5 times before you arrive at your url, talk about slowing down the web unnecessarily)

	/*
	$shorteners = array("bit.ly", "t.co", "tinyurl.com", "wp.me", "goo.gl", "fb.me", "is.gd", "tiny.cc", "youtu.be", "yt.be", "flic.kr", "tr.im", "ow.ly", "t.cn", "url.cn", "g.co", "is.gd", "su.pr", "aje.me");
	$host = parse_url($url, PHP_URL_HOST);
	if (!in_array($host,$shorteners)) {
		return $url;
	}
	*/

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

	#var_dump($headers);
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

function get_tweet($id, $force=false, $user=null) {
	global $db, $ratelimited;

	// This regex is pretty close to Twitter's regex, but more relaxed since it allows invalid domain names
	// The important part is pretty much deciding which characters can't be at the end of the url
	// A nice way to test Twitter's url detection is to compose a new tweet and see when the url turns blue
	// $url_regex = "/(^|[^a-z0-9])https?:\/\/[a-z0-9\/\-+=_#%\.~?\[\]@!$&'()*,;:]+(?<![%\.~?\[\]@!$&'()*,;:])/ig";

	// try to get tweet from db
	if (!$force) {
		$stmt = $db->prepare("SELECT * FROM tweets WHERE tweet_id=?");
		$stmt->execute(array($id));
		$tweet = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($tweet !== FALSE) {
			if ($tweet["error"] != null) {
				return false;
			}
			return $tweet;
		}
	}

	// don't even try if we're ratelimited
	if ($ratelimited) {
		return false;
	}

	// get the tweet
	$json = twitter_api("/statuses/show", array("id" => $id));
	if (isset($json["error"]) || isset($json["errors"])) {
		if (in_array($json["errors"][0]["code"], array(179,34))) {
			// 179: Sorry, you are not authorized to see this status.
			// 34: Sorry, that page does not exist
			$stmt = $db->prepare("INSERT OR REPLACE INTO tweets VALUES (NULL,?,?,NULL,NULL,?)");
			$stmt->execute(array($tweet["tweet_id"], $user, $json["errors"][0]["code"]));
			return false;
		}
		else if ($json["errors"][0]["code"] == 88) {
			// rate limit exceeded
			$ratelimited = true;
		}
		return false;
	}


	$tweet["tweet_id"] = $json["id_str"];
	$tweet["user"] = $json["user"]["screen_name"];
	$tweet["date"] = strtotime($json["created_at"]);
	$tweet["text"] = str_replace("\n", " ", $json["text"]);

	foreach ($json["entities"]["urls"] as $url) {
		$expanded_url = resolve_url($url["expanded_url"]);
		$tweet["text"] = str_replace($url["url"], $expanded_url, $tweet["text"]);
	}

	// store tweet in db
	$stmt = $db->prepare("INSERT OR REPLACE INTO tweets VALUES (NULL,?,?,?,?,NULL)");
	$stmt->execute(array($tweet["tweet_id"], $tweet["user"], $tweet["date"], $tweet["text"]));

	return $tweet;
}

function parse_tweet($tweet) {
	global $db;
	#$tweet["text"] = htmlspecialchars($tweet["text"], ENT_NOQUOTES|ENT_XML1, 'UTF-8', false);
	$tweet["text"] = htmlspecialchars_decode($tweet["text"], ENT_QUOTES);
	$tweet["text"] = str_replace(array("&","<",">"), array("&amp;","&amp;lt;","&amp;gt;"), $tweet["text"]);
	$t = array(
		"id"      => $tweet["id_str"],
		"date"    => $tweet["created_at"],
		"user"    => $tweet["user"]["screen_name"],
		"title"   => $tweet["text"],
		"text"    => $tweet["text"],
		"embeds"  => array()
	);

	if (!isset($tweet["entities"]["media"])) {
		$tweet["entities"]["media"] = array();
	}

	// expand urls
	foreach ($tweet["entities"]["urls"] as $url) {
		$expanded_url = resolve_url($url["expanded_url"]);
		$expanded_url_https = preg_replace("/^http:\/\//", "https://", $expanded_url);
		$expanded_url_https_noslash = preg_replace("/\/$/", "", $expanded_url_https);
		$host = preg_replace("/^www\./", "", parse_url($expanded_url, PHP_URL_HOST)); // remove www. if present
		$path = parse_url($expanded_url, PHP_URL_PATH);
		$paths = explode("/", trim($path,"/"));
		$query = array_merge(
			double_explode("&", "=", parse_url($expanded_url,PHP_URL_QUERY)),
			double_explode("&", "=", parse_url($expanded_url,PHP_URL_FRAGMENT))
		);

		$t["text"] = str_replace($url["url"], "<a href=\"$expanded_url\" title=\"{$url["display_url"]} {$url["url"]}\" rel=\"noreferrer\">$expanded_url</a>", $t["text"]);
		$t["title"] = str_replace($url["url"], "[$host]", $t["title"]);

		// embed YouTube
		if (($host == "youtube.com" || $host == "m.youtube.com") && (isset($query["v"]) || isset($query["list"]))) {
			$embed_url = "https://www.youtube.com/embed/".(isset($query["v"])?$query["v"]:"videoseries")."?".(isset($query["list"])?"list={$query["list"]}":"").(isset($query["t"])?"start={$query["t"]}":"");
			$t["embeds"][] = array("<iframe width=\"853\" height=\"480\" src=\"$embed_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
		}

		// embed Vimeo
		if ($host == "vimeo.com" && preg_match("/\/(\d+)/",$path,$matches) > 0) {
			$t["embeds"][] = array("<iframe width=\"853\" height=\"480\" src=\"https://player.vimeo.com/video/{$matches[1]}\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
		}

		// embed Ustream
		// resolve and cache ustream channel ids in db
		if ($host == "ustream.tv" && !in_array($paths[0],explode(",",",blog,contact-us,copyright-policy,forgot-password,forgot-username,howto,information,login-signup,new,our-company,platform,premium-membership,press,privacy-policy,producer,services,terms,user,ustream-pro"))
		 && !($paths[0] == "channel" && !isset($paths[1]))) {
			if ($paths[0] == "recorded" && isset($paths[1]) && is_numeric($paths[1])) {
				$t["embeds"][] = array("<iframe width=\"640\" height=\"392\" src=\"http://www.ustream.tv/embed$path?v=3&wmode=direct\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
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
					$code = file_get_contents($expanded_url); // we could maybe use the ustream API here, but that requires a key so this is fine
					if (preg_match("/ name=\"ustream:channel_id\" content=\"(\d+)\"/",$code,$matches) > 0) {
						$channel_id = $matches[1];
					}
					else {
						$channel_id = NULL;
					}
					$stmt = $db->prepare("INSERT OR REPLACE INTO ustream VALUES (NULL,?,?)");
					$stmt->execute(array($channel_name, $channel_id));
				}
				if ($channel_id != NULL) {
					$t["embeds"][] = array("<iframe width=\"640\" height=\"392\" src=\"http://www.ustream.tv/embed/$channel_id?v=3&wmode=direct\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
				}
			}
		}

		// embed TwitPic
		if ($host == "twitpic.com" && preg_match("/\/([a-z0-9]+)/",$path,$matches) > 0) {
			$t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"https://twitpic.com/show/large/{$matches[1]}.jpg\" /></a>", "picture");
		}

		// embed imgur
		if ($host == "imgur.com" && !in_array($paths[0],explode(",",",random,signin,register,user,blog,help,removalrequest,tos,apps")) && ($paths[0] != "gallery" || isset($paths[1]))) {
			$embed_url = "https://i.imgur.com/".($paths[0] == "gallery"?$paths[1]:$paths[0]).".jpg";
			$t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"$embed_url\" /></a>", "picture");
		}
		if ($host == "i.imgur.com" && !empty($paths[0])) {
			$t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"$expanded_url_https\" /></a>", "picture");
		}

		// embed giphy
		if ($host == "giphy.com" && $paths[0] == "gifs" && !empty($paths[1])) {
			$t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"http://media.giphy.com/media/{$paths[1]}/giphy.gif\" /></a>", "picture");
		}

		// embed pinterest
		// pinterest embeds using JavaScript, so encapsulate that in a simple website. bah!
		if ($host == "pinterest.com" && !in_array($paths[0],explode(",",",join,login,popular,all,gifts,videos,_,search,about,fashionweek"))) {
			if ($paths[0] == "pin") {
				if (isset($paths[1]) && is_numeric($paths[1])) {
					$t["embeds"][] = array("<iframe width=\"270\" height=\"500\" src=\"http://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedPin&url=$expanded_url\" frameborder=\"0\" allowfullscreen></iframe>", "picture");
				}
			}
			else if (count($paths) == 1) {
				$t["embeds"][] = array("<iframe width=\"600\" height=\"280\" src=\"http://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedUser&url=$expanded_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
			}
			else if (count($paths) >= 2 && !in_array($paths[1],explode(",","boards,pins,likes,followers,following"))) {
				$t["embeds"][] = array("<iframe width=\"600\" height=\"280\" src=\"http://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedBoard&url=$expanded_url\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
			}
		}

		if (count($paths) >= 2) {
			// embed Instagram
			// find out if it's an image or video, embed with img tag if photo, use iframe otherwise
			if ($host == "instagram.com" && $paths[0] == "p") {
				$code = $paths[1];
				$stmt = $db->prepare("SELECT type FROM instagram WHERE code=?");
				$stmt->execute(array($code));
				$row = $stmt->fetch(PDO::FETCH_ASSOC);
				if ($row !== FALSE) {
					$type = $row["type"];
				}
				else {
					$json = json_decode(file_get_contents("https://api.instagram.com/oembed?url=$expanded_url"), true);
					$type = $json["type"];
					$stmt = $db->prepare("INSERT OR REPLACE INTO instagram VALUES (NULL,?,?)");
					$stmt->execute(array($code, $type));
				}
				if ($type == "photo") {
					$t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"https://instagram.com/p/{$paths[1]}/media/?size=l\" /></a>", "picture");
				}
				else {
					$t["embeds"][] = array("<iframe src=\"$expanded_url_https_noslash/embed/\" width=\"612\" height=\"710\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "video");
				}
			}

			// embed Vine
			if ($host == "vine.co" && $paths[0] == "v") {
				$t["embeds"][] = array("<iframe width=\"600\" height=\"600\" src=\"https://vine.co/v/{$paths[1]}/embed/simple\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
			}

			// embed PHHHOTO
			if ($host == "phhhoto.com" && $paths[0] == "i") {
				$t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"https://s3.amazonaws.com/phhhoto-gifs/{$paths[1]}/original/hh.gif\" /></a>", "picture");
			}

			// embed ow.ly
			if ($host == "ow.ly" && $paths[0] == "i") {
				$t["embeds"][] = array("<a href=\"$expanded_url\" title=\"$expanded_url\" rel=\"noreferrer\"><img src=\"http://static.ow.ly/photos/normal/{$paths[1]}.jpg\" /></a>", "picture");
			}

			// embed flickr
			// flickr blocks iframe embeds, so hide referer by using a website which does a meta refresh
			if ($host == "flickr.com" && $paths[0] == "photos") {
				if (count($paths) == 2 || (count($paths) >= 4 && $paths[2] == "sets")) {
					$t["embeds"][] = array("<iframe width=\"800\" height=\"533\" srcdoc='<meta http-equiv=\"refresh\" content=\"0;url=$expanded_url/show\">' src=\"http://stefansundin.com/stuff/hide-referer.php?url=$expanded_url/show\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
				}
				else if (count($paths) >= 3 && (is_numeric($paths[2]) || $paths[2] == "favorites" || (count($paths) >= 4 && $paths[2] == "galleries"))) {
					$t["embeds"][] = array("<iframe width=\"800\" height=\"533\" srcdoc='<meta http-equiv=\"refresh\" content=\"0;url=$expanded_url/lightbox\">' src=\"http://stefansundin.com/stuff/hide-referer.php?url=$expanded_url/lightbox\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "picture");
				}
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
				$t["embeds"][] = array("<iframe width=\"760\" height=\"500\" src=\"$expanded_url\" frameborder=\"0\" allowfullscreen></iframe>", "text");
			}

			// embed Indiegogo
			if ($host == "indiegogo.com" && $paths[0] == "projects") {
				$t["embeds"][] = array("<iframe width=\"240\" height=\"510\" src=\"http://www.indiegogo.com/project/{$paths[1]}/widget\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "money");
			}
		}

		// embed Kickstarter
		if ($host == "kickstarter.com" && count($paths) >= 3 && $paths[0] == "projects") {
			$t["embeds"][] = array("<iframe width=\"220\" height=\"380\" src=\"http://www.kickstarter.com/projects/{$paths[1]}/{$paths[2]}/widget/card.html\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "money");
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
	}

	// expand media (Twitter pics)
	foreach ($tweet["entities"]["media"] as $url) {
		$media_url = str_replace("&", "&amp;", $url["media_url_https"].":large"); // use large picture
		$t["text"] = str_replace($url["url"], "<a href=\"https://{$url["display_url"]}\" title=\"{$url["display_url"]}\" rel=\"noreferrer\">https://{$url["display_url"]}</a>", $t["text"]);
		$t["embeds"][] = array("<a href=\"$media_url\" title=\"{$url["display_url"]}\" rel=\"noreferrer\"><img src=\"$media_url\" /></a>", "picture");
		// replace url in title, can't use parse_url() to find the host since it doesn't use a protocol (will almost certainly always be pic.twitter.com though)
		if (preg_match("/^(?:[a-zA-Z]+:\/\/)?([^\/]+)/",$url["display_url"],$matches) > 0) {
			$t["title"] = str_replace($url["url"], "[{$matches[1]}]", $t["title"]);
		}
	}

	// embed Spotify (plain text uri)
	preg_match_all("/spotify:(?:(?:album|artist|track):(?:[a-zA-Z0-9]+)|user:(?:[a-zA-Z0-9]+):playlist:(?:[a-zA-Z0-9]+))/", $t["text"], $matches);
	foreach ($matches[0] as $uri) {
		$t["embeds"][] = array("<iframe width=\"300\" height=\"380\" src=\"https://embed.spotify.com/?uri=$uri\" frameborder=\"0\" scrolling=\"no\" allowfullscreen></iframe>", "audio");
	}

	$t["embeds"] = array_unique_embeds($t["embeds"]);
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



#die(var_dump(twitter_api("/application/rate_limit_status")));
#die(var_dump(twitter_api("/users/lookup", array("screen_name" => $user))));
#die(var_dump(twitter_api("/statuses/show", array("id" => "210462857140252672"))));
// for ($i=0; $i < 130; $i++) {
// 	// twitter_api("/statuses/show", array("id" => "411397857107664896"));
// 	die(var_dump(get_tweet("411593665287446528")));
// }
// die();
#die(var_dump(get_tweet("409181201211998208")));
#die(var_dump(get_tweet("411593665287446528")));

$query = array(
	"screen_name" => $user,
	"count" => 200, // max 200
	"include_rts" => true, // include retweets
	#"max_id" => "31053515254140928",
	#"trim_user" => true,
	#"exclude_replies" => true,
	#"contributor_details" => true,
);

$json = twitter_api("/statuses/user_timeline", $query);
#die(var_dump($json));



if (isset($json["error"])) {
	http_response_code(503);
	die($json["error"]);
}
if (isset($json["errors"])) {
	http_response_code(429);
	die("{$json["errors"][0]["message"]} ({$json["errors"][0]["code"]})");
}

$user = @$json[0]["user"]["screen_name"] ?: $user;
$updated = date("c", strtotime(@$json[0]["created_at"]));


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
	foreach ($json as $tweet) {
		$id = $tweet["id_str"];
		$updated = date("c", strtotime($tweet["created_at"]));

		if (isset($tweet["retweeted_status"])) {
			$t = parse_tweet($tweet["retweeted_status"]);
			$title = "$user: RT @{$t["user"]}: {$t["title"]}";
			$content = "$user: RT @{$t["user"]}: {$t["text"]}";
		}
		else {
			$t = parse_tweet($tweet);
			$title = "$user: {$t["title"]}";
			$content = "$user: {$t["text"]}";
		}

		if (isset($tweet["in_reply_to_screen_name"]) && isset($tweet["in_reply_to_status_id_str"])) {
			$url = "https://twitter.com/{$tweet["in_reply_to_screen_name"]}/status/{$tweet["in_reply_to_status_id_str"]}";
			$irt = get_tweet($tweet["in_reply_to_status_id_str"], false, $tweet["in_reply_to_screen_name"]);
			if ($irt) {
				$posted = date("c", $irt["date"]);
				$content .= "\n<br/><br/>\nIn reply to: <a href=\"$url\" title=\"$posted\" rel=\"noreferrer\">{$irt["user"]}</a>: {$irt["text"]}";
			}
			else {
				// probably ratelimited
				$content .= "\n<br/><br/>\nIn reply to: <a href=\"$url\" rel=\"noreferrer\">{$irt["user"]}</a>";
			}
			$title .= " &#8644;";
		}

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
		<title>$title</title>
		<content type="html">
$content
		</content>
	</entry>

END;

		flush();
	}

	if (!isset($getall) || count($json) == 0) {
		break;
	}

	$query["max_id"] = bcsub($json[count($json)-1]["id_str"], "1");
	$json = twitter_api("/statuses/user_timeline", $query);
}

echo <<<END
</feed>

END;
