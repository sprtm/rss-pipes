<?php

namespace rsspipestests\sections;

use rsspipes\rss\Feed;
use rsspipes\rss\Item;
use rsspipes\sections\Atom;
use rsspipes\sections\PageArticle;
use rsspipes\sections\Rss;
use rsspipes\sections\Section;

class PageArticleTest extends \PHPUnit_Framework_TestCase
{
    private $cacheDir;

    protected function setUp()
    {
        $this->cacheDir = sys_get_temp_dir() . '/rss-pipes-article-test-' . uniqid('', true);
        mkdir($this->cacheDir);
    }

    protected function tearDown()
    {
        if (is_dir($this->cacheDir . '/seen')) {
            foreach (glob($this->cacheDir . '/seen/*') as $marker) {
                unlink($marker);
            }
            rmdir($this->cacheDir . '/seen');
        }
        foreach (glob($this->cacheDir . '/*') as $path) {
            unlink($path);
        }
        rmdir($this->cacheDir);
    }

    private function item($description = 'Feed excerpt', $url = 'https://example.com/article')
    {
        return new Item(['link' => $url, 'description' => $description]);
    }

    /** @dataProvider articlesProvider */
    public function testArticleExtractionAndFallbacks($html, $expected)
    {
        $section = new PageArticleStub();
        $section->body = $html;
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame($expected, $item->description);
        $this->assertSame(['https://example.com/article'], $section->urls);
    }

    public function articlesProvider()
    {
        return [
            ['<meta name="description" content="Summary"><section itemprop="articleBody"><p>First <b>bold </b>word.</p><p>ÅÄÖ &amp; blåbär.</p></section>', '<p>First <strong>bold </strong>word.</p>' . "\n" . '<p>ÅÄÖ &amp; blåbär.</p>'],
            ['<div itemprop="text articleBody"><p>First</p><div itemprop="articleBody"><p>Second</p></div></div>', "<p>First</p>\n<p>Second</p>"],
            ['<section itemprop="articleBody"><p>First</p></section><section itemprop="articleBody"><p>Second</p></section>', "<p>First</p>\n<p>Second</p>"],
            ['<section itemprop="articleBody"><table><tr><th>Name</th><th>Score</th></tr><tr><td>Club</td><td>3</td></tr></table></section>', '<table><tr><th>Name</th><th>Score</th></tr><tr><td>Club</td><td>3</td></tr></table>'],
            ['<section itemprop="articleBody"><time>Published yesterday</time><p>Actual story.</p><figure><figcaption>Video promotion and photo credit.</figcaption></figure><p>Final paragraph.</p></section>', "<p>Actual story.</p>\n<p>Final paragraph.</p>"],
            ['<section itemprop="articleBody"><p>Line<br>break</p><p><i>More</i> text.</p><script>alert(1)</script><nav>Menu</nav><aside>Related</aside><form>Subscribe</form><footer>Footer</footer><p hidden>Hidden</p><p aria-hidden="true">Hidden</p></section>', "<p>Line<br>break</p>\n<p><em>More</em> text.</p>"],
            ['<script type="application/ld+json">{"@graph":[{"@type":"NewsArticle","articleBody":"First paragraph.\n\nSecond &amp; final paragraph."}]}</script>', "<p>First paragraph.</p>\n<p>Second &amp; final paragraph.</p>"],
            ['<script type="application/ld+json">[{"@type":["Thing","https://schema.org/BlogPosting"],"articleBody":"<p>Café</p><p>日本語</p>"}]</script>', "<p>Café</p>\n<p>日本語</p>"],
            ['<script type="application/ld+json">{"@type":"Product","articleBody":"Unrelated"}</script><meta name="description" content="Summary">', '<p>Summary</p>'],
            ['<script type="application/ld+json">invalid</script><meta property="og:description" content="OpenGraph"><meta name="description" content="Standard">', '<p>OpenGraph</p>'],
            ['<script type="application/ld+json">{"@type":"Article","articleBody":"&nbsp;"}</script><meta name="description" content="Summary">', '<p>Summary</p>'],
            ['<section itemprop="articleBody"><script>hidden()</script></section><meta name="twitter:description" content="Twitter">', '<p>Twitter</p>'],
            ["<meta charset='ISO-8859-1'><section itemprop='articleBody'><p>\xC5\xC4\xD6 \xE5\xE4\xF6</p></section>", '<p>ÅÄÖ åäö</p>'],
            ['<article><p>Too short for heuristic extraction.</p></article><meta name="description" content="Summary">', '<p>Summary</p>'],
            ['<html><body><nav>Menu</nav></body></html>', 'Feed excerpt'],
            ['', 'Feed excerpt'],
            [false, 'Feed excerpt'],
        ];
    }

