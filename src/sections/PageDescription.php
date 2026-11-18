<?php

namespace rsspipes\sections;

use rsspipes\rss\Item;

/** Replaces item descriptions with metadata from their linked webpages. */
class PageDescription extends Section
{
    /** @param Item $item */
    public function processItem($item)
    {
        if (!is_string($item->link) || !preg_match('~^https?://~i', $item->link)) {
            return;
        }

        $body = $this->fetchPage($item->link);
        if (!is_string($body) || trim($body) === '') {
            return;
        }

        $description = $this->findDescription($body);
        if ($description !== null) {
            $item->description = $description;
        }
    }

    protected function fetchPage($url)
    {
        $http = new \HttpClient();
        return $http->get($url);
    }

    protected function findDescription($body)
    {
        // HTML without an encoding declaration commonly uses UTF-8.
        if (!preg_match('/charset\s*=|<\?xml[^>]*encoding\s*=/i', $body)) {
            $body = '<?xml encoding="UTF-8">' . $body;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadHTML($body, LIBXML_NONET)) {
                return null;
            }

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
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
