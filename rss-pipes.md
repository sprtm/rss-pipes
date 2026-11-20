# RSS Pipes command reference

RSS Pipes has **no command line commands**. Its “commands” are *sections* in a `.yml` pipe configuration. The application starts with an empty feed, runs sections from top to bottom, and serves the result over HTTP. A source section such as `rss` or `atom` normally comes first. [Pipe execution](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/Pipe.php)

Save this as `pipes/news.yml`:

```yaml
- type: rss
  url: https://example.com/feed.xml

- type: block
  rules:
    title: Advertisement

- type: replace
  attribute: title
  search: "Example:"
  replace: "News:"
```

With the supplied web entry point, `index.php?pipe=news` returns RSS and `index.php?pipe=news&format=json` returns JSON. Visiting `index.php` without `pipe` lists available pipes. [HTTP behavior](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/View.php)

## Sections

| `type` | Configuration | What it does |
| --- | --- | --- |
| `rss` | `url`; optional `showErrors` | Fetches and parses an RSS feed. Appends its items to the current feed. On a fetch failure, adds an error item by default; set `showErrors: 0` to suppress it. [Source](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Rss.php) |
| `atom` | `url`; optional `showErrors` | Fetches an Atom feed and converts entries into the internal RSS item fields. Atom `id` becomes `guid`, `content` becomes `description`, and `updated` becomes `pubDate`. It inherits `rss` fetch error handling. [Source](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Atom.php) |
| `filter` | `rules` | Keeps matching items. [Source](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Filter.php) |
| `block` | `rules` | Removes matching items. [Source](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Block.php) |
| `replace` | `attribute`, `search`, `replace` | Replaces every exact occurrence of `search` in the selected field of every item. Both `search` and `replace` must be nonempty strings. [Source](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Replace.php) |
| `callback` | `feed` **or** `item` | Calls PHP code to modify the whole feed or each item. If both are supplied, only `feed` runs. [Source](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Callback.php) |
| `pipe` | `config` | Runs an **inline nested configuration** and appends its resulting items. It does not look up another pipe by filename, and it does not copy that pipe’s metadata. [Source](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Pipe.php) |

### Matching rules for `filter` and `block`

`rules` maps item fields to text rules. Use fields such as `title`, `description`, or `link`.

```yaml
- type: filter
  rules:
    title: Python
    description:
      - tutorial
      - guide
```

This keeps items whose title contains “Python” and whose description contains either “tutorial” or “guide”. Different fields are checked in sequence, so an item must match each field; alternatives within one field are OR. For `block`, matching any configured field removes the item. Plain text matching is case insensitive and looks for a substring. A rule beginning with `/` is treated as a PHP regular expression when valid:

```yaml
- type: block
  rules:
    title:
      - "/^Sponsored:/i"
      - Advertisement
```

Nested lists alternate OR and AND. For example, `title: [[Python, tutorial]]` matches a title containing both words. Invalid regular expressions fall back to literal substring matching. [Matching implementation](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Block.php)

### `replace` fields

`attribute` must be one of `guid`, `pubDate`, `title`, `link`, `description`, or `comments`. Replacement is case sensitive because it uses PHP’s `str_replace`; it does not accept a regular expression. An empty replacement string is rejected by validation, so this section cannot delete matched text. [Replace implementation](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Replace.php)

### Nested pipe example

```yaml
- type: pipe
  config:
    - type: rss
      url: https://example.com/first.xml
    - type: filter
      rules:
        title: Science

- type: atom
  url: https://example.com/second.atom
```

The nested pipe’s items are appended first, followed by the Atom entries. There is no automatic sorting or deduplication. [Nested pipe](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Pipe.php), [execution order](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/Pipe.php)

## Practical limits

- Pipe files are `.yml` by default. PHP pipe files require calling `View::run($pipesDir, true)`; use that option only for trusted configurations because PHP files are included and executed. [Controller](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/Controller.php)
- `callback` is most useful in a PHP configuration, where you can supply a callable. Its functions modify the feed or item objects in place. [Callback implementation](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/sections/Callback.php)
- JSON output contains feed metadata and the six standard item data fields. The RSS builder likewise writes those fields; parsed item attributes and categories are not emitted by these builders. [JSON builder](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/builder/JsonBuilder.php), [RSS builder](https://github.com/urmaul/rss-pipes/blob/9e11e9a5315e8d11764c2784c02ffa02dee5639f/src/builder/RssBuilder.php)