    public function testReadabilityFindsArticleWithoutPublisherSelectors()
    {
        $paragraph = str_repeat('This is an article sentence, with useful information. ', 12);
        $section = new PageArticleStub();
        $section->body = '<html><head><meta name="description" content="Summary"></head><body>' .
            '<nav><a href="/">Navigation</a></nav><article><p>' . $paragraph . '</p><p>Final paragraph.</p></article>' .
            '<aside><a href="/other">Unrelated stories</a></aside><footer>Site footer</footer></body></html>';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>' . trim($paragraph) . "</p>\n<p>Final paragraph.</p>", $item->description);
    }

    public function testHtmlParagraphsSurviveRssJsonAndCacheWithoutExposingSourceMarkup()
    {
        $section = new PageArticleStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = '<section itemprop="articleBody"><p>First &amp; second.</p>' .
            '<p>&lt;img src=x onerror=bad&gt;<br>Final.</p></section>';
        $expected = "<p>First &amp; second.</p>\n<p>&lt;img src=x onerror=bad&gt;<br>Final.</p>";
        $feed = new Feed();
        $feed->items[] = $this->item();
        $section->processFeed($feed);
        $this->assertSame($expected, $feed->items[0]->description);

        $xml = new \SimpleXMLElement((new \rsspipes\builder\RssBuilder())->buildFeed($feed));
        $this->assertSame($expected, (string) $xml->channel->item->description);
        $json = json_decode((new \rsspipes\builder\JsonBuilder())->buildFeed($feed), true);
        $this->assertSame($expected, $json['items'][0]['description']);

        $section->body = false;
        $section->processFeed($feed);
        $this->assertSame($expected, $feed->items[0]->description);
        $this->assertCount(1, $section->urls);
    }

    /** @dataProvider linkedPostsProvider */
    public function testLiveBlogKeepsFeedExcerptEvenWhenPostMarkupIsAvailable($attribute)
    {
        $section = new PageArticleStub();
        $section->body = '<meta name="description" content="Whole blog summary"><main itemprop="articleBody">' .
            '<section data-post-id="other"><p>Unrelated story.</p></section>' .
            '<section ' . $attribute . '><time>08:00</time><p>Requested update.</p><p>Second paragraph.</p></section>' .
            '<section><p>Another unrelated story.</p></section></main>';
        $item = $this->item('Original excerpt', 'https://example.com/live?post=selected');
        $section->processItem($item);
        $this->assertSame('Original excerpt', $item->description);
        $this->assertSame([], $section->urls);
    }

    public function linkedPostsProvider()
    {
        return [['id="selected"'], ['id="post-selected"'], ['id="entry-selected"'],
            ['data-id="selected"'], ['data-post-id="selected"'], ['data-entry-id="selected"']];
    }

    public function testLivePostKeepsEachFeedExcerptWithoutFetchingOrCaching()
    {
        $section = new PageArticleStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = '<meta name="description" content="Wrong blog summary">' .
            '<script type="application/ld+json">{"@type":"NewsArticle","articleBody":"Entire live blog"}</script>' .
            '<main itemprop="articleBody"><p>' . str_repeat('Unrelated updates. ', 40) . '</p></main>';
        $item = $this->item('Correct feed excerpt', 'https://example.com/live?post=missing');
        $section->processItem($item);
        $this->assertSame('Correct feed excerpt', $item->description);
        $item = $this->item('Another excerpt', 'https://example.com/live?post=missing');
        $section->processItem($item);
        $this->assertSame('Another excerpt', $item->description);
        $this->assertSame([], $section->urls);
        $this->assertSame([], glob($this->cacheDir . '/*'));
    }

