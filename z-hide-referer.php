<?php
// Note: this file is only prefixed with "z-" to make github gist put the file below twitter-rss.php (they sort alphabetically), so just rename it if you want to use it.

if (!isset($_GET["url"])) {
	header("Content-Type: text/plain");
	echo <<<END
Because flickr is a dick and blocks iframe embeds. Example use:
<iframe width="800" height="532" srcdoc='<meta http-equiv="refresh" content="0;url=http://www.flickr.com/photos/national-archives-of-australia/9507039958/lightbox">' src="http://stefansundin.com/stuff/hide-referer.php?url=http://www.flickr.com/photos/national-archives-of-australia/9507039958/lightbox" frameborder="0" scrolling="no" allowfullscreen></iframe>

This page will do a meta refresh which does not leak a referer.
If your browser supports srcdoc then you don't need to use this file.
END;
	die();
}

if (strpos($_GET["url"],'"')) {
	header("Content-Type: text/plain");
	die("Please urlencode if url contains quote characters.");
}

?>
<meta http-equiv="refresh" content="0;url=<?php echo $_GET["url"]; ?>">
