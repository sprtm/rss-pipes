<?php

namespace rsspipes\html;

/** Builds reader-friendly HTML from article nodes without copying source attributes. */
class ArticleHtml
{
    private $url;
    private $excluded;

    public function __construct($url, callable $excluded)
    {
        $this->url = $url;
        $this->excluded = $excluded;
    }

    public function render(\DOMNode $node)
    {
        if ($node instanceof \DOMDocument) {
            return trim($this->children($node, true));
        }
        $html = trim($this->node($node, false));
        if ($html === '' || preg_match('~<(?:p|h[1-6]|ul|ol|li|blockquote|pre|hr|table|thead|tbody|tfoot|tr|th|td)>~', $html)) {
            return $html;
        }
        return '<p>' . $html . '</p>';
    }

    private function escape($text)
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function children(\DOMNode $node, $flow = false, $preformatted = false)
    {
        $parts = [];
        $inline = '';
        foreach ($node->childNodes as $child) {
            $html = $this->node($child, $preformatted);
            if ($flow && preg_match('~<(?:p|h[1-6]|ul|ol|li|blockquote|pre|hr|table|thead|tbody|tfoot|tr|th|td)>~', $html)) {
                if (trim($inline) !== '') {
                    $parts[] = '<p>' . trim($inline) . '</p>';
                }
                $inline = '';
                $parts[] = $html;
            } else {
                $inline .= $html;
            }
        }
        if (!$flow) {
            return $inline;
        }
        if (trim($inline) !== '') {
            $parts[] = '<p>' . trim($inline) . '</p>';
        }
        return implode("\n", $parts);
    }

    private function node(\DOMNode $node, $preformatted)
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $text = $node->nodeValue;
            if (!$preformatted) {
                $text = preg_replace('/[\s\x{00a0}]+/u', ' ', $text);
            }
            return $this->escape($text);
        }
        if (!$node instanceof \DOMElement) {
            return $this->children($node, false, $preformatted);
        }
        if (call_user_func($this->excluded, $node)) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['br', 'hr'], true)) {
            return '<' . $tag . '>';
        }
        if (in_array($tag, ['div', 'section', 'article', 'main', 'header', 'body', 'html'], true)) {
            return $this->children($node, true, $preformatted);
        }
        $content = $this->children($node, false, $preformatted || $tag === 'pre');
        if (trim($content) === '') {
            return '';
        }
        if ($tag === 'a') {
            $href = $this->safeLink($node->getAttribute('href'));
            return $href === null ? $content : '<a href="' . $this->escape($href) . '">' . $content . '</a>';
        }
        $aliases = ['b' => 'strong', 'i' => 'em'];
        $tag = isset($aliases[$tag]) ? $aliases[$tag] : $tag;
        if (in_array($tag, ['strong', 'em', 'u', 's', 'del', 'sub', 'sup', 'code'], true)) {
            return '<' . $tag . '>' . $content . '</' . $tag . '>';
        }
        if (in_array($tag, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'li',
            'pre', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'], true)) {
            return '<' . $tag . '>' . ($tag === 'pre' ? $content : trim($content)) . '</' . $tag . '>';
        }
        // Unknown wrappers lose their tags and attributes, but keep useful text.
        return $content;
    }

    private function safeLink($href)
    {
        $href = trim($href);
        if ($href === '' || preg_match('/[\x00-\x20\x7f]/', $href) || strpos($href, '\\') !== false) {
            return null;
        }
        if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $href, $match)) {
            $scheme = strtolower($match[1]);
            if ($scheme === 'mailto') {
                return strlen($href) > 7 ? $href : null;
            }
            $parts = parse_url($href);
            return in_array($scheme, ['http', 'https'], true) && is_array($parts) &&
                !empty($parts['host']) && preg_match('~^https?://~i', $href) ? $href : null;
        }
        $base = is_string($this->url) ? parse_url($this->url) : false;
        if (!is_array($base) || empty($base['host']) || empty($base['scheme']) ||
            !in_array(strtolower($base['scheme']), ['http', 'https'], true)) {
            return null;
        }
        if (substr($href, 0, 2) === '//') {
            return $this->safeLink($base['scheme'] . ':' . $href);
        }
        $relative = parse_url($href);
        if ($relative === false) {
            return null;
        }
        $basePath = isset($base['path']) ? $base['path'] : '/';
        $path = isset($relative['path']) ? $relative['path'] : '';
        if ($path === '') {
            $path = $basePath;
            if (!isset($relative['query']) && isset($base['query'])) {
                $relative['query'] = $base['query'];
            }
        } elseif ($path[0] !== '/') {
            $path = substr($basePath, 0, strrpos($basePath, '/') + 1) . $path;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                if (count($segments) > 1) {
                    array_pop($segments);
                }
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $resolved = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') .
            (implode('/', $segments) ?: '/');
        if (preg_match('~(?:/|/\.|/\.\.)$~', $path) && substr($resolved, -1) !== '/') {
            $resolved .= '/';
        }
        return $resolved . (isset($relative['query']) ? '?' . $relative['query'] : '') .
            (isset($relative['fragment']) ? '#' . $relative['fragment'] : '');
    }
}