    /** @dataProvider oldArticleCachePrefixesProvider */
    public function testPreviousArticleCacheVersionIsIgnored($prefix)
    {
        $url = 'https://example.com/article';
        file_put_contents($this->cacheDir . '/' . $prefix . hash('sha256', $url) . '.json',
            json_encode(['expiresAt' => 100000, 'description' => 'Old noisy extraction']));
        $section = new PageArticleStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = '<section itemprop="articleBody"><p>Clean article.</p></section>';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>Clean article.</p>', $item->description);
        $this->assertCount(1, $section->urls);
    }

    public function oldArticleCachePrefixesProvider()
    {
        return [['article-v1-'], ['article-v2-'], ['article-v3-'], ['article-v4-']];
    }

    public function testPublisherOverrideAndMissingOverrideFallback()
    {
        $section = new PageArticleStub();
        $section->bodyXPath = '//div[@id="story"]';
        $section->init();
        $section->body = '<div id="story"><p>Selected article.</p></div><section itemprop="articleBody">Other article.</section>';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>Selected article.</p>', $item->description);
        $section->bodyXPath = '//div[@id="missing"]';
        $section->processItem($item);
        $this->assertSame('<p>Other article.</p>', $item->description);
    }

    public function testReadabilityPreservesLegacyPageEncoding()
    {
        $paragraph = str_repeat('ÅÄÖ åäö är svensk text, med flera meningar. ', 12);
        $section = new PageArticleStub();
        $section->body = mb_convert_encoding('<html><head><meta charset="ISO-8859-1"></head><body><article><p>' .
            $paragraph . '</p></article></body></html>', 'ISO-8859-1', 'UTF-8');
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>' . trim($paragraph) . '</p>', $item->description);
    }

    public function testMissingArticleDoesNotExtractLongSiteFooter()
    {
        $section = new PageArticleStub();
        $section->body = '<html><head><meta name="description" content="Article summary"></head><body>' .
            '<main><article><p>Short introduction.</p></article><div class="site-footer"><p>' .
            str_repeat('This newspaper reports on local and world news. ', 20) . '</p></div></main></body></html>';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>Article summary</p>', $item->description);
    }

    public function testReadabilityExcludesPrintHeadersAndEditorialNotices()
    {
        $paragraph = str_repeat('This is the article text, with useful information. ', 12);
        $section = new PageArticleStub();
        $section->body = '<article><div class="article__print-header"><p>Printed copy from the newspaper.</p></div>' .
            '<div class="NoScriptMessage__root"><p>Enable Javascript.</p></div><p>' . $paragraph . '</p>' .
            '<section class="TransparencyBox__root"><p>' . str_repeat('Our editorial policy. ', 20) . '</p></section></article>';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>' . trim($paragraph) . '</p>', $item->description);
    }

    public function testArticleSummaryBoxIsExcludedFromAllDomExtractionPaths()
    {
        $paragraph = str_repeat('This article discusses Snabbversionen, with useful information. ', 12);
        foreach (['markup', 'override', 'readability'] as $method) {
            $section = new PageArticleStub();
            if ($method === 'override') {
                $section->bodyXPath = '//article';
            }
            $section->body = '<article' . ($method === 'markup' ? ' itemprop="articleBody"' : '') . '>' .
                '<p>' . $paragraph . '</p><section data-track-id="article-summary">' .
                '<h2>Snabbversionen</h2><ul><li>Generated summary of the story.</li></ul></section>' .
                '<h2>Article summary</h2><p>Final article paragraph.</p></article>';
            $item = $this->item();
            $section->processItem($item);
            $this->assertSame('<p>' . trim($paragraph) . '</p>' . "\n<h2>Article summary</h2>\n<p>Final article paragraph.</p>",
                $item->description, $method);
        }
    }

