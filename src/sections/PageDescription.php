<?php

namespace rsspipes\sections;

use rsspipes\rss\Item;

/** Replaces item descriptions with metadata from their linked webpages. */
class PageDescription extends Section
{
    private const PAYWALL_CACHE_VERSION = 2;

    public $cache = true;
    public $cacheDir;
    public $cacheTtl = 86400;
    public $failureCacheTtl = 300;
    public $timeout = 5;

    protected $sectionType = 'pageDescription';
    protected $cacheDirectory = 'rss-pipes-page-descriptions';
    protected $cachePrefix = '';
    private $paywalled;

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
        $this->paywalled = null;
        if ($item->sourceError || !is_string($item->link) || !preg_match('~^https?://~i', $item->link)) {
            return;
        }
        $query = parse_url($item->link, PHP_URL_QUERY);
        $parameters = [];
        parse_str(is_string($query) ? $query : '', $parameters);
        if (array_key_exists('pinnedEntry', $parameters) || array_key_exists('post', $parameters) ||
            array_key_exists('ifr-dir-postId', $parameters)) {
            $this->logEvent('skip_live_update', $item->link);
            // These links point into a chat/live blog. Its HTML and cached page
            // text can contain many other updates; retain the feed's excerpt.
            return;
        }

        $cached = $this->readCache($item->link);
        if ($cached !== null) {
            $description = $cached['description'];
            $this->paywalled = $cached['paywalled'];
            $this->logEvent($description === null ? 'cached_failure_keep_summary' : 'using_cached_description', $item->link);
        } else {
            if (!$this->canFetchPage($item->link)) {
                return;
            }
            $body = $this->fetchPage($item->link);
            $description = is_string($body) && trim($body) !== '' ? $this->findDescription($body, $item->link) : null;
            $this->logEvent($description === null ? 'keep_feed_summary' : 'using_page_content', $item->link);
            $this->writeCache($item->link, $description);
        }

        if ($description !== null) {
            $item->description = $this->formatDescription($description);
        }
        if ($this->paywalled === true) {
            $description = rtrim((string) $item->description);
            if (!preg_match('~<p>Troligen betalvägg</p>\s*$~u', $description)) {
                $item->description = $description . ($description === '' ? '' : "\n") . '<p>Troligen betalvägg</p>';
            }
        }
    }

    protected function formatDescription($description)
    {
        return $description;
    }

    protected function canFetchPage($url)
    {
        return true;
    }

    protected function fetchPage($url)
    {
        $http = new \HttpClient();
        $body = $http->get($url, ['timeout' => $this->timeout]);
        $this->logEvent('page_request', $url, $http->httpCode);
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
            $entry['expiresAt'] <= $this->currentTime() || !array_key_exists('description', $entry) ||
            !isset($entry['paywallVersion']) || $entry['paywallVersion'] !== self::PAYWALL_CACHE_VERSION ||
            !array_key_exists('paywalled', $entry) || ($entry['paywalled'] !== null && !is_bool($entry['paywalled']))) {
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
        $body = json_encode(['expiresAt' => $this->currentTime() + $ttl, 'description' => $description,
            'paywalled' => $this->paywalled, 'paywallVersion' => self::PAYWALL_CACHE_VERSION]);
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

            $this->paywalled = $this->findPaywallStatus($document);
            return $this->extractFromDocument($document, $url);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function findPaywallStatus(\DOMDocument $document)
    {
        // SvD and other publishers distinguish a reading quota from an
        // unconditional subscription requirement in their primary article data.
        // Metered articles can still have isAccessibleForFree=false in JSON-LD.
        foreach ($document->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('id') !== 'article-pulse-data' ||
                strtolower(trim($script->getAttribute('type'))) !== 'application/json') {
                continue;
            }
            $data = json_decode($script->textContent, true);
            if (!is_array($data)) {
                continue;
            }
            foreach (['accessLevel', 'accessLevelName'] as $key) {
                if (!isset($data[$key]) || !is_string($data[$key])) {
                    continue;
                }
                $level = strtolower(trim($data[$key]));
                if (in_array($level, ['meter', 'metered'], true)) {
                    return null;
                }
                if ($level === 'free') {
                    return false;
                }
            }
        }
        foreach ($document->getElementsByTagName('script') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $status = $this->structuredPaywallStatus(json_decode($script->textContent, true));
            if ($status !== null) {
                return $status;
            }
        }
        return null;
    }

    private function structuredPaywallStatus($data)
    {
        if (!is_array($data)) {
            return null;
        }
        foreach ((array) (isset($data['@type']) ? $data['@type'] : []) as $type) {
            if (is_string($type) && preg_match('~(?:Article|BlogPosting)$~', $type)) {
                // Explicitly restricted sections take precedence over a free
                // introduction. Ignore unrelated objects such as recommendations.
                if ($this->restrictedPart(isset($data['hasPart']) ? $data['hasPart'] : null)) {
                    return true;
                }
                $free = $this->accessBoolean(isset($data['isAccessibleForFree']) ? $data['isAccessibleForFree'] : null);
                return $free === null ? null : !$free;
            }
        }
        if (isset($data['@graph'])) {
            return $this->structuredPaywallStatus($data['@graph']);
        }
        if (isset($data['mainEntity'])) {
            return $this->structuredPaywallStatus($data['mainEntity']);
        }
        // Only traverse JSON-LD lists and primary-entity containers, rather than
        // treating flags on publishers, events, or related stories as evidence.
        foreach ($data as $key => $value) {
            if (is_int($key)) {
                $status = $this->structuredPaywallStatus($value);
                if ($status !== null) {
                    return $status;
                }
            }
        }
        return null;
    }

    private function restrictedPart($part)
    {
        if (!is_array($part)) {
            return false;
        }
        if ($this->accessBoolean(isset($part['isAccessibleForFree']) ? $part['isAccessibleForFree'] : null) === false) {
            return true;
        }
        foreach ($part as $key => $value) {
            if ((is_int($key) || $key === 'hasPart') && $this->restrictedPart($value)) {
                return true;
            }
        }
        return false;
    }

    private function accessBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            switch (strtolower(trim($value))) {
                case 'true': return true;
                case 'false': return false;
            }
        }
        return null;
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
