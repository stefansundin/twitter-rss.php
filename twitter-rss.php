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
6. Set up the feeds in your favorite reader, using twitter-rss.php?user=


15 requests can be done per 15 minutes.
TODO: Caching backend and rate limiting.

https://dev.twitter.com/docs/api/1.1/get/statuses/user_timeline
https://dev.twitter.com/docs/rate-limiting/1.1
https://dev.twitter.com/docs/tweet-entities
http://creativecommons.org/licenses/by/3.0/
*/

$consumer_key = "xxx";
$consumer_secret = "yyy";
$access_token = "zzz";
$access_token_secret = "xyz";


if (!isset($_GET["user"])) {
	die("Please specify user like twitter-rss.php?user=");
}

$user = $_GET["user"];



function resolve_url($url) {
	$shorteners = Array("bit.ly", "t.co", "tinyurl.com", "wp.me", "goo.gl", "fb.me", "is.gd", "tiny.cc", "youtu.be", "yt.be", "flic.kr", "tr.im", "ow.ly", "t.cn", "url.cn", "g.co", "is.gd", "su.pr", "aje.me");

	$domain = parse_url($url, PHP_URL_HOST);
	if (!in_array($domain,$shorteners)) {
		return $url;
	}

	ini_set('user_agent', 'Mozilla/5.0'); // fb.me hack
	$headers = get_headers($url);
	$headers = array_reverse($headers);
	#var_dump($headers);
	foreach ($headers as $header) {
		if (stripos($header,"Location:") === 0) {
			$location = trim(substr($header, strlen("Location:")));
			if ($location[0] == "/") {
				// relative redirect, must get next Location header until a domain appears
				// Google Plus does this sometimes
				if (!isset($path)) {
					$path = $location;
				}
				continue;
			}
			if (stripos($location,"//www.youtube.com/das_captcha") !== FALSE) {
				// ignore YouTube captcha and grab the real url
				// will occur if the script is generating a lot of resolve_url() requests that lead to YouTube
				continue;
			}
			if (isset($path) && preg_match("/^([a-zA-Z]+:\/\/[^\/]+)/",$location,$matches) > 0) {
				// compose the path
				$location = $matches[1].$path;
			}
			return $location;
		}
	}
}

set_time_limit(120); // resolving all the urls can take quite a bit of time...



$url = "https://api.twitter.com/1.1/statuses/user_timeline.json";
$query = array(
	"screen_name" => $user,
	"count" => 200, // max 200
	"include_rts" => true, // include retweets
	//"trim_user" => true,
	//"exclude_replies" => true,
	//"contributor_details" => true,
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

$options = array(
	CURLOPT_HTTPHEADER => array("Authorization: $auth"),
	CURLOPT_HEADER => false,
	CURLOPT_URL => $url,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_SSL_VERIFYPEER => false
);

$feed = curl_init();
curl_setopt_array($feed, $options);
$json = json_decode(curl_exec($feed), true);
curl_close($feed);

#var_dump($json);



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


foreach ($json as $t) {
	$user = $t["user"]["screen_name"];
	$updated = date("c", strtotime($t["created_at"]));
	$text = $title = $t["text"];

	// expand urls
	foreach ($t["entities"]["urls"] as $url) {
		#var_dump($url);
		$expanded_url = resolve_url($url["expanded_url"]);
		$escaped_url = str_replace("&", "&amp;", $expanded_url);
		$text = str_replace($url["url"], "&lt;a href=\"$escaped_url\" title=\"{$url["display_url"]}\">$escaped_url&lt;/a>", $text);

		$domain = parse_url($expanded_url, PHP_URL_HOST);
		$title = str_replace($url["url"], "[$domain]", $title);

		// embed if YouTube
		if ($domain == "www.youtube.com" || $domain == "m.youtube.com") {
			unset($video_id);
			unset($list);

			if (preg_match("/[\?&]v=([^&\?#]+)/",$expanded_url,$matches) > 0) {
				$video_id = $matches[1];
			}
			if (preg_match("/[\?&]list=([^&\?#]+)/",$expanded_url,$matches) > 0) {
				$list = $matches[1];
			}

			if (isset($video_id) && isset($list)) {
				$text .= "\n&lt;p>&lt;iframe width=\"853\" height=\"480\" src=\"https://www.youtube.com/embed/$video_id?list=$list\" frameborder=\"0\" allowfullscreen>&lt;/iframe>&lt;/p>";
			}
			else if (!isset($video_id) && isset($list)) {
				$text .= "\n&lt;p>&lt;iframe width=\"853\" height=\"480\" src=\"https://www.youtube.com/embed/videoseries?list=$list\" frameborder=\"0\" allowfullscreen>&lt;/iframe>&lt;/p>";
			}
			else if (isset($video_id) && !isset($list)) {
				$text .= "\n&lt;p>&lt;iframe width=\"853\" height=\"480\" src=\"https://www.youtube.com/embed/$video_id\" frameborder=\"0\" allowfullscreen>&lt;/iframe>&lt;/p>";
			}
		}

		// embed if Vimeo
		if ($domain == "vimeo.com") {
			if (preg_match("/\/(\d+)/",$expanded_url,$matches) > 0) {
				$video_id = $matches[1];
				$text .= "\n&lt;p>&lt;iframe width=\"853\" height=\"480\" src=\"http://player.vimeo.com/video/$video_id\" frameborder=\"0\" allowfullscreen>&lt;/iframe>&lt;/p>";
			}
		}
	}

	// expand media (Twitter pics)
	if (isset($t["entities"]["media"])) {
		foreach ($t["entities"]["media"] as $url) {
			#var_dump($url);
			$media_url = str_replace("&", "&amp;", $url["media_url_https"].":large"); // use large picture
			$text = str_replace($url["url"], "&lt;a href=\"$media_url\">$media_url&lt;/a>", $text);
			$text .= "\n&lt;p>&lt;a href=\"$media_url\" title=\"{$url["display_url"]}\">&lt;img src=\"$media_url\" />&lt;/a>&lt;/p>";

			$domain = parse_url($url["display_url"], PHP_URL_HOST);
			$title = str_replace($url["url"], "[$domain]", $title);
		}
	}

	echo <<<END
	<entry>
		<id>https://twitter.com/$user/status/{$t["id_str"]}</id>
		<link href="https://twitter.com/$user/status/{$t["id_str"]}" />
		<updated>$updated</updated>
		<author><name>$user</name></author>
		<title>$user: $title</title>
		<content type="html">$user: $text</content>
	</entry>


END;

	flush();
}


echo <<<END
</feed>

END;

