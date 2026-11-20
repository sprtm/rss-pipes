<?php

namespace rsspipes\sections;

use rsspipes\rss\Feed;
use rsspipes\rss\Item;

/**
 * Parses rss feed.
 */
class Rss extends Section
{
    public $url;
    public $showErrors = 1;
    
    /**
     * @param Feed $feed
     */
    public function processFeed($feed)
    {
        $http = $this->createHttpClient();
        $body = $http->get($this->url);
        if ($body === false) {
            $error = $http->lastError ?: 'Feed request failed';
        } elseif ($http->httpCode < 200 || $http->httpCode >= 300) {
            $error = 'Feed request failed: HTTP ' . $http->httpCode;
        } elseif (!is_string($body) || trim($body) === '') {
            $error = 'The source feed returned an empty response';
        } else {
            $previous = libxml_use_internal_errors(true);
            try {
                $this->parseBody($body, $feed);
                return;
            } catch (\Exception $exception) {
                $error = 'Invalid source feed: ' . $exception->getMessage();
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        if ($this->showErrors) {
            $feed->metadata += ['title' => 'RSS Pipes: source unavailable',
                'link' => $this->url, 'description' => 'The source feed could not be loaded.'];
            $item = new Item(['title' => $error, 'link' => $this->url]);
            $item->sourceError = true;
            $feed->addItem($item);
        }
    }

    protected function createHttpClient()
    {
        return new \HttpClient();
    }
    
    public function parseBody($body, $feed)
    {
        $xml = new \SimpleXMLElement($body, LIBXML_NONET);
        if ($xml->getName() !== 'rss' || !isset($xml->channel)) {
            throw new \UnexpectedValueException('Expected RSS XML with a channel');
        }
        
        foreach ($xml->attributes() as $key => $val) {
            $feed->attributes += [$key => $val];
        }
        $feed->namespaces += $xml->getDocNamespaces();
        
        $metadata = $this->parseChildrenValues($xml->channel);
        unset($metadata['item']);
        
        $feed->metadata += $metadata;
        
        foreach ($xml->channel->item as $key => $element) {
            $item = new Item;
            $item->setData($this->parseChildrenValues($element));
            $item->attributes += $this->parseAllAttributes($element);

            $feed->items[] = $item;
        }
    }
    
    protected function parseChildrenValues($element)
    {
        $children = (array) $element->children();
        $values = [];
        foreach ($children as $key => $value) {
            if (is_object($value) && $value->count()) {
                $value = $this->parseChildrenValues($value);
                
            } elseif (is_array($value)) {
                $value = array_map('strval', $value);
                
            } else {
                $value = (string) $value;
            }
            
            $values[$key] = $value;
        }
        
        return $values;
    }
    
    public function parseAllAttributes($element)
    {
        $attributes = [];
        foreach ($element->children() as $childKey => $child) {
            $attributes = [];
            foreach ($child->attributes() as $key => $value) {
                $attributes[$key] = (string) $value;
            }
            if ($attributes) {
                $attributes[$childKey] = $attributes;
            }
        }
        return $attributes;
    }
}
