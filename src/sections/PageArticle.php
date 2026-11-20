<?php

namespace rsspipes\sections;

use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;
use andreskrey\Readability\Readability;

/** Extracts article text from the same HTML request used for page metadata. */
class PageArticle extends PageDescription
{
    public $bodyXPath;

    protected $sectionType = 'pageArticle';
    protected $cacheDirectory = 'rss-pipes-page-articles';
    // Separate representations even when both section types share a cacheDir.
    protected $cachePrefix = 'article-v2-';

    public function init()
    {
        parent::init();
        if ($this->bodyXPath !== null) {
            $previous = libxml_use_internal_errors(true);
            try {
                if (!is_string($this->bodyXPath) || trim($this->bodyXPath) === '' ||
                    (new \DOMXPath(new \DOMDocument()))->query($this->bodyXPath) === false) {
                    throw new exceptions\InvalidConfigException('pageArticle.bodyXPath must be a valid XPath expression');
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
    }

    protected function cachePath($url)
    {
        // An override changes the representation cached for a given URL.
        return parent::cachePath($this->bodyXPath === null ? $url : $url . "\0bodyXPath\0" . $this->bodyXPath);
    }

    protected function extractFromDocument(\DOMDocument $document, $url = null)
    {
        if (is_string($url)) {
            $query = parse_url($url, PHP_URL_QUERY);
            $parameters = [];
            parse_str(is_string($query) ? $query : '', $parameters);
            if (array_key_exists('post', $parameters)) {
                // These URLs identify one live-blog update, not the whole page.
                // Page metadata and generic articleBody can describe other posts.
                return $this->linkedPostText($document, $parameters['post']);
            }
        }
        $xpath = new \DOMXPath($document);
        $queries = [];
        if ($this->bodyXPath !== null) {
            $queries[] = $this->bodyXPath;
        }
        $queries[] = '//*[contains(concat(" ", normalize-space(@itemprop), " "), " articleBody ")]';
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            $selected = [];
            foreach ($nodes as $node) {
                $selected[$node->getNodePath()] = true;
            }
            $parts = [];
            foreach ($nodes as $node) {
                // A matching ancestor already includes all of its descendants.
                $nested = false;
                for ($ancestor = $node->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
                    if (isset($selected[$ancestor->getNodePath()])) {
                        $nested = true;
                        break;
                    }
                }
                if (!$nested) {
                    $text = $this->articleText($node);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
            if ($parts) {
                return implode("\n\n", $parts);
            }
        }

        foreach ($document->getElementsByTagName('script') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) === 'application/ld+json') {
                $body = $this->structuredArticleBody(json_decode($script->textContent, true));
                if ($body !== null) {
                    return $body;
                }
            }
        }

        // Give Readability already-decoded UTF-8 HTML. It performs no fetching
        // and its DOM output is converted to text instead of exposing raw HTML.
        $reader = new Readability(new Configuration(['FixRelativeURLs' => false, 'CharThreshold' => 200]));
        try {
            $reader->parse($this->readabilityHtml($document));
            $article = $reader->getDOMDocument();
            if ($article !== null) {
                $text = $this->articleText($article);
                if (mb_strlen($text, 'UTF-8') >= 200) {
                    return $text;
                }
            }
        } catch (ParseException $exception) {
            // Missing/short article bodies use the same metadata fallbacks.
        }

        return parent::extractFromDocument($document);
    }

    private function linkedPostText(\DOMDocument $document, $postId)
    {
        if (!is_string($postId) || trim($postId) === '') {
            return null;
        }
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[@id or @data-id or @data-post-id or @data-entry-id]');
        foreach ($nodes as $node) {
            $matches = in_array($node->getAttribute('id'), [$postId, 'post-' . $postId, 'entry-' . $postId], true);
            foreach (['data-id', 'data-post-id', 'data-entry-id'] as $attribute) {
                $matches = $matches || $node->getAttribute($attribute) === $postId;
            }
            if ($matches) {
                $text = $this->articleText($node);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        // Returning null preserves each item's own RSS/Atom excerpt, including
        // on a failure-cache hit. Never substitute metadata for the whole blog.
        return null;
    }

    private function readabilityHtml(\DOMDocument $document)
    {
        // Clean a separate DOM so metadata remains available for fallback.
        $copy = new \DOMDocument('1.0', 'UTF-8');
        $copy->appendChild($copy->importNode($document->documentElement, true));
        $xpath = new \DOMXPath($copy);
        foreach ($xpath->query('//*') as $node) {
            if ($this->isExcluded($node) && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        // A missing/paywalled article must not make a long site footer win.
        foreach (['//article[not(ancestor::article)]', '//main[not(ancestor::main)]'] as $query) {
            $best = null;
            $length = -1;
            $headline = -1;
            foreach ($xpath->query($query) as $candidate) {
                $size = mb_strlen($this->articleText($candidate), 'UTF-8');
                $hasHeadline = $candidate->getElementsByTagName('h1')->length > 0 ? 1 : 0;
                if ($hasHeadline > $headline || ($hasHeadline === $headline && $size > $length)) {
                    $best = $candidate;
                    $length = $size;
                    $headline = $hasHeadline;
                }
            }
            if ($best !== null) {
                $head = $copy->getElementsByTagName('head')->item(0);
                return '<html>' . ($head === null ? '' : $copy->saveHTML($head)) .
                    '<body>' . $copy->saveHTML($best) . '</body></html>';
            }
        }
        // Serializing the root omits external document type declarations.
        return $copy->saveHTML($copy->documentElement);
    }

    private function structuredArticleBody($data)
    {
        if (!is_array($data)) {
            return null;
        }
        foreach ((array) (isset($data['@type']) ? $data['@type'] : []) as $type) {
            if (is_string($type) && preg_match('~(?:Article|BlogPosting)$~', $type) &&
                isset($data['articleBody']) && is_string($data['articleBody'])) {
                $body = trim($data['articleBody']);
                if ($body !== '') {
                    if (!preg_match('/<[a-z][^>]*>/i', $body)) {
                        $text = $this->normalizeText(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if ($text !== '') {
                            return $text;
                        }
                        continue;
                    }
                    // articleBody is text, although some publishers put HTML in it.
                    $document = new \DOMDocument();
                    $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $body . '</body></html>', LIBXML_NONET);
                    $text = $this->articleText($document);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }
        foreach ($data as $value) {
            $body = $this->structuredArticleBody($value);
            if ($body !== null) {
                return $body;
            }
        }
        return null;
    }

    private function articleText(\DOMNode $node)
    {
        return $this->normalizeText($this->collectText($node));
    }

    private function normalizeText($text)
    {
        $text = preg_replace('/[^\S\n]+/u', ' ', str_replace(["\r\n", "\r", "\xc2\xa0"], ["\n", "\n", ' '], $text));
        $text = preg_replace('/ *\n */u', "\n", $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    private function collectText(\DOMNode $node)
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return preg_replace('/[\s\x{00a0}]+/u', ' ', $node->nodeValue);
        }
        if ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);
            if ($this->isExcluded($node)) {
                return '';
            }
            if ($tag === 'br') {
                return "\n";
            }
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->collectText($child);
        }
        if ($node instanceof \DOMElement && in_array($tag, ['td', 'th'], true)) {
            return trim($text) . ' ';
        }
        if ($node instanceof \DOMElement && in_array($tag, ['p', 'div', 'section', 'article', 'main', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol', 'tr'], true)) {
            return "\n\n" . trim($text) . "\n\n";
        }
        return $text;
    }

    private function isExcluded(\DOMElement $node)
    {
        if (in_array(strtolower($node->tagName), ['script', 'style', 'noscript', 'template', 'nav', 'aside', 'footer', 'form', 'button', 'dialog', 'iframe', 'svg', 'video', 'audio', 'figure', 'figcaption', 'time'], true) ||
            $node->hasAttribute('hidden') || strtolower($node->getAttribute('aria-hidden')) === 'true' ||
            in_array($node->getAttribute('role'), ['navigation', 'contentinfo', 'dialog'], true)) {
            return true;
        }
        $identity = $node->getAttribute('class') . ' ' . $node->getAttribute('id');
        return preg_match('/(?:^|[-_\s])(?:footer|print|cookie|consent|newsletter|social|share|related|disclaimer|noscriptmessage|(?:transparency|disclaimer)[-_]?(?:box|notice))(?:$|[-_\s])/i', $identity) === 1;
    }
}