    public function testAffiliateCarouselCannotReplaceArticleOrMetadataFallback()
    {
        $ad = '<div data-track-id="affiliate-ad-carousel" data-track-element-type="AffiliateAdCarousel">' .
            '<p>Annons</p><p>Hitta ett mobilabonnemang som passar dig!</p>' .
            str_repeat('<a href="https://example.com/offer"><p>Mobile subscription offer.</p></a>', 30) . '</div>';
        $paragraph = str_repeat('This is the news article, with useful information. ', 12);
        foreach (['markup', 'override', 'readability', 'fallback'] as $method) {
            $section = new PageArticleStub();
            if ($method === 'override') {
                // An override matching only the ad must fall through to the body.
                $section->bodyXPath = '//*[@data-track-id="affiliate-ad-carousel"]';
            }
            $section->body = '<html><head><meta name="description" content="News summary"></head><body><article' .
                (in_array($method, ['markup', 'override'], true) ? ' itemprop="articleBody"' : '') . '>' .
                $ad . ($method === 'fallback' ? '' : '<p>' . $paragraph . '</p>') . '</article></body></html>';
            $item = $this->item();
            $section->processItem($item);
            $this->assertSame($method === 'fallback' ? '<p>News summary</p>' : '<p>' . trim($paragraph) . '</p>',
                $item->description, $method);
        }
    }

    public function testMissingPrimaryArticleDoesNotExtractRelatedArticle()
    {
        $section = new PageArticleStub();
        $section->body = '<html><head><meta name="description" content="Primary summary"></head><body>' .
            '<article><h1>Primary headline</h1><p>Short introduction.</p></article><article><h2>Related headline</h2><p>' .
            str_repeat('A different story, linked beneath the main article. ', 20) . '</p></article></body></html>';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>Primary summary</p>', $item->description);
    }

    public function testArticleCacheIsSharedExpiresAndDoesNotCollideWithDescriptions()
    {
        $url = 'https://example.com/article';
        $descriptionPath = $this->cacheDir . '/' . hash('sha256', $url) . '.json';
        file_put_contents($descriptionPath, json_encode(['expiresAt' => 100000, 'description' => 'Cached summary']));

        $first = new PageArticleStub();
        $first->cache = true;
        $first->cacheDir = $this->cacheDir;
        $first->body = '<section itemprop="articleBody"><p>Cached article.</p></section>';
        $first->processItem($this->item());
        $this->assertCount(1, $first->urls);
        $this->assertCount(2, glob($this->cacheDir . '/*'));
        $this->assertSame('Cached summary', json_decode(file_get_contents($descriptionPath), true)['description']);

        $next = new PageArticleStub();
        $next->cache = true;
        $next->cacheDir = $this->cacheDir;
        $next->now += 86399;
        $next->body = '<section itemprop="articleBody">Updated article.</section>';
        $item = $this->item();
        $next->processItem($item);
        $this->assertSame('<p>Cached article.</p>', $item->description);
        $this->assertSame([], $next->urls);
        $next->now++;
        $next->processItem($item);
        $this->assertSame('<p>Updated article.</p>', $item->description);
        $this->assertCount(1, $next->urls);
    }

    public function testFailureCacheKeepsEachItemsDescriptionAndRetries()
    {
        $section = new PageArticleStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = false;
        $section->processItem($this->item());
        $section->body = '<section itemprop="articleBody">Recovered article.</section>';
        $section->now += 299;
        $item = $this->item('Another feed excerpt');
        $section->processItem($item);
        $this->assertSame('Another feed excerpt', $item->description);
        $this->assertCount(1, $section->urls);
        $section->now++;
        $section->processItem($item);
        $this->assertSame('<p>Recovered article.</p>', $item->description);
        $this->assertCount(2, $section->urls);
    }

    public function testDifferentPublisherOverridesHaveSeparateCacheEntries()
    {
        $section = new PageArticleStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = '<div id="first">First article.</div><div id="second">Second article.</div>';
        $section->bodyXPath = '//div[@id="first"]';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>First article.</p>', $item->description);
        $section->bodyXPath = '//div[@id="second"]';
        $section->processItem($item);
        $this->assertSame('<p>Second article.</p>', $item->description);
        $this->assertCount(2, $section->urls);
        $this->assertCount(2, glob($this->cacheDir . '/*'));
    }

