<?php

namespace rsspipestests\sections;

use rsspipes\builder\JsonBuilder;
use rsspipes\builder\RssBuilder;
use rsspipes\rss\Feed;
use rsspipes\rss\Item;
use rsspipes\sections\PageArticle;

class ArticleFormattingTest extends \PHPUnit_Framework_TestCase
{
    private function section($body)
    {
        $section = new FormattingArticleStub();
        $section->body = $body;
        return $section;
    }

    private function item()
    {
        return new Item(['link' => 'https://example.com/news/story?from=rss', 'description' => 'Feed excerpt']);
    }

    /** @dataProvider formattingProvider */
    public function testPreservesSemanticMarkupAndDropsUnsafeOrDecorativeMarkup($body, $expected)
    {
        $section = $this->section('<section itemprop="articleBody">' . $body . '</section>');
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame($expected, $item->description);
    }

    public function formattingProvider()
    {
        return [
            ['<h2 class="heading">Heading</h2><p><strong>Bold ingress.</strong></p><p>Body <em>emphasis</em>.</p>',
                "<h2>Heading</h2>\n<p><strong>Bold ingress.</strong></p>\n<p>Body <em>emphasis</em>.</p>"],
            ['<h3>Subheading</h3><ul><li>One</li><li><b>Two</b><ul><li>Nested</li></ul></li></ul><ol><li>Three</li></ol>',
                "<h3>Subheading</h3>\n<ul><li>One</li><li><strong>Two</strong><ul><li>Nested</li></ul></li></ul>\n<ol><li>Three</li></ol>"],
            ['<blockquote><p>A quote.</p><p><i>More.</i></p></blockquote>',
                '<blockquote><p>A quote.</p><p><em>More.</em></p></blockquote>'],
            ['<p>First<br>Second</p><hr><p>Third</p>', "<p>First<br>Second</p>\n<hr>\n<p>Third</p>"],
            ["<pre><code>  &lt;example&gt;\n    code &amp; text\n</code></pre>",
                "<pre><code>  &lt;example&gt;\n    code &amp; text\n</code></pre>"],
            ['<div><span>Before</span></div><section><p>After <span class="font-bold">ordinary text</span>.</p></section>',
                "<p>Before</p>\n<p>After ordinary text.</p>"],
            ['<p onclick="bad()" style="color:red" id="unsafe">Visible <strong onmouseover="bad()" class="x">bold</strong>.</p>' .
                '<script>bad()</script><style>p { display:none }</style><iframe src="https://bad.example/">Frame</iframe>' .
                '<svg onload="bad()"><text>SVG</text></svg><object data="javascript:bad()">Object</object>' .
                '<math><mtext>Math payload</mtext></math><form>Subscribe</form><p hidden>Hidden</p>',
                '<p>Visible <strong>bold</strong>.</p>'],
            ['<p>&lt;img src=x onerror=bad&gt; &amp; &lt;strong&gt;literal&lt;/strong&gt;</p>',
                '<p>&lt;img src=x onerror=bad&gt; &amp; &lt;strong&gt;literal&lt;/strong&gt;</p>'],
            ['<p>Before<div><strong>Nested</strong></div>After</p>',
                "<p>Before</p>\n<p><strong>Nested</strong></p>\n<p>After</p>"],
        ];
    }

