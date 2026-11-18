<?php

namespace rsspipestests\sections;

use rsspipes\rss\Feed;
use rsspipes\rss\Item;
use rsspipes\sections\PageDescription;
use rsspipes\sections\Section;

class PageDescriptionTest extends \PHPUnit_Framework_TestCase
{
    private $cacheDir;

    protected function setUp()
    {
        $this->cacheDir = sys_get_temp_dir() . '/rss-pipes-test-' . uniqid('', true);
        mkdir($this->cacheDir);
    }

    protected function tearDown()
    {
        foreach (glob($this->cacheDir . '/*') as $path) {
            unlink($path);
        }
        rmdir($this->cacheDir);
    }

    private function cachedSection($body)
    {
        $section = new PageDescriptionStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = $body;
        return $section;
    }

    private function item($url = 'https://example.com/article', $description = 'Feed excerpt')
    {
        return new Item(['link' => $url, 'description' => $description]);
    }

    public function testCacheIsSharedAcrossSectionInstancesAndExpires()
    {
        $first = $this->cachedSection('<meta name="description" content="Cached excerpt">');
        $first->processItem($this->item());

        $next = $this->cachedSection('<meta name="description" content="Updated excerpt">');
        $next->now += 86399;
        $item = $this->item();
        $next->processItem($item);
        $this->assertSame('Cached excerpt', $item->description);
        $this->assertSame([], $next->urls);

        $next->now++;
        $next->processItem($item);
        $this->assertSame('Updated excerpt', $item->description);
        $this->assertCount(1, $next->urls);
    }

    /** @dataProvider missingDescriptionsProvider */
    public function testFailuresKeepEachItemsOwnDescriptionAndRetryAfterFiveMinutes($body)
    {
        $section = $this->cachedSection($body);
        $section->processItem($this->item());
        $section->body = '<meta name="description" content="Recovered">';
        $section->now += 299;
        $item = $this->item('https://example.com/article', 'Another feed excerpt');
        $section->processItem($item);
        $this->assertSame('Another feed excerpt', $item->description);
        $this->assertCount(1, $section->urls);

        $section->now++;
        $section->processItem($item);
        $this->assertSame('Recovered', $item->description);
        $this->assertCount(2, $section->urls);
    }

    public function missingDescriptionsProvider()
    {
        return [[false], [''], ['<html><body>No metadata</body></html>']];
    }

    public function testDifferentUrlsAreCachedSeparately()
    {
        $section = $this->cachedSection('<meta name="description" content="First">');
        $section->processItem($this->item());
        $section->body = '<meta name="description" content="Second">';
        $item = $this->item('https://example.com/other');
        $section->processItem($item);
        $this->assertSame('Second', $item->description);
        $this->assertCount(2, $section->urls);
    }

    public function testCorruptCacheIsRefetchedAndReplaced()
    {
        $path = $this->cacheDir . '/' . hash('sha256', 'https://example.com/article') . '.json';
        file_put_contents($path, '{broken');
        $section = $this->cachedSection('<meta name="description" content="Fetched">');
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('Fetched', $item->description);
        $this->assertSame('Fetched', json_decode(file_get_contents($path), true)['description']);
    }

    public function testUnavailableCacheDirectoryDoesNotPreventEnrichment()
    {
        $section = $this->cachedSection('<meta name="description" content="Fetched">');
        $path = $this->cacheDir . '/file';
        file_put_contents($path, 'Not a directory');
        $section->cacheDir = $path . '/cache';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('Fetched', $item->description);
    }

    public function testCacheCanBeDisabled()
    {
        $section = $this->cachedSection('<meta name="description" content="Fetched">');
        $section->cache = false;
        $section->processItem($this->item());
        $section->processItem($this->item());
        $this->assertCount(2, $section->urls);
        $this->assertSame([], glob($this->cacheDir . '/*'));
    }

    public function testCustomTtlsAndZeroTtl()
    {
        $section = $this->cachedSection('<meta name="description" content="Fetched">');
        $section->cacheTtl = 10;
        $section->failureCacheTtl = 0;
        $section->processItem($this->item());
        $section->now += 10;
        $section->body = false;
        $section->processItem($this->item());
        $section->processItem($this->item());
        $this->assertCount(3, $section->urls);
    }

    /**
     * @dataProvider invalidCacheConfigsProvider
     * @expectedException rsspipes\sections\exceptions\InvalidConfigException
     */
    public function testInvalidCacheConfig($options)
    {
        Section::create(['type' => 'pageDescription'] + $options);
    }

    public function invalidCacheConfigsProvider()
    {
        return [
            [['timeout' => 0]], [['timeout' => -1]], [['timeout' => '5']],
            [['cacheTtl' => -1]], [['failureCacheTtl' => -1]],
            [['cache' => 'false']], [['cacheDir' => '']], [['cacheDir' => []]],
        ];
    }

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
            ['<html><head>' . str_repeat(' ', 2048) . '<meta charset="UTF-8"><meta property="og:description" content="ÅÄÖ åäö – för första gången"></head></html>', 'ÅÄÖ åäö – för första gången'],
            ['<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta property="og:description" content="ÅÄÖ åäö">', 'ÅÄÖ åäö'],
            ["<meta charset='ISO-8859-1'><meta property='og:description' content='\xC5\xC4\xD6 \xE5\xE4\xF6'>", 'ÅÄÖ åäö'],
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
    public $cache = false;
    public $now = 1000;
    public $body;
    public $urls = [];

    protected function currentTime()
    {
        return $this->now;
    }

    protected function fetchPage($url)
    {
        $this->urls[] = $url;
        return $this->body;
    }
}
