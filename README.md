# Rss Pipes

[![Build Status](https://travis-ci.org/urmaul/rss-pipes.svg)](https://travis-ci.org/urmaul/rss-pipes)

Rss feeds manipulating tool inspired by Yahoo Pipes. It has no pipes editing interface - you just configure them in `.yml` files and copy their urls to your reader.

## Usage

### Simplest app

Just create `index.php` file with content like this.

```php
<?php

require '../vendor/autoload.php';

$pipesDir = __DIR__ . '/../pipes';
\rsspipes\View::run($pipesDir);

```

Then you create a `pipes` dir that contains your pipe configs. For example you can create a config named `urmaul.yml` with content like this.

```yaml
# urmaul.com without yii
---
-
  type: atom
  url: http://urmaul.com/atom.xml
-
  type: block
  rules:
    title: Yii
```

Opening `index.php?pipe=urmaul` you will see rss feed of urmaul.com blog without posts about Yii.

## Available sections

### Rss

Parses rss feed.

Source requests must return a successful HTTP status and non-empty, valid RSS
XML. Rejected requests (for example HTTP 406), empty responses, invalid XML,
and unexpected document types produce an error item instead of a PHP fatal
error page. A failed source supplies fallback channel metadata when none exists,
so the error can be read as RSS. Set `showErrors: 0` to suppress error items.

Set `sourceCacheTtl: 600` on an RSS or Atom source to share one upstream check
per ten minutes across readers. On failure, the pipe serves the last successful
source until a later check succeeds. The default is zero (check every run).

Set `log: true` on the `rss`, `atom`, `pageArticle`, or `pageDescription` section
to write request statuses and cache or extraction decisions to the PHP error log.
Look for `[rss-pipes]` in Docker logs. URLs omit credentials and query strings.

HTTP 403, 406, 429, or 503 pauses upstream requests to the same hostname for
31 minutes. Refreshes during the pause serve cached content or RSS summaries
and do not restart the timer. Source failures require a previously cached
feed to serve headlines. Cooldown state uses the source cache volume.

### Atom

Parses atom feed.

Uses the same source-request error handling as `rss`, and expects Atom XML.

### PageArticle

By default, each run fetches all eligible uncached articles. For a publisher
that limits requests, set `maxFetches` to cap newly seen articles per run. Cache hits
use no slots. Items beyond the limit keep their summaries on subsequent refreshes. Keep the
article cache directory persistent to preserve seen-link history.

Replaces each item's description with article text from its linked webpage.
Works after `rss`, `atom`, or `pipe` sections:

```yaml
- type: rss
  url: https://example.com/feed.xml
- type: pageArticle
  maxFetches: 5
```

Extraction uses explicit `itemprop="articleBody"` markup first, then JSON-LD
`articleBody` on articles and blog posts, then Readability's general article
extractor. Extracted content is rebuilt as safe HTML, preserving semantic headings,
bold/emphasized text, paragraphs, line breaks, lists, blockquotes, tables, and code
blocks where present in the selected content. `<b>` and `<i>` become `<strong>`
and `<em>`. Links retain only validated HTTP, HTTPS, or mailto destinations;
relative links are resolved against the article URL. Publisher CSS, classes,
fonts, event handlers, and other source attributes are discarded. Formatting
provided only by a publisher's CSS is not reconstructed. Plain-text structured
article bodies and metadata summaries receive paragraphs and line breaks.
Scripts, navigation, forms, and other non-article elements are discarded. Readability results shorter
than 200 characters are ignored to reduce accidental extraction of page controls.
When available, an `article` or `main` element limits the heuristic extraction;
site footers, print headers, and common interface notices are removed first.
Figures, captions, and `time` elements are excluded from article text.
Aftonbladet’s “Snabbversionen” summary box, affiliate advertisement carousels,
explicitly marked advertisement slots, and DN’s account-promotion box
(`login-infobox`) are also excluded. Bonnier subscription offers and checkout
containers (`tapet-paywall` and `tapet-single-offer`) are removed while the
paywall label is preserved. Existing article
cache entries are refreshed once to apply this filter.
If no usable article is found, it falls back to `og:description`,
`twitter:description`, and meta `description`, then keeps the item's own feed
description. Do not add `pageDescription` after `pageArticle`, as that would
replace the article text with a summary.

For chat/live-blog links containing a `pinnedEntry`, `post`, or `ifr-dir-postId` query parameter,
both `pageArticle` and `pageDescription` preserve the original feed description
without requesting the page or using cached page text. These links identify a
single update, while the page can contain many unrelated updates. Other live-blog
URL formats are not yet handled specially.
JSON descriptions contain the same HTML as RSS descriptions. Article caches now
store sanitized HTML so formatting survives cache hits without double escaping.
Old text-only article cache entries are bypassed once to fetch the original
formatting; no manual cache clearing is needed. Normal cache lifetimes and the
number of requests per uncached article are unchanged.

No publisher settings are required by default. For a site whose article is not
identified correctly, an optional XPath expression can select its body before
the general extraction methods run:

```yaml
- type: pageArticle
  bodyXPath: '//div[@class="article__body"]'
```

Matching nodes are combined in document order, without repeating nested matches.
If the expression matches nothing usable, general extraction still runs.

#### Signed-in articles with browser cookies

To fetch articles using your existing publisher subscription, set `cookieFile` to
an exported **Netscape cookies.txt** file. This option requires PHP's `curl`
extension and applies only to `pageArticle`, not the RSS source request:

```yaml
- type: rss
  url: https://example.com/feed.xml
- type: pageArticle
  cookieFile: /srv/rss-pipes-secrets/publisher-cookies.txt
```

Use an absolute local path readable by the PHP process. Raw `Cookie:` headers,
JSON exports, HAR files, and browser cookie databases are not accepted. Invalid,
empty, or unreadable files fail configuration instead of silently fetching anonymously.
Cookies are loaded when the section initializes; re-exporting them takes effect
on the next initialization (normally the next feed request).

To save cookies without installing a browser extension:

1. Sign in to the publisher in Chrome or Edge and open a subscriber article.
2. Open Developer Tools, then **Application → Storage → Cookies**, and select
   the publisher's origin. The table shows Name, Value, Domain, Path, Expires,
   Secure, and HttpOnly. See [Chrome's cookie inspector documentation](https://developer.chrome.com/docs/devtools/application/cookies/).
3. Create a plain text file starting with `# Netscape HTTP Cookie File`. For
   each publisher session/authentication cookie, copy its fields into one row
   in the order below, separated by **literal tab characters**. If you cannot
   identify the authentication cookies, include all cookies for that publisher.
   Include cookies from its relevant parent domain as well.

```text
# Netscape HTTP Cookie File
#HttpOnly_.example.com	TRUE	/	TRUE	0	session	PASTE_COOKIE_VALUE_HERE
```

The example row uses literal tabs. Its seven fields are:

| Field | Value to save |
| --- | --- |
| Domain | Exactly the domain shown in DevTools; prefix it with `#HttpOnly_` if HttpOnly is checked. |
| Include subdomains | `TRUE` for a domain starting with `.`, otherwise `FALSE`. |
| Path | The cookie's Path, often `/`. |
| Secure | `TRUE` if Secure is checked, otherwise `FALSE`. |
| Expiry | Expiry as Unix seconds; `0` only for cookies shown as Session. |
| Name | The exact cookie name. |
| Value | The exact cookie value, without added quotes or URL encoding. |

For a dated expiry, convert the displayed date to Unix seconds, for example
`Math.floor(Date.parse("2030-01-01T00:00:00Z") / 1000)` in a browser console,
using the cookie's actual expiry date and timezone. Do not replace an expired
cookie's date to extend it; sign in again and copy the new value instead.
The [curl cookie format reference](https://curl.se/docs/http-cookies.html)
describes the Netscape format. A browser cookie exporter can also produce this
format; export only the publisher's cookies, including HttpOnly cookies.

4. Save the file outside this repository and the public web directory. On the
   server, restrict access, for example `chmod 600 /srv/rss-pipes-secrets/publisher-cookies.txt`,
   and ensure the PHP process owns it or otherwise has read permission.
5. Set `cookieFile` as above and refresh the feed. If access expires, sign in
   again and replace the export. The pipe does not log in or renew sessions.

The cookie engine respects domain, path, expiry, and Secure restrictions,
including across HTTP/HTTPS redirects. Each article request gets its own cookie
engine; response cookies are not written back to your export or shared with
other articles. Exported cookie contents distinguish cache entries and seen-link
history from anonymous requests and other exports, so replacing the export
allows a fresh fetch. Normal cache lifetimes still apply, including when a
cookie expires during that lifetime. Cookie values are not included in logs or
cache contents. Protect the export like a password, and keep the generated
full-text feed and article cache private if they contain subscriber content.

This works when the publisher returns full HTML to the saved session. Browser
JavaScript, local storage, partitioned-cookie state, device-bound sessions, and
additional article API requests are not reproduced. Some publishers will still
return an excerpt or reject the request. The existing paywall label describes
the publisher's metadata and may remain even when the full article is fetched.

Fetching, caching, and timeouts work like `pageDescription`: one HTML request per
new or expired URL, a 5-second timeout, successful results cached for 24 hours,
and failures cached for 5 minutes. Metadata fallbacks count as successful results.
The same `cache`, `cacheDir`, `cacheTtl`, `failureCacheTtl`, and `timeout` options
are available. The default directory is
`sys_get_temp_dir()/rss-pipes-page-articles`; article entries use a separate file
prefix even with a shared custom directory. Different `bodyXPath` expressions
also have separate cache entries. Existing description cache entries cannot
supply article text, so switching types fetches each URL again once.

Only the downloaded HTML is used: JavaScript, images, videos, and additional
article APIs are not loaded. Missing text, paywalls, and blocked requests can
limit extraction to an introduction or the existing description. General
extraction is heuristic and may occasionally include unrelated text or omit
parts of an article. Article extraction adds local processing, and full text
makes cache files and generated feeds larger.

Uses the PHP 7-compatible `andreskrey/readability.php` library. Run
`composer install` after updating; its dependencies require PHP's DOM, XML, and
mbstring extensions.

Both `pageArticle` and `pageDescription` append `Trolig betalvägg` as a separate final
HTML paragraph when the downloaded page's JSON-LD explicitly marks the article
or one of its `hasPart` sections as not accessible for free. Boolean `false` and
string values such as `"False"` are recognized. Missing metadata, short excerpts,
and failed requests do not identify a paywall and do not add a label. The label
does not indicate whether the extracted text is complete. Detection also works
when only a page summary or the original feed excerpt is available.
When primary article access data (`article-pulse-data`) explicitly says `Meter`
or `Metered`, no label is added: availability depends on the reader's quota.
An explicit `Free` access level also suppresses a conflicting JSON-LD restriction.

Paywall status is cached with the text and adds no page requests during normal
processing. Older cache entries without this status or with an outdated detection
version are refetched once to update it. The note is added when serving the item, so cached content does not accumulate
duplicate notes. RSS and JSON include it in `description`; `comments` is unchanged.

### PageDescription

Optionally replaces each item's description with metadata from its linked webpage.
Add this section after an `rss`, `atom`, or `pipe` section:

```yaml
-
  type: rss
  url: https://example.com/feed.xml
-
  type: pageDescription
```

Uses the first non-empty `og:description`, falling back to `twitter:description`
and then the standard meta `description`, in that priority order. The original
feed description is kept if the page cannot be fetched or has no usable metadata.
Only HTTP and HTTPS item links are fetched. This works with both RSS and Atom.

Descriptions are cached on disk by article URL for 24 hours. Failed requests and
pages without usable metadata are cached for 5 minutes; these entries keep each
item's own feed description. Only new or expired URLs are fetched on subsequent
runs. Each webpage request has a 5-second timeout; timeouts and HTTP errors keep
the feed description. The first run still fetches pages sequentially and can be
slower.

Optional settings (times are in seconds):

```yaml
- type: pageDescription
  cache: true
  cacheTtl: 86400
  failureCacheTtl: 300
  timeout: 5
  # Optional: use a persistent directory instead of the system temporary directory.
  cacheDir: /var/cache/rss-pipes/page-descriptions
```

By default, the cache lives in `sys_get_temp_dir()/rss-pipes-page-descriptions`
(usually `/tmp/rss-pipes-page-descriptions`). Set `cache: false` to disable it,
or set either TTL to `0` to disable caching that type of result. The cache
directory must be writable by PHP. If it cannot be read or written, processing
continues without caching. Delete its `.json` files to clear the cache; expired
entries are ignored and replaced when their URL is requested again.

In Docker, mount a volume at the configured `cacheDir` to keep cached descriptions
when the container is recreated. For example, with the custom path above, add
`page-descriptions:/var/cache/rss-pipes/page-descriptions` to the service's
`volumes`, and declare `page-descriptions: {}` in the Compose file's top-level
`volumes` mapping. Ensure the volume is writable by the PHP process.

This requires PHP's DOM extension. Page metadata may also be truncated; a usable
page description replaces the feed description regardless of length. Place
filters before this step to avoid fetching pages for discarded items, or after
it to filter using the webpage descriptions.

### Filter

Keep only items that fulfill the condition.

### Block

Drop items that fulfill the condition.

### Callback

Proces feed or items using callbacks.

### Replace

Replaces substring inside attribute.

Parameters:

* **attribute** - attribute we want to replace substring in.
* **search** - subtring we want to replace.
* **replace** - subtring we want to replace with.
* **regex** - optional boolean, defaults to `false`. Set to `true` to use a delimited PCRE pattern in `search`. Replacement strings support capture references such as `$1`. Invalid patterns are rejected when the pipe loads.

Sample:

```yaml
-
  type: replace
  attribute: title
  search: space
  replace: spaaace
```

Remove a podcast sponsor footer and everything after it, including line breaks.

```yaml
- type: replace
  attribute: description
  regex: true
  search: '~(?:<br\s*/?>\s*)?(?:<p>\s*)?Testa Stör är sponsrade av ComeOn!.*\z~s'
  replace: ''
```

### Pipe

Runs separate pipe and adds it's result to feed.