    /** @dataProvider linksProvider */
    public function testLinksAreResolvedAndOnlyAllowedSchemesSurvive($href, $expectedHref)
    {
        $section = $this->section('<section itemprop="articleBody"><p><a onclick="bad()" target="_blank" style="color:red" href="' .
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">Read &amp; see</a></p></section>');
        $item = $this->item();
        $section->processItem($item);
        $expected = $expectedHref === null ? 'Read &amp; see' : '<a href="' .
            htmlspecialchars($expectedHref, ENT_QUOTES, 'UTF-8') . '">Read &amp; see</a>';
        $this->assertSame('<p>' . $expected . '</p>', $item->description);
    }

    public function linksProvider()
    {
        return [
            ['https://other.example/path?q=1&lang=sv', 'https://other.example/path?q=1&lang=sv'],
            ['mailto:reader@example.com', 'mailto:reader@example.com'],
            ['/about', 'https://example.com/about'],
            ['next', 'https://example.com/news/next'],
            ['../about?q=one&lang=sv#part', 'https://example.com/about?q=one&lang=sv#part'],
            ['#part', 'https://example.com/news/story?from=rss#part'],
            ['?page=2', 'https://example.com/news/story?page=2'],
            ['//other.example/path', 'https://other.example/path'],
            ['./', 'https://example.com/news/'],
            ['../..', 'https://example.com/'],
            ['/a//b', 'https://example.com/a//b'],
            ['javascript:alert(1)', null],
            ["java\nscript:alert(1)", null],
            ['data:text/html,<script>bad()</script>', null],
            ['vbscript:bad()', null],
            ['file:///etc/passwd', null],
            ['ftp://other.example/file', null],
            ['https:/invalid', null],
            ['\\\\other.example', null],
        ];
    }

    public function testHtmlEntitiesCannotHideAnUnsafeLinkScheme()
    {
        $section = $this->section('<section itemprop="articleBody"><p><a href="jav&#x61;script:bad()">Visible link text</a></p></section>');
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>Visible link text</p>', $item->description);
    }

    public function testXPathSelectionPreservesTheSelectedHeadingItself()
    {
        $section = $this->section('<h2>Selected heading</h2><p>Other text</p>');
        $section->bodyXPath = '//h2';
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<h2>Selected heading</h2>', $item->description);
    }

    public function testStructuredArticleHtmlPreservesFormattingAndRelativeLinks()
    {
        $body = '<h2>Heading</h2><p><b>Ingress.</b> <a href="../about">More</a></p>';
        $section = $this->section('<script type="application/ld+json">' . json_encode([
            '@type' => 'NewsArticle', 'articleBody' => $body,
        ]) . '</script>');
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame("<h2>Heading</h2>\n<p><strong>Ingress.</strong> <a href=\"https://example.com/about\">More</a></p>", $item->description);
    }

    public function testReadabilityPreservesHeadingsAndEmphasis()
    {
        $paragraph = str_repeat('Article text, with information about the event. ', 15);
        $section = $this->section('<html><head><title>Main article title</title></head><body><article>' .
            '<h1>Main article title</h1><p><strong>Bold ingress.</strong></p><h2>Body heading</h2>' .
            '<p>' . $paragraph . '<em>Important detail.</em></p></article></body></html>');
        $item = $this->item();
        $section->processItem($item);
        $this->assertContains('<strong>Bold ingress.</strong>', $item->description);
        $this->assertContains('<h2>Body heading</h2>', $item->description);
        $this->assertContains('<em>Important detail.</em>', $item->description);
    }

    public function testRichHtmlAndPaywallNoteSurviveRssJsonAndCacheWithoutDoubleEscaping()
    {
        $directory = sys_get_temp_dir() . '/rss-pipes-formatting-' . uniqid('', true);
        mkdir($directory);
        try {
            $section = $this->section('<script type="application/ld+json">{"@type":"NewsArticle","isAccessibleForFree":false}</script>' .
                '<section itemprop="articleBody"><h2>ÅÄÖ &amp; news</h2><p><strong>Ingress.</strong></p></section>');
            $section->cache = true;
            $section->cacheDir = $directory;
            $feed = new Feed();
            $feed->items[] = $this->item();
            $section->processFeed($feed);
            $expected = "<h2>ÅÄÖ &amp; news</h2>\n<p><strong>Ingress.</strong></p>\n<p>Betalartikel</p>";
            $this->assertSame($expected, $feed->items[0]->description);
            $xml = new \SimpleXMLElement((new RssBuilder())->buildFeed($feed));
            $this->assertSame($expected, (string) $xml->channel->item->description);
            $json = json_decode((new JsonBuilder())->buildFeed($feed), true);
            $this->assertSame($expected, $json['items'][0]['description']);
            $section->body = false;
            $section->processFeed($feed);
            $this->assertSame($expected, $feed->items[0]->description);
            $this->assertSame(1, $section->requests);
        } finally {
            foreach (glob($directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testMetadataFallbackRemainsTextRatherThanExecutableMarkup()
    {
        $section = $this->section('<meta name="description" content="&lt;strong onclick=bad()&gt;Text&lt;/strong&gt;">');
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame('<p>&lt;strong onclick=bad()&gt;Text&lt;/strong&gt;</p>', $item->description);
    }
}

class FormattingArticleStub extends PageArticle
{
    public $cache = false;
    public $body;
    public $requests = 0;

    protected function fetchPage($url)
    {
        $this->requests++;
        return $this->body;
    }
}
