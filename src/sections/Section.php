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

    // Serialize requests per host so concurrent readers respect the first rejection.
    protected function guardedRequest($url, $request)
    {
        $directory = sys_get_temp_dir() . '/rss-pipes-sources';
        $host = strtolower(rtrim((string) parse_url((string) $url, PHP_URL_HOST), '.'));
        if ($host === '') {
            return $request();
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->logEvent('cooldown_storage_unavailable', $url);
            return null;
        }
        $lock = @fopen($directory . '/host-' . hash('sha256', $host), 'c+');
        if (!$lock) {
            $this->logEvent('cooldown_storage_unavailable', $url);
            return null;
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                return null;
            }
            if ((int) stream_get_contents($lock) > time()) {
                $this->logEvent('host_cooldown_skip', $url);
                return null;
            }
            list($body, $status) = $request();
            if (in_array((int) $status, [403, 406, 429, 503], true)) {
                rewind($lock);
                ftruncate($lock, 0);
                fwrite($lock, (string) (time() + 31 * 60));
                fflush($lock);
                $this->logEvent('host_cooldown_started', $url, $status);
            }
            return [$body, $status];
        } finally {
            fclose($lock);
        }
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