    public function testMetadataFallbackIsCachedWithoutAnotherRequest()
    {
        $section = new PageArticleStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = '<meta name="description" content="Page summary">';
        $item = $this->item();
        $section->processItem($item);
        $section->body = false;
        $section->processItem($item);
        $this->assertSame('<p>Page summary</p>', $item->description);
        $this->assertCount(1, $section->urls);
    }

    public function testRssAndAtomItemsUseTheSameExtractor()
    {
        $rss = '<rss version="2.0"><channel><item><link>https://example.com/article</link><description>RSS excerpt</description></item></channel></rss>';
        $atom = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><id>article</id><link href="https://example.com/article"/><content>Atom excerpt</content></entry></feed>';
        foreach ([[new Rss(), $rss], [new Atom(), $atom]] as $source) {
            $feed = new Feed();
            $source[0]->parseBody($source[1], $feed);
            $feed->items[0]->link .= $source[0] instanceof Atom ? "/atom" : "/rss";
            $section = new PageArticleStub();
            $section->cacheDir = $this->cacheDir;
            $section->body = '<section itemprop="articleBody">Article text.</section>';
            $section->processFeed($feed);
            $this->assertSame('<p>Article text.</p>', $feed->items[0]->description);
        }
    }

    public function testOnlyHttpLinksAreFetchedAndLibxmlModeIsRestored()
    {
        $previous = libxml_use_internal_errors(false);
        try {
            $section = new PageArticleStub();
            $section->body = '<article><p>Unclosed';
            foreach ([null, '', '/relative', 'file:///etc/passwd', 'ftp://example.com/article', 'https://example.com/article'] as $url) {
                $section->processItem($this->item('Feed excerpt', $url));
            }
            $this->assertSame(['https://example.com/article'], $section->urls);
            $this->assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function testSectionCanBeConfigured()
    {
        $this->assertInstanceOf(PageArticle::class, Section::create(['type' => 'pageArticle']));
    }

    /**
     * @dataProvider invalidConfigsProvider
     * @expectedException rsspipes\sections\exceptions\InvalidConfigException
     */
    public function testInvalidConfig($options)
    {
        Section::create(['type' => 'pageArticle'] + $options);
    }

    public function testOmittedLimitFetchesAllUncachedArticles()
    {
        $section = new PageArticleStub();
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = '<article itemprop="articleBody">Story</article>';
        $feed = new Feed();
        foreach (range(1, 7) as $id) {
            $feed->items[] = $this->item('Excerpt', 'https://example.com/' . $id);
        }
        $section->processFeed($feed);
        $this->assertCount(7, $section->urls);
        $section->processFeed($feed);
        $this->assertCount(7, $section->urls);
    }

    public function testOnlyNewStoriesUseTheFetchLimit()
    {
        $section = new PageArticleStub();
        $section->maxFetches = 2;
        $section->cache = true;
        $section->cacheDir = $this->cacheDir;
        $section->body = '<article itemprop="articleBody">Story</article>';
        foreach ([[3, 2, 1], [3, 2, 1], [4, 3, 2, 1]] as $ids) {
            $feed = new Feed();
            foreach ($ids as $id) {
                $feed->items[] = $this->item('Excerpt', 'https://www.dn.se/' . $id);
            }
            $section->processFeed($feed);
            if (count($ids) === 3) {
                $this->assertSame('Excerpt', $feed->items[2]->description);
            }
        }
        $this->assertSame(['https://www.dn.se/3', 'https://www.dn.se/2',
            'https://www.dn.se/4'], $section->urls);
        $this->assertSame('Excerpt', $feed->items[3]->description);
        foreach (array_slice($feed->items, 0, 3) as $item) {
            $this->assertContains('Story', $item->description);
        }
    }

    public function invalidConfigsProvider()
    {
        return [[['timeout' => 0]], [['cache' => 'false']], [['bodyXPath' => '']], [['bodyXPath' => []]], [['bodyXPath' => '//*[']]];
    }
}

class PageArticleStub extends PageArticle
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
