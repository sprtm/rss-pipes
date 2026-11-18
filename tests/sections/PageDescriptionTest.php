<?php

namespace rsspipestests\sections;

use rsspipes\rss\Feed;
use rsspipes\rss\Item;
use rsspipes\sections\PageDescription;
use rsspipes\sections\Section;

class PageDescriptionTest extends \PHPUnit_Framework_TestCase
{
    public function testSectionCanBeConfigured()
    {
        $this->assertInstanceOf(PageDescription::class, Section::create(['type' => 'pageDescription']));
    }

    /** @dataProvider descriptionsProvider */
    public function testPageDescriptions($body, $expected)
    {
        $section = new PageDescriptionStub();
        $section->body = $body;
        $item = new Item(['link' => 'https://example.com/article', 'description' => 'Feed excerpt']);

        $section->processItem($item);

        $this->assertSame($expected, $item->description);
        $this->assertSame(['https://example.com/article'], $section->urls);
    }

    public function descriptionsProvider()
    {
        return [
            ['<meta name="description" content="Standard"><meta name="twitter:description" content="Twitter"><meta property="og:description" content="Full article excerpt">', 'Full article excerpt'],
            ['<meta property="og:description" content=" "><meta name="description" content="Standard"><meta name="twitter:description" content="Twitter">', 'Twitter'],
            ['<meta name="description" content="Standard">', 'Standard'],
            ["<META CONTENT='  Café &amp; blåbär  ' PROPERTY='OG:DESCRIPTION'>", 'Café & blåbär'],
            ['<meta charset="utf-8"><meta property="og:description" content="日本語">', '日本語'],
            ['<meta name="og:description" content="Short">', 'Short'],
            ['<meta property="og:description" content="First"><meta property="og:description" content="Second">', 'First'],
            ['<html><head><meta property="og:description" content="Excerpt"></head><body><p>Unclosed', 'Excerpt'],
            ['<meta property="og:description" content=" ">', 'Feed excerpt'],
            ['<html><body>No metadata</body></html>', 'Feed excerpt'],
            ['', 'Feed excerpt'],
            [false, 'Feed excerpt'],
        ];
    }

    public function testOnlyHttpLinksAreFetched()
    {
        $section = new PageDescriptionStub();
        $section->body = '<meta name="description" content="Page excerpt">';
        $feed = new Feed();
        foreach ([null, '', '/relative', 'file:///etc/passwd', 'ftp://example.com/article', 'http://example.com/article'] as $link) {
            $feed->items[] = new Item(['link' => $link, 'description' => 'Feed excerpt']);
        }

        $section->processFeed($feed);

        $this->assertSame(['http://example.com/article'], $section->urls);
        foreach (array_slice($feed->items, 0, 5) as $item) {
            $this->assertSame('Feed excerpt', $item->description);
        }
        $this->assertSame('Page excerpt', $feed->items[5]->description);
    }

    public function testLibxmlErrorModeIsRestored()
    {
        $previous = libxml_use_internal_errors(false);
        try {
            $section = new PageDescriptionStub();
            $section->body = '<html><body><p>Unclosed';
            $section->processItem(new Item(['link' => 'https://example.com/article']));
            $this->assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}

class PageDescriptionStub extends PageDescription
{
    public $body;
    public $urls = [];

    protected function fetchPage($url)
    {
        $this->urls[] = $url;
        return $this->body;
    }
}
