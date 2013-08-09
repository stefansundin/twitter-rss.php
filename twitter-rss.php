<?php
/*
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
6. Make sure the url resolution database is created. Otherwise you can try: touch twitter-rss.db; chmod 666 twitter-rss.db
7. Set up the feeds in your favorite reader, using twitter-rss.php?user=

To clean up the database (remove urls not seen in the last three days):
sqlite3 twitter-rss.db "DELETE FROM urls WHERE last_seen < strftime('%s','now','-3 days'); VACUUM;"

You may want to create an index (I have not seen any significant gains from it yet, so it's not done automatically):
CREATE INDEX url ON urls (url)

180 requests can be done per 15 minutes.
TODO: Make sure we don't hit the limit.

https://dev.twitter.com/docs/api/1.1/get/statuses/user_timeline
https://dev.twitter.com/docs/rate-limiting/1.1
https://dev.twitter.com/docs/tweet-entities
http://creativecommons.org/licenses/by/3.0/
*/

$consumer_key = "xxx";
$consumer_secret = "yyy";
$access_token = "zzz";
$access_token_secret = "xyz";


#die(var_dump(get_headers("http://t.co/VH6NIWVV6N")));

if (!isset($_GET["user"])) {
	die("Please specify user like twitter-rss.php?user=");
}
$user = $_GET["user"];


// setup url resolution db
try {
	$db = new PDO("sqlite:twitter-rss.db");
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec("CREATE TABLE IF NOT EXISTS urls (id INTEGER PRIMARY KEY, url STRING UNIQUE, resolved STRING, first_seen INTEGER, last_seen INTEGER)");
	$db->beginTransaction();
} catch (PDOException $e) {
	die("Database failed: ".$e->getMessage());
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
	if (preg_match("/^([a-zA-Z]+:\/\/[^\/]+)\/?(.*)$/", $url, $matches) > 0) {
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
		) {
		 	// Stop at these redirections: (usually the last redirection, so we usually get the intended url anyway)
			// YouTube captcha, will happen if the script is generating a lot of resolve_url() requests that lead to YouTube.
			// nytimes.com has a bad reaction if it can't set cookies, and redirection loops ensues, just stop this madness
			// Facebook redirects to unsupportedbrowser if it can't identify a known user agent
			// Spotify is a little worse, as open.spotify.com doesn't even try to redirect to play.spotify.com if it's an unsupported user agent
			break;
		}

		$url = $location;
	}

	// store resolved url in db
	$stmt = $db->prepare("INSERT OR REPLACE INTO urls VALUES (NULL,?,?,?,?)");
	$stmt->execute(array($original_url, $url, time(), time()));

	return $url;
}

/*
echo resolve_url("http://tinyurl.com/kfhwhdm", true);
$db->commit();
die();
*/

