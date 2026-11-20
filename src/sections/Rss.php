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
    public $sourceCacheTtl = 0;

    public function init()
    {
        if (!is_int($this->sourceCacheTtl) || $this->sourceCacheTtl < 0) {
            throw new exceptions\InvalidConfigException('sourceCacheTtl must be a non-negative integer');
        }
    }
    
    /**
     * @param Feed $feed
     */
    public function processFeed($feed)
    {
        if ($this->sourceCacheTtl > 0) {
            $error = $this->loadCachedSource($feed);
        } else {
            $error = $this->loadSource($feed, $body);
        }
        if ($error === null) {
            return;
        }
        $this->logEvent('source_unavailable', $this->url);
        if ($this->showErrors) {
            $feed->metadata += ['title' => 'RSS Pipes: source unavailable',
                'link' => $this->url, 'description' => 'The source feed could not be loaded.'];
            $item = new Item(['title' => $error, 'link' => $this->url]);
            $item->sourceError = true;
            $feed->addItem($item);
        }
    }

    private function loadCachedSource($feed)
    {
        $directory = sys_get_temp_dir() . '/rss-pipes-sources';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return 'Cannot create source cache directory';
        }
        $path = $directory . '/' . hash('sha256', get_class($this) . "\0" . $this->url);
        $lock = @fopen($path . '.lock', 'c+');
        if (!$lock) {
            return 'Cannot open source cache lock';
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                return 'Cannot lock source cache';
            }
            $state = json_decode(stream_get_contents($lock), true);
            if (isset($state['checked']) && time() - $state['checked'] < $this->sourceCacheTtl) {
                $error = $state['error'];
            } else {
                $error = $this->loadSource($feed, $body);
                if ($error === null) {
                    $temporary = tempnam($directory, 'feed-');
                    if ($temporary === false || file_put_contents($temporary, $body) !== strlen($body) ||
                        !rename($temporary, $path)) {
                        if ($temporary !== false) {
                            @unlink($temporary);
                        }
                        return null;
                    }
                }
                // A skipped check must not postpone the next eligible request.
                if ($error !== 'Source request paused') {
                    rewind($lock);
                    ftruncate($lock, 0);
                    fwrite($lock, json_encode(['checked' => time(), 'error' => $error]));
                }
                if ($error === null) {
                    return null;
                }
            }
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                $this->logEvent($error === null ? 'source_cache_hit' : 'serving_last_good_source', $this->url);
                $this->parseBody($cached, $feed);
                return null;
            }
            return $error ?? 'Cached source unavailable';
        } finally {
            fclose($lock);
        }
    }

    private function loadSource($feed, &$body)
    {
        $transportError = null;
        $response = $this->guardedRequest($this->url, function () use (&$transportError) {
            $http = $this->createHttpClient();
            $body = $http->get($this->url);
            $this->logEvent('source_request', $this->url, $http->httpCode);
            if ($body === false) {
                $transportError = $http->lastError;
            }
            return [$body, $http->httpCode];
        });
        if ($response === null) {
            return 'Source request paused';
        }
        list($body, $status) = $response;
        if ($body === false) {
            $error = $transportError ?: 'Feed request failed';
        } elseif ($status < 200 || $status >= 300) {
            $error = 'Feed request failed: HTTP ' . $status;
        } elseif (!is_string($body) || trim($body) === '') {
            $error = 'The source feed returned an empty response';
        } else {
            $previous = libxml_use_internal_errors(true);
            try {
                $this->parseBody($body, $feed);
                return null;
            } catch (\Exception $exception) {
                $error = 'Invalid source feed: ' . $exception->getMessage();
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        return $error;
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
