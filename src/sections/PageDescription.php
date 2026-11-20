<?php

namespace rsspipes\sections;

use rsspipes\rss\Item;

/** Replaces item descriptions with metadata from their linked webpages. */
class PageDescription extends Section
{
    public $cache = true;
    public $cacheDir;
    public $cacheTtl = 86400;
    public $failureCacheTtl = 300;
    public $timeout = 5;

    protected $sectionType = 'pageDescription';
    protected $cacheDirectory = 'rss-pipes-page-descriptions';
    protected $cachePrefix = '';

    public function init()
    {
        foreach (['cacheTtl', 'failureCacheTtl', 'timeout'] as $name) {
            if (!is_int($this->$name) || $this->$name < ($name === 'timeout' ? 1 : 0)) {
                throw new exceptions\InvalidConfigException($this->sectionType . '.' . $name . ' must be an integer ' . ($name === 'timeout' ? '> 0' : '>= 0'));
            }
        }
        if (!is_bool($this->cache) || ($this->cacheDir !== null && (!is_string($this->cacheDir) || $this->cacheDir === ''))) {
            throw new exceptions\InvalidConfigException($this->sectionType . ' requires a boolean cache and a non-empty string cacheDir');
        }
    }

    /** @param Item $item */
    public function processItem($item)
    {
        if (!is_string($item->link) || !preg_match('~^https?://~i', $item->link)) {
            return;
        }

        $cached = $this->readCache($item->link);
        if ($cached !== null) {
            $description = $cached['description'];
        } else {
            $body = $this->fetchPage($item->link);
            $description = is_string($body) && trim($body) !== '' ? $this->findDescription($body, $item->link) : null;
            $this->writeCache($item->link, $description);
        }

        if ($description !== null) {
            $item->description = $description;
        }
    }

    protected function fetchPage($url)
    {
        $http = new \HttpClient();
        $body = $http->get($url, ['timeout' => $this->timeout]);
        return $http->httpCode >= 200 && $http->httpCode < 300 ? $body : false;
    }

    protected function currentTime()
    {
        return time();
    }

    protected function cachePath($url)
    {
        $directory = $this->cacheDir === null ? sys_get_temp_dir() . '/' . $this->cacheDirectory : $this->cacheDir;
        return rtrim($directory, '/\\') . '/' . $this->cachePrefix . hash('sha256', $url) . '.json';
    }

    protected function readCache($url)
    {
        if (!$this->cache) {
            return null;
        }
        $body = @file_get_contents($this->cachePath($url));
        $entry = $body === false ? null : json_decode($body, true);
        if (!is_array($entry) || !isset($entry['expiresAt']) || !is_int($entry['expiresAt']) ||
            $entry['expiresAt'] <= $this->currentTime() || !array_key_exists('description', $entry)) {
            return null;
        }
        $description = $entry['description'];
        if ($description !== null && (!is_string($description) || trim($description) === '')) {
            return null;
        }
        return ($description === null ? $this->failureCacheTtl : $this->cacheTtl) > 0 ? $entry : null;
    }

    protected function writeCache($url, $description)
    {
        $ttl = $description === null ? $this->failureCacheTtl : $this->cacheTtl;
        if (!$this->cache || $ttl === 0) {
            return;
        }
        $path = $this->cachePath($url);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        $body = json_encode(['expiresAt' => $this->currentTime() + $ttl, 'description' => $description]);
        if ($body === false || !is_writable($directory)) {
            return;
        }
        // Publish complete entries so concurrent feed requests cannot read partial JSON.
        $temporary = @tempnam($directory, '.page-description-');
        if ($temporary === false) {
            return;
        }
        if (@file_put_contents($temporary, $body) === strlen($body) && @rename($temporary, $path)) {
            return;
        }
        @unlink($temporary);
    }

    protected function findDescription($body, $url = null)
    {
        // Older libxml versions can miss HTML5 or late charset declarations.
        // Tell the parser explicitly when the bytes are UTF-8, even if a meta
        // declaration exists. Leave declared legacy encodings to the parser.
        if (preg_match('//u', $body) === 1 || !preg_match('/charset\s*=|<\?xml[^>]*encoding\s*=/i', $body)) {
            $body = '<?xml encoding="UTF-8">' . $body;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadHTML($body, LIBXML_NONET)) {
                return null;
            }

            return $this->extractFromDocument($document, $url);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    protected function extractFromDocument(\DOMDocument $document, $url = null)
    {
        $descriptions = [];
        $keys = ['og:description', 'twitter:description', 'description'];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            $property = strtolower(trim($meta->getAttribute('property')));
            $name = strtolower(trim($meta->getAttribute('name')));
            $content = trim($meta->getAttribute('content'));
            if ($content === '') {
                continue;
            }
            foreach ($keys as $key) {
                if (($property === $key || $name === $key) && !isset($descriptions[$key])) {
                    $descriptions[$key] = $content;
                }
            }
        }
        foreach ($keys as $key) {
            if (isset($descriptions[$key])) {
                return $descriptions[$key];
            }
        }
        return null;
    }
}