function parse_tweet($tweet) {
	$t = array(
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
		$escaped_url = str_replace("&", "&amp;", $expanded_url);
		$host = preg_replace("/^www\./", "", parse_url($expanded_url, PHP_URL_HOST)); // remove www. if present
		$path = parse_url($expanded_url, PHP_URL_PATH);
		$paths = explode("/", $path);
		$query = double_explode("&", "=", parse_url($expanded_url, PHP_URL_QUERY));

		$t["text"] = str_replace($url["url"], "&lt;a href=\"$escaped_url\" title=\"{$url["display_url"]} {$url["url"]}\">$escaped_url&lt;/a>", $t["text"]);
		$t["title"] = str_replace($url["url"], "[$host]", $t["title"]);

		// embed YouTube
		if ($host == "youtube.com" || $host == "m.youtube.com") {
			if (isset($query["v"]) && isset($query["list"])) {
				$t["embeds"][] = array("&lt;iframe width=\"853\" height=\"480\" src=\"https://www.youtube.com/embed/{$query["v"]}?list={$query["list"]}\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "video");
			}
			else if (!isset($query["v"]) && isset($query["list"])) {
				$t["embeds"][] = array("&lt;iframe width=\"853\" height=\"480\" src=\"https://www.youtube.com/embed/videoseries?list={$query["list"]}\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "video");
			}
			else if (isset($query["v"]) && !isset($query["list"])) {
				$t["embeds"][] = array("&lt;iframe width=\"853\" height=\"480\" src=\"https://www.youtube.com/embed/{$query["v"]}\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "video");
			}
		}

		// embed Vimeo
		if ($host == "vimeo.com" && preg_match("/\/(\d+)/",$path,$matches) > 0) {
			$t["embeds"][] = array("&lt;iframe width=\"853\" height=\"480\" src=\"https://player.vimeo.com/video/{$matches[1]}\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "video");
		}

		// embed Twitch
		if ($host == "twitch.tv" && !in_array($paths[1],explode(",",",directory,login,p,products,search,user"))) {
			$t["embeds"][] = array("&lt;iframe width=\"853\" height=\"512\" src=\"http://twitch.tv/embed?channel={$paths[1]}\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "video");
		}

		// embed TwitPic
		if ($host == "twitpic.com" && preg_match("/\/([a-z0-9]+)/",$path,$matches) > 0) {
			$t["embeds"][] = array("&lt;a href=\"$expanded_url\" title=\"$expanded_url\">&lt;img src=\"https://twitpic.com/show/large/{$matches[1]}.jpg\" />&lt;/a>", "picture");
		}

		// embed imgur
		if ($host == "i.imgur.com" && !empty($paths[1])) {
			$t["embeds"][] = array("&lt;a href=\"$expanded_url\" title=\"$expanded_url\">&lt;img src=\"$expanded_url\" />&lt;/a>", "picture");
		}

		// embed Instagram
		if ($host == "instagram.com" && $paths[1] == "p" && count($paths) >= 2) {
			$t["embeds"][] = array("&lt;a href=\"$expanded_url\" title=\"$expanded_url\">&lt;img src=\"https://instagr.am/p/{$paths[2]}/media/?size=l\" />&lt;/a>", "picture");
		}

		// embed Vine
		if ($host == "vine.co" && $paths[1] == "v" && count($paths) >= 2) {
			$t["embeds"][] = array("&lt;iframe width=\"600\" height=\"600\" src=\"https://vine.co/v/{$paths[1]}/embed/simple\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "picture");
		}

		// embed PHHHOTO
		if ($host == "phhhoto.com" && $paths[1] == "i" && count($paths) >= 2) {
			$t["embeds"][] = array("&lt;a href=\"$expanded_url\" title=\"$expanded_url\">&lt;img src=\"https://s3.amazonaws.com/phhhoto-gifs/{$paths[2]}/original/hh.gif\" />&lt;/a>", "picture");
		}

		// embed ow.ly
		if ($host == "ow.ly" && $paths[1] == "i" && count($paths) >= 2) {
			$t["embeds"][] = array("&lt;a href=\"$expanded_url\" title=\"$expanded_url\">&lt;img src=\"http://static.ow.ly/photos/normal/{$paths[2]}.jpg\" />&lt;/a>", "picture");
		}

		// embed SoundCloud
		if ($host == "soundcloud.com"
		 && !in_array($paths[1],explode(",",",apps,community-guidelines,creators,dashboard,explore,imprint,jobs,logout,messages,pages,people,premium,press,pro,search,settings,stream,terms-of-use,upload,you"))
		 && (!isset($paths[2]) || !in_array($paths[2],explode(",","activity,comments,favorites,followers,following,groups,likes,tracks")))
		) {
			$height = isset($paths[2])?166:450;
			$t["embeds"][] = array("&lt;iframe width=\"853\" height=\"$height\" src=\"https://w.soundcloud.com/player/?url=$escaped_url\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "audio");
		}

		// embed Spotify
		if (($host == "play.spotify.com" || $host == "open.spotify.com") && count($paths) >= 3) {
			if (in_array($paths[1],explode(",","album,artist,track"))) {
				$t["embeds"][] = array("&lt;iframe width=\"300\" height=\"380\" src=\"https://embed.spotify.com/?uri=spotify:{$paths[1]}:{$paths[2]}\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "audio");
			}
			else if (count($paths) >= 5 && $paths[1] == "user" && $paths[3] == "playlist") {
				$t["embeds"][] = array("&lt;iframe width=\"300\" height=\"380\" src=\"https://embed.spotify.com/?uri=spotify:{$paths[1]}:{$paths[2]}:{$paths[3]}:{$paths[4]}\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "audio");
			}
		}
	}

	// expand media (Twitter pics)
	foreach ($tweet["entities"]["media"] as $url) {
		$media_url = str_replace("&", "&amp;", $url["media_url_https"].":large"); // use large picture
		$t["text"] = str_replace($url["url"], "&lt;a href=\"https://{$url["display_url"]}\" title=\"{$url["display_url"]}\">https://{$url["display_url"]}&lt;/a>", $t["text"]);
		$t["embeds"][] = array("&lt;a href=\"$media_url\" title=\"{$url["display_url"]}\">&lt;img src=\"$media_url\" />&lt;/a>", "picture");
		// replace url in title, can't use parse_url() to find the host since it doesn't use a protocol (will almost certainly always be pic.twitter.com though)
		if (preg_match("/^(?:[a-zA-Z]+:\/\/)?([^\/]+)/",$url["display_url"],$matches) > 0) {
			$t["title"] = str_replace($url["url"], "[{$matches[1]}]", $t["title"]);
		}
	}

	// embed Spotify (plain text uri)
	preg_match_all("/spotify:(?:(?:album|artist|track):(?:[a-zA-Z0-9]+)|user:(?:[a-zA-Z0-9]+):playlist:(?:[a-zA-Z0-9]+))/", $t["text"], $matches);
	foreach ($matches[0] as $uri) {
		$t["embeds"][] = array("&lt;iframe width=\"300\" height=\"380\" src=\"https://embed.spotify.com/?uri=$uri\" frameborder=\"0\" allowfullscreen>&lt;/iframe>", "audio");
	}

	// escape unescaped ampersands, this is necessary on some (only old?) tweets
	$t["title"] = preg_replace("/&(?!([a-zA-Z][a-zA-Z0-9]*|(#\d+));)/", "&amp;", $t["title"]);
	$t["text"] = preg_replace("/&(?!([a-zA-Z][a-zA-Z0-9]*|(#\d+));)/", "&amp;", $t["text"]);

	return $t;
}


set_time_limit(120); // resolving all the urls can take quite a bit of time...



$url = "https://api.twitter.com/1.1/statuses/user_timeline.json";
#$url = "https://api.twitter.com/1.1/application/rate_limit_status.json";
$query = array(
	"screen_name" => $user,
	"count" => 200, // max 200
	"include_rts" => true, // include retweets
	#"trim_user" => true,
	#"exclude_replies" => true,
	#"contributor_details" => true,
);
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

#die(var_dump($json));

if (isset($json["error"])) {
	http_response_code(503);
	die($json["error"]);
}
if (isset($json["errors"])) {
	http_response_code(429);
	die($json["errors"]["message"]);
}

$user = $json[0]["user"]["screen_name"];
$updated = date("c", strtotime($json[0]["created_at"]));


header("Content-Type: application/atom+xml;charset=utf-8");

echo <<<END
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
	<id>https://twitter.com/$user</id>
	<title>&#128038; $user</title>
	<updated>$updated</updated>
	<link href="https://twitter.com/$user" />

END;


foreach ($json as $tweet) {
	$id = $tweet["id_str"];
	$updated = date("c", strtotime($tweet["created_at"]));

	if (isset($tweet["retweeted_status"])) {
		$t = parse_tweet($tweet["retweeted_status"]);
		$title = "{$t["user"]}: RT @{$tweet["retweeted_status"]["user"]["screen_name"]}: {$t["title"]}";
		$content = "{$t["user"]}: RT @{$tweet["retweeted_status"]["user"]["screen_name"]}: {$t["text"]}";
	}
	else {
		$t = parse_tweet($tweet);
		$title = "{$t["user"]}: {$t["title"]}";
		$content = "{$t["user"]}: {$t["text"]}";
	}


	if (isset($tweet["in_reply_to_screen_name"])) {
		$content .= "\n&lt;br/>&lt;br/>\nIn reply to: &lt;a href=\"https://twitter.com/{$tweet["in_reply_to_screen_name"]}/status/{$tweet["in_reply_to_status_id_str"]}\">https://twitter.com/{$tweet["in_reply_to_screen_name"]}/status/{$tweet["in_reply_to_status_id_str"]}&lt;/a>";
	}

	foreach ($t["embeds"] as $embed) {
		$content .= "\n&lt;br/>&lt;br/>\n{$embed[0]}";
		$icons = array("video" => "&#x1F3AC;", "picture" => "&#x1F3A8;", "audio" => "&#x1F3BC;");
		$title .= " {$icons[$embed[1]]}";
	}

	$content .= "\n&lt;br/>&lt;br/>\nRetweeted {$tweet["retweet_count"]} times. Favorited by {$tweet["favorite_count"]} people.";

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


echo <<<END
</feed>

END;

$db->commit();
