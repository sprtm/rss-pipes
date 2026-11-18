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

### Atom

Parses atom feed.

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

This requires PHP's DOM extension and adds one webpage request per item each time
the pipe runs, so it can make processing slower. Page metadata may also be
truncated; a usable page description replaces the feed description regardless of
length. Place filters before this step to avoid fetching pages for discarded
items, or after it to filter using the webpage descriptions.

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
