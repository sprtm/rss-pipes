<?php

namespace rsspipes\sections;

use rsspipes\rss\Item;

/**
 * Section that replaces substring inside attribute.
 */
class Replace extends Section
{
    /**
     * @var callable
     */
    public $item;
    
    /**
     * Attribute we want to replace substring in.
     * @var string
     */
    public $attribute;
    /**
     * Subtring we want to replace.
     * @var string
     */
    public $search;
    /**
     * Subtring we want to replace with.
     * @var string
     */
    public $replace;

    /** @var bool Whether search is a delimited PCRE pattern. */
    public $regex = false;
    
    public function init()
    {
        parent::init();
        
        \Assert\that($this->attribute, 'replace.attribute')->notEmpty()->inArray(Item::dataAttributes());
        \Assert\that($this->search, 'replace.search')->notEmpty()->string();
        \Assert\that($this->replace, 'replace.replace')->string();
        \Assert\that($this->regex, 'replace.regex')->boolean();
        if ($this->regex && @preg_match($this->search, '') === false) {
            throw new \InvalidArgumentException('replace.search must be a valid delimited regular expression');
        }
    }
    
    /**
     * @param Item $item
     */
    public function processItem($item)
    {
        $data = $item->getData();
        
        $replaced = [];
        if ($this->regex) {
            $value = preg_replace($this->search, $this->replace, (string) $data[$this->attribute]);
            if ($value === null) {
                throw new \RuntimeException('Regex replacement failed with PCRE error ' . preg_last_error());
            }
            $replaced[$this->attribute] = $value;
        } else {
            $replaced[$this->attribute] = str_replace($this->search, $this->replace, $data[$this->attribute]);
        }
        $item->setData($replaced);
    }
}
