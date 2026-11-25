<?php

namespace rsspipestests\sections;

use rsspipes\rss\Item;
use rsspipes\sections\PageArticle;

class SubscriberArticleTest extends \PHPUnit_Framework_TestCase
{
    private $directory;
    private $url = 'https://www.sydsvenskan.se/malmo/story/';

    protected function setUp()
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('PHP curl extension required');
        }
        $this->directory = sys_get_temp_dir() . '/rss-pipes-subscriber-' . uniqid('', true);
        mkdir($this->directory);
        file_put_contents($this->directory . '/cookies.txt',
            ".sydsvenskan.se\tTRUE\t/\tTRUE\t0\tsession\tsecret\n");
    }

    protected function tearDown()
    {
        if ($this->directory !== null) {
            foreach (glob($this->directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
    }

    private function section()
    {
        $section = new SubscriberArticleStub();
        $section->cookieFile = $this->directory . '/cookies.txt';
        $section->cacheDir = $this->directory;
        $section->init();
        return $section;
    }

    private function item($url = null)
    {
        return new Item(['link' => $url ?: $this->url, 'description' => 'Feed summary']);
    }

    public function testSubscriberHtmlReplacesArticleAndUsesNormalSanitizingAndCache()
    {
        $section = $this->section();
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame("<p>Full <strong>story</strong>.</p>\n<p>Trolig betalvägg</p>", $item->description);
        $this->assertCount(2, $section->urls);
        $this->assertSame('/api/get-paywall', parse_url($section->urls[1], PHP_URL_PATH));
        $this->assertSame('www.sydsvenskan.se', parse_url($section->urls[1], PHP_URL_HOST));
        parse_str(parse_url($section->urls[1], PHP_URL_QUERY), $parameters);
        $this->assertSame($this->url, $parameters['articleUrl']);
        $this->assertSame('60f71144-4052-4e38-9c96-dea9a6f536c9', $parameters['articleId']);
        $this->assertSame('locked', $parameters['accessRestriction']);
        $this->assertSame('desktop', $parameters['channel']);
        $cached = $this->item();
        $section->processItem($cached);
        $this->assertSame($item->description, $cached->description);
        $this->assertCount(2, $section->urls);
    }

    /** @dataProvider deniedResponsesProvider */
    public function testDeniedOrUnrecognizedContentKeepsOriginalArticle($response)
    {
        $section = $this->section();
        $section->subscriberResponse = $response;
        $item = $this->item();
        $section->processItem($item);
        $this->assertSame("<p>Introduction.</p>\n<p>Trolig betalvägg</p>", $item->description);
        $this->assertCount(2, $section->urls);
    }

    public function deniedResponsesProvider()
    {
        return [
            [['<div>Subscribe now</div>', 200, ['paywallUnlocked' => false]]],
            [['<article class="article"><p>Offer</p></article>', 200]],
            [[false, 403, ['paywallUnlocked' => true]]],
            [['', 200, ['paywallUnlocked' => true]]],
            [['<div>Unexpected response</div>', 200, ['paywallUnlocked' => true]]],
        ];
    }

    public function testDoesNotRequestOtherPublishersOrMarkupUrls()
    {
        $section = $this->section();
        $section->processItem($this->item('https://other.example/article'));
        $this->assertSame(['https://other.example/article'], $section->urls);
    }

    public function testAnonymousRequestsDoNotCallSubscriberEndpoint()
    {
        $section = $this->section();
        $section->cookieFile = null;
        $section->init();
        $section->processItem($this->item());
        $this->assertSame([$this->url], $section->urls);
    }
}

class SubscriberArticleStub extends PageArticle
{
    public $urls = [];
    public $subscriberResponse = [
        '<article class="article"><section itemprop="articleBody"><p>Full <b>story</b>.</p><script>bad()</script></section></article>',
        200, ['paywallUnlocked' => true],
    ];

    protected function guardedRequest($url, $request)
    {
        list($body, $status) = $request();
        return [$body, $status];
    }

    protected function requestPage($url)
    {
        $this->urls[] = $url;
        if (parse_url($url, PHP_URL_PATH) === '/api/get-paywall') {
            return $this->subscriberResponse;
        }
        // The untrusted markup URL must never determine the API request host.
        return ['<html><head><script type="application/ld+json">{"@type":"NewsArticle","isAccessibleForFree":false}</script></head>' .
            '<body><article class="article"><section itemprop="articleBody"><p>Introduction.</p></section>' .
            '<lcl-tapet-paywall data-article-url="https://other.example/steal" ' .
            'data-article-uuid="60f71144-4052-4e38-9c96-dea9a6f536c9" data-access-restriction="locked">' .
            '</lcl-tapet-paywall></article></body></html>', 200];
    }
}
