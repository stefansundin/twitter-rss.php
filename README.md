# Twitter to RSS

- Resolves links and embeds pictures, YouTube and other services (besides pictures, all of them require that your RSS reader supports iframes).
- Uses an SQLite backend to cache resolved urls and stuff.


# Instructions

1. Create a Twitter account.
2. Create an app on https://dev.twitter.com/apps
3. Copy consumer key and consumer secret to variables at the top of `twitter-rss.php`.
4. Run the script to get a bearer token.
5. Copy the bearer token to the source code.
6. Set up the feeds in your favorite reader, using e.g. `twitter-rss.php?user=github`
7. Make sure the url resolution database is created. Otherwise you can try: `touch twitter-rss.db; chmod 666 twitter-rss.db`


# To get history

1. Request `twitter-rss.php?user=github&all`
2. You will be redirected to `twitter-rss.php?user=github&all=<timestamp>`
3. This url can be used to fetch all tweets for 30 minutes, after that, it will behave like normal, only fetching the last 200 tweets. The reason for this is to prevent your feed reader from using up the Twitter API limit.
4. Add this url to your feed reader before the timer runs out. You may have to get a new url since resolving the urls probably took a while.
5. Note that the API limits the number of tweets you can get to about 3200 tweets.


# Misc

To clean up the database (remove urls not seen in the last three days):
```
sqlite3 twitter-rss.db "DELETE FROM urls WHERE last_seen < strftime('%s','now','-3 days'); VACUUM;"
```

You may want to create an index on the urls table:
```
CREATE INDEX url ON urls (url)
```


# Notes

- It seems that links created in 2011 and earlier don't always have their urls as entities (they are not even autolinked when viewing them on twitter.com).
- Old tweets don't escape ampersands either.
- 180 requests can be done per 15 minutes.
- TODO: Make sure we don't hit the limit.
- Check your limits by going to `twitter-rss.php?limits`
- The PHP extensions `php_curl`, `php_pdo_sqlite`, and `php_openssl` must be enabled.


# Docs

- https://dev.twitter.com/docs/api/1.1/get/statuses/user_timeline
- https://dev.twitter.com/docs/rate-limiting/1.1
- https://dev.twitter.com/docs/tweet-entities
