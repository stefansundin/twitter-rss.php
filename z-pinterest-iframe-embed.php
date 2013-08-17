<?php
// Note: this file is only prefixed with "z-" to make github gist put the file below twitter-rss.php (they sort alphabetically), so just rename it if you want to use it.

if (!isset($_GET["type"]) || !isset($_GET["url"]) || !preg_match("/^https?:\/\/(?:www\.)?pinterest\.com\//",$_GET["url"]) > 0 || strpos($_GET["type"],'"') || strpos($_GET["url"],'"')) {
	header("Content-Type: text/plain");
	echo <<<END
That doesn't look like a pinterest url. Example use:
<iframe width="270" height="500" src="http://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedPin&url=http://pinterest.com/pin/51509989461129766/" frameborder="0" allowfullscreen></iframe>
<iframe width="600" height="280" src="http://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedUser&url=http://pinterest.com/knyberg/" frameborder="0" scrolling="no" allowfullscreen></iframe>
<iframe width="600" height="280" src="http://stefansundin.com/stuff/pinterest-iframe-embed.php?type=embedBoard&url=http://pinterest.com/knyberg/our-home-planet-earth/" frameborder="0" scrolling="no" allowfullscreen></iframe>
Note that the pin widget does not have a bounded height.
END;
	die();
}

?>
<html>
<head><script type="text/javascript" src="//assets.pinterest.com/js/pinit.js" async="true"></script></head>
<body><a data-pin-do="<?php echo $_GET["type"]; ?>" href="<?php echo $_GET["url"]; ?>"></a></body>
</html>
