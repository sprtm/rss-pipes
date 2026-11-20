<?php

namespace rsspipestests\sections;

use rsspipes\rss\Item;
use rsspipes\sections\PageArticle;
use rsspipes\sections\PageDescription;

class PaywallTest extends \PHPUnit_Framework_TestCase
{
    private $cacheDir;

    protected function setUp()
    {
        $this->cacheDir = sys_get_temp_dir() . '/rss-pipes-paywall-test-' . uniqid('', true);
        mkdir($this->cacheDir);
    }

    protected function tearDown()
    {
        foreach (glob($this->cacheDir . '/*') as $path) {
            unlink($path);
        }
        rmdir($this->cacheDir);
    }

    private function sections()
    {
        return [new PaywallDescriptionStub(), new PaywallArticleStub()];
    }

    private function item($description = 'Feed excerpt', $url = 'https://example.com/article')
    {
        return new Item(['link' => $url, 'description' => $description]);
    }

    private function html($metadata)
    {
        return '<meta name="description" content="Summary"><script type="application/ld+json">' .
            json_encode($metadata) . '</script><section itemprop="articleBody"><p>Article text.</p></section>';
    }

    /** @dataProvider accessMetadataProvider */
    public function testOnlyExplicitArticleRestrictionsAddTheNote($metadata, $expectedPaid)
    {
        foreach ($this->sections() as $section) {
            $section->body = $this->html($metadata);
            $item = $this->item();
            $section->processItem($item);
            $expected = $section instanceof PageArticle ? '<p>Article text.</p>' : 'Summary';
            if ($expectedPaid) {
                $expected .= "\n<p>Betalartikel</p>";
            }
            $this->assertSame($expected, $item->description);
            $this->assertNull($item->comments);
            $this->assertCount(1, $section->urls);
            $feed = new \rsspipes\rss\Feed();
            $feed->items[] = $item;
            $xml = new \SimpleXMLElement((new \rsspipes\builder\RssBuilder())->buildFeed($feed));
            $this->assertSame($expected, (string) $xml->channel->item->description);
            $json = json_decode((new \rsspipes\builder\JsonBuilder())->buildFeed($feed), true);
            $this->assertSame($expected, $json['items'][0]['description']);
        }
    }

    public function accessMetadataProvider()
    {
        return [
            [['@type' => 'NewsArticle', 'isAccessibleForFree' => false], true],
            [['@type' => 'NewsArticle', 'isAccessibleForFree' => 'False'], true],
            [['@type' => ['Thing', 'https://schema.org/Article'], 'isAccessibleForFree' => ' false '], true],
            [['@graph' => [['@type' => 'BlogPosting', 'isAccessibleForFree' => false]]], true],
            [['@type' => 'WebPage', 'mainEntity' => ['@type' => 'NewsArticle', 'isAccessibleForFree' => false]], true],
            [['@type' => 'NewsArticle', 'isAccessibleForFree' => true,
                'hasPart' => [['@type' => 'WebPageElement', 'isAccessibleForFree' => 'False']]], true],
            [['@type' => 'NewsArticle', 'isAccessibleForFree' => true], false],
            [['@type' => 'NewsArticle', 'isAccessibleForFree' => 'True'], false],
            [['@type' => 'NewsArticle'], false],
            [['@type' => 'NewsArticle', 'isAccessibleForFree' => 'unknown'], false],
            [['@type' => 'NewsArticle', 'isAccessibleForFree' => 0], false],
            [['@type' => 'Event', 'isAccessibleForFree' => false], false],
            [['@type' => 'NewsArticle', 'mentions' => ['@type' => 'NewsArticle', 'isAccessibleForFree' => false]], false],
        ];
    }

    public function testRestrictionIsCachedAcrossInstancesAndExpires()
    {
        foreach ($this->sections() as $first) {
            $first->cache = true;
            $first->cacheDir = $this->cacheDir;
            $first->body = $this->html(['@type' => 'NewsArticle', 'isAccessibleForFree' => false]);
            $first->processItem($this->item());

            $class = get_class($first);
            $next = new $class();
            $next->cache = true;
            $next->cacheDir = $this->cacheDir;
            $next->body = $this->html(['@type' => 'NewsArticle', 'isAccessibleForFree' => true]);
            $item = $this->item();
            $next->processItem($item);
            $expected = $next instanceof PageArticle ? '<p>Article text.</p>' : 'Summary';
            $this->assertSame($expected . "\n<p>Betalartikel</p>", $item->description);
            $next->processItem($item);
            $this->assertSame($expected . "\n<p>Betalartikel</p>", $item->description);
            $this->assertSame([], $next->urls);

            $next->now += 86400;
            $next->processItem($item);
            $this->assertSame($expected, $item->description);
            $this->assertCount(1, $next->urls);
        }
    }

    public function testPaywalledPageWithoutExtractableTextAnnotatesEachOriginalExcerpt()
    {
        foreach ($this->sections() as $section) {
            $section->cache = true;
            $section->cacheDir = $this->cacheDir;
            $section->body = '<script type="application/ld+json">{"@type":"NewsArticle","isAccessibleForFree":false}</script>';
            $item = $this->item('<p>First feed excerpt.</p>');
            $section->processItem($item);
            $this->assertSame("<p>First feed excerpt.</p>\n<p>Betalartikel</p>", $item->description);
            $item = $this->item('<p>Another feed excerpt.</p>');
            $section->processItem($item);
            $this->assertSame("<p>Another feed excerpt.</p>\n<p>Betalartikel</p>", $item->description);
            $this->assertCount(1, $section->urls);

            $section->body = false;
            $item = $this->item('Unrelated excerpt', 'https://example.com/other');
            $section->processItem($item);
            $this->assertSame('Unrelated excerpt', $item->description);
            $section->processItem($this->item());
            $item = $this->item('Still unrelated', 'https://example.com/third');
            $section->processItem($item);
            $this->assertSame('Still unrelated', $item->description);
        }
    }

    public function testOlderCacheEntriesAreRefetchedToDiscoverTheRestriction()
    {
        foreach ($this->sections() as $section) {
            $section->cache = true;
            $section->cacheDir = $this->cacheDir;
            $prefix = $section instanceof PageArticle ? 'article-v2-' : '';
            $path = $this->cacheDir . '/' . $prefix . hash('sha256', 'https://example.com/article') . '.json';
            file_put_contents($path, json_encode(['expiresAt' => 100000, 'description' => 'Old text']));
            $section->body = $this->html(['@type' => 'NewsArticle', 'isAccessibleForFree' => 'False']);
            $section->processItem($this->item());
            $this->assertCount(1, $section->urls);
            $cached = json_decode(file_get_contents($path), true);
            $this->assertTrue($cached['paywalled']);
            $this->assertSame(false, strpos($cached['description'], 'Betalartikel'));
        }
    }
}

trait PaywallStubBehavior
{
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

class PaywallDescriptionStub extends PageDescription
{
    public $cache = false;
    use PaywallStubBehavior;
}

class PaywallArticleStub extends PageArticle
{
    public $cache = false;
    use PaywallStubBehavior;
}
