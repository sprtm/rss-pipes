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

### Atom

Parses atom feed.

Uses the same source-request error handling as `rss`, and expects Atom XML.

### PageArticle

Replaces each item's description with article text from its linked webpage.
Works after `rss`, `atom`, or `pipe` sections:

```yaml
- type: rss
  url: https://example.com/feed.xml
- type: pageArticle
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

Both `pageArticle` and `pageDescription` append `Betalartikel` as a separate final
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

Sample:

```yaml
-
  type: replace
  attribute: title
  search: space
  replace: spaaace
```

### Pipe

Runs separate pipe and adds it's result to feed.
