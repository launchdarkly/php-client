<?php
namespace LaunchDarkly;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * A client for the LaunchDarkly API.
 */
class LDClient {
    const DEFAULT_BASE_URI = 'https://app.launchdarkly.com';
    const VERSION = '2.0.0';

    /** @var string */
    protected $_apiKey;
    /** @var string */
    protected $_baseUri;
    /** @var EventProcessor */
    protected $_eventProcessor;
    /** @var  bool */
    protected $_offline = false;
    /** @var bool */
    protected $_send_events = true;
    /** @var array|mixed */
    protected $_defaults = array();
    /** @var mixed|LoggerInterface */
    protected $_logger;

    /** @var  FeatureRequester */
    protected $_featureRequester;

    /**
     * Creates a new client instance that connects to LaunchDarkly.
     *
     * @param string $apiKey The API key for your account
     * @param array $options Client configuration settings
     *     - base_uri: Base URI of the LaunchDarkly API. Defaults to `DEFAULT_BASE_URI`
     *     - timeout: Float describing the maximum length of a request in seconds. Defaults to 3
     *     - connect_timeout: Float describing the number of seconds to wait while trying to connect to a server. Defaults to 3
     *     - cache: An optional Kevinrob\GuzzleCache\Strategy\CacheStorageInterface. Defaults to an in-memory cache.
     */
    public function __construct($apiKey, $options = array()) {
        $this->_apiKey = $apiKey;
        if (!isset($options['base_uri'])) {
            $this->_baseUri = self::DEFAULT_BASE_URI;
        } else {
            $this->_baseUri = rtrim($options['base_uri'], '/');
        }
        if (isset($options['send_events'])) {
            $this->_send_events = $options['send_events'];
        }
        if (isset($options['offline']) && $options['offline'] === true) {
            $this->_offline = true;
            $this->_send_events = false;
        }

        if (isset($options['defaults'])) {
            $this->_defaults = $options['defaults'];
        }

        if (!isset($options['timeout'])) {
            $options['timeout'] = 3;
        }
        if (!isset($options['connect_timeout'])) {
            $options['connect_timeout'] = 3;
        }

        if (!isset($options['capacity'])) {
            $options['capacity'] = 1000;
        }

        if (!isset($options['logger'])) {
            $logger = new Logger("LaunchDarkly", [new ErrorLogHandler()]);
            $options['logger'] = $logger;
        }
        $this->_logger = $options['logger'];

        $this->_eventProcessor = new EventProcessor($apiKey, $options);

        if (isset($options['feature_requester_class'])) {
            $featureRequesterClass = $options['feature_requester_class'];
        } else {
            $featureRequesterClass = '\\LaunchDarkly\\GuzzleFeatureRequester';
        }

        if (!is_a($featureRequesterClass, FeatureRequester::class, true)) {
            throw new \InvalidArgumentException;
        }
        $this->_featureRequester = new $featureRequesterClass($this->_baseUri, $apiKey, $options);
    }

    public function getFlag($key, $user, $default = false) {
        return $this->toggle($key, $user, $default);
    }

    /**
     * Calculates the value of a feature flag for a given user.
     *
     * @param string $key The unique key for the feature flag
     * @param LDUser $user The end user requesting the flag
     * @param boolean $default The default value of the flag
     *
     * @return mixed Whether or not the flag should be enabled, or `default`
     */
    public function toggle($key, $user, $default = false) {
        $default = $this->_get_default($key, $default);

        if ($this->_offline) {
            return $default;
        }

        try {
            if (is_null($user) || strlen($user->getKey()) === 0) {
                $this->_sendFlagRequestEvent($key, $user, $default, $default);
                $this->_logger->warn("Toggle called with null user or null/empty user key! Returning default value");
                return $default;
            }
            $flag = $this->_featureRequester->get($key);

            if (is_null($flag)) {
                $this->_sendFlagRequestEvent($key, $user, $default, $default);
                return $default;
            } else if ($flag->isOn()) {
                $result = $flag->evaluate($user, $this->_featureRequester);
                if (!$this->isOffline() && $this->_send_events) {
                    foreach ($result->getPrerequisiteEvents() as $e) {
                        $this->_eventProcessor->enqueue($e);
                    }
                }
                if ($result->getValue() != null) {
                    $this->_sendFlagRequestEvent($key, $user, $result->getValue(), $default, $flag->getVersion());
                    return $result->getValue();
                }
            }
            $offVariation = $flag->getOffVariationValue();
            if ($offVariation != null) {
                $this->_sendFlagRequestEvent($key, $user, $offVariation, $default, $flag->getVersion());
                return $offVariation;
            }
        } catch (\Exception $e) {
            $this->_logger->error("Caught $e");
        }
        try {
            $this->_sendFlagRequestEvent($key, $user, $default, $default);
        } catch (\Exception $e) {
            $this->_logger->error("Caught $e");
        }
        return $default;
    }

    /**
     * Returns whether the LaunchDarkly client is in offline mode.
     *
     */
    public function isOffline() {
        return $this->_offline;
    }

    /**
     * Tracks that a user performed an event.
     *
     * @param $eventName string The name of the event
     * @param $user LDUser The user that performed the event
     * @param $data mixed
     */
    public function track($eventName, $user, $data) {
        if ($this->isOffline()) {
            return;
        }
        if (is_null($user) || strlen($user->getKey()) === 0) {
            $this->_logger->warn("Track called with null user or null/empty user key!");
        }

        $event = array();
        $event['user'] = $user->toJSON();
        $event['kind'] = "custom";
        $event['creationDate'] = Util::currentTimeUnixMillis();
        $event['key'] = $eventName;
        if (isset($data)) {
            $event['data'] = $data;
        }
        $this->_eventProcessor->enqueue($event);
    }

    /**
     * @param $user LDUser
     */
    public function identify($user) {
        if ($this->isOffline()) {
            return;
        }
        if (is_null($user) || strlen($user->getKey()) === 0) {
            $this->_logger->warn("Track called with null user or null/empty user key!");
        }

        $event = array();
        $event['user'] = $user->toJSON();
        $event['kind'] = "identify";
        $event['creationDate'] = Util::currentTimeUnixMillis();
        $event['key'] = $user->getKey();
        $this->_eventProcessor->enqueue($event);
    }

    /**
     * @param $key string
     * @param $user LDUser
     * @param $value mixed
     * @param $default
     * @param $version int | null
     * @param string | null $prereqOf
     */
    protected function _sendFlagRequestEvent($key, $user, $value, $default, $version = null, $prereqOf = null) {
        if ($this->isOffline() || !$this->_send_events) {
            return;
        }
        $this->_eventProcessor->enqueue(Util::newFeatureRequestEvent($key, $user, $value, $default, $version, $prereqOf));
    }

    protected function _get_default($key, $default) {
        if (array_key_exists($key, $this->_defaults)) {
            return $this->_defaults[$key];
        } else {
            return $default;
        }
    }
}
