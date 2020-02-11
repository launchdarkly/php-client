<?php
namespace LaunchDarkly;

/**
 * Internal data model class that describes a feature flag user targeting list.
 *
 * Application code should never need to reference the data model directly.
 *
 * @ignore
 * @internal
 */
class Target
{
    /** @var string[] */
    private $_values = array();
    /** @var int */
    private $_variation = null;

    protected function __construct(array $values, $variation)
    {
        $this->_values = $values;
        $this->_variation = $variation;
    }

    public static function getDecoder()
    {
        return function ($v) {
            return new Target($v['values'], $v['variation']);
        };
    }

    /**
     * @return \string[]
     */
    public function getValues()
    {
        return $this->_values;
    }

    /**
     * @return int
     */
    public function getVariation()
    {
        return $this->_variation;
    }
}
