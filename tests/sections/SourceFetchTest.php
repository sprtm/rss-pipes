<?php

namespace rsspipestests\sections;

use rsspipes\builder\RssBuilder;
use rsspipes\rss\Feed;
use rsspipes\sections\Atom;
use rsspipes\sections\Rss;

class SourceFetchTest extends \PHPUnit_Framework_TestCase
{
    /** @dataProvider failedResponsesProvider */
    public function testSourceFailuresProduceRssErrorsInsteadOfFatalHtml($status, $body, $message)
    {
        foreach ([new RssFetchStub(), new AtomFetchStub()] as $section) {
            $section->url = 'https://example.com/rss/';
            $section->status = $status;
            $section->body = $body;
            $feed = new Feed();
            $previous = libxml_use_internal_errors(false);
            ob_start();
            try {
                $section->processFeed($feed);
                $this->assertFalse(libxml_use_internal_errors());
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
                libxml_use_internal_errors($previous);
            }
            $this->assertSame('', $output);
            $this->assertCount(1, $feed->items);
            $this->assertContains($message, $feed->items[0]->title);
            $xml = new \SimpleXMLElement((new RssBuilder())->buildFeed($feed));
            $this->assertSame('rss', $xml->getName());
            $this->assertSame('RSS Pipes: source unavailable', (string) $xml->channel->title);
            $this->assertSame($section->url, (string) $xml->channel->link);
            $this->assertSame($feed->items[0]->title, (string) $xml->channel->item->title);

            $section->showErrors = 0;
            $feed = new Feed();
            $section->processFeed($feed);
            $this->assertSame([], $feed->items);
        }
    }

    public function failedResponsesProvider()
    {
        return [
            [406, '', 'HTTP 406'],
            [500, '<html><body>Server error</body></html>', 'HTTP 500'],
            [200, '', 'empty response'],
            [200, " \n\t", 'empty response'],
            [200, 'not XML', 'Invalid source feed'],
            [200, '<html><body>Verification page</body></html>', 'Invalid source feed'],
            [200, '<rss><broken>', 'Invalid source feed'],
            [0, false, 'Transport error'],
        ];
    }

    public function testValidRssAndAtomSourcesStillParseNormally()
    {
        foreach ([
            [new RssFetchStub(), '<rss version="2.0"><channel><title>News</title><item><title>Story</title></item></channel></rss>'],
            [new AtomFetchStub(), '<feed xmlns="http://www.w3.org/2005/Atom"><title>News</title><entry><id>one</id><title>Story</title></entry></feed>'],
        ] as $source) {
            $section = $source[0];
            $section->body = $source[1];
            $feed = new Feed();
            $section->processFeed($feed);
            $this->assertSame('News', $feed->metadata['title']);
            $this->assertCount(1, $feed->items);
            $this->assertSame('Story', $feed->items[0]->title);
        }
    }

    public function testExistingFeedMetadataSurvivesSourceFailure()
    {
        $section = new RssFetchStub();
        $section->status = 406;
        $section->body = '';
        $feed = new Feed();
        $feed->metadata = ['title' => 'Combined news', 'link' => 'https://example.com/', 'description' => 'My news'];
        $metadata = $feed->metadata;
        $section->processFeed($feed);
        $this->assertSame($metadata, $feed->metadata);
    }
}

trait SourceFetchStubBehavior
{
    public $body;
    public $status = 200;

    protected function createHttpClient()
    {
        return new class($this->body, $this->status) {
            public $httpCode;
            public $lastError = 'Transport error';
            private $body;

            public function __construct($body, $status)
            {
                $this->body = $body;
                $this->httpCode = $status;
            }

            public function get($url)
            {
                return $this->body;
            }
        };
    }
}

class RssFetchStub extends Rss
{
    use SourceFetchStubBehavior;
}

class AtomFetchStub extends Atom
{
    use SourceFetchStubBehavior;
}
