<?php

namespace rsspipes\sections;

use rsspipes\rss\Feed;
use rsspipes\rss\Item;
use rsspipes\sections\exceptions\Exception;
use rsspipes\sections\exceptions\InvalidConfigException;

abstract class Section
{
    public $log = false;

    protected function logEvent($event, $url, $status = null)
    {
        if (!$this->log) {
            return;
        }
        $parts = parse_url((string) $url);
        $record = [
            'section' => get_class($this),
            'event' => $event,
            'host' => $parts['host'] ?? '',
            'path' => $parts['path'] ?? '',
        ];
        if ($status !== null) {
            $record['upstream_status'] = (int) $status;
        }
        error_log('[rss-pipes] ' . json_encode($record, JSON_UNESCAPED_SLASHES));
    }

    public function init()
    {
    }
    
    /**
     * @param Feed $feed
     */
    public function processFeed($feed)
    {
        foreach ($feed->items as $item) {
            $this->processItem($item);
        }
    }
    
    /**
     * @param Item $item
     */
    public function processItem($item)
    {
        throw new Exception(__METHOD__ . 'shouldn`t be called.');
    }
    
    public static function create($config)
    {
        $class = __NAMESPACE__ . '\\' . ucfirst($config['type']);
        unset($config['type']);

        if (!class_exists($class))
            throw new InvalidConfigException('Section does not exist: ' . $class);
        
        /** @var Section $section */
        $section = new $class();
        foreach ($config as $key => $val) {
            $section->$key = $val;
        }
        
        $section->init();
        
        return $section;
    }
}
