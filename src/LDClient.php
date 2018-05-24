<?php
namespace LaunchDarkly;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Used internally.
 */
class InvalidSDKKeyException extends \Exception
{
}

/**
 * A client for the LaunchDarkly API.
 */
class LDClient
{
    const DEFAULT_BASE_URI = 'https://app.launchdarkly.com';
    const DEFAULT_EVENTS_URI = 'https://events.launchdarkly.com';
    const VERSION = '3.1.0';

    /** @var string */
    protected $_sdkKey;
    /** @var string */
    protected $_baseUri;
    /** @var string */
    protected $_eventsUri;
    /** @var EventProcessor */
    protected $_eventProcessor;
    /** @var  bool */
    protected $_offline = false;
    /** @var bool */
    protected $_send_events = true;
    /** @var array|mixed */
    protected $_defaults = array();
    /** @var LoggerInterface */
    protected $_logger;
    /** @var FeatureRequester */
    protected $_featureRequester;

    /**
     * Creates a new client instance that connects to LaunchDarkly.
     *
     * @param string $sdkKey The SDK key for your account
     * @param array $options Client configuration settings
     *     - base_uri: Base URI of the LaunchDarkly API. Defaults to `https://app.launchdarkly.com`.
     *     - events_uri: Base URI for sending events to LaunchDarkly. Defaults to 'https://events.launchdarkly.com'
     *     - timeout: Float describing the maximum length of a request in seconds. Defaults to 3
     *     - connect_timeout: Float describing the number of seconds to wait while trying to connect to a server. Defaults to 3
     *     - cache: An optional Kevinrob\GuzzleCache\Storage\CacheStorageInterface. Defaults to an in-memory cache.
     *     - send_events: An optional bool that can disable the sending of events to LaunchDarkly. Defaults to false.
     *     - logger: An optional Psr\Log\LoggerInterface. Defaults to a Monolog\Logger sending all messages to the php error_log.
     *     - offline: An optional boolean which will disable all network calls and always return the default value. Defaults to false.
     *     - feature_requester: An optional LaunchDarkly\FeatureRequester instance.
     *     - feature_requester_class: An optional class implementing LaunchDarkly\FeatureRequester, if `feature_requester` is not specified. Defaults to GuzzleFeatureRequester.
     *     - event_publisher: An optional LaunchDarkly\EventPublisher instance.
     *     - event_publisher_class: An optional class implementing LaunchDarkly\EventPublisher, if `event_publisher` is not specified. Defaults to CurlEventPublisher.
     *     - all_attributes_private: True if no user attributes (other than the key) should be sent back to LaunchDarkly. By default, this is false.
     *     - private_attribute_names: An optional array of user attribute names to be marked private. Any users sent to LaunchDarkly with this configuration active will have attributes with these names removed. You can also set private attributes on a per-user basis in LDUserBuilder.
     */
    public function __construct($sdkKey, $options = array())
    {
        $this->_sdkKey = $sdkKey;
        if (!isset($options['base_uri'])) {
            $this->_baseUri = self::DEFAULT_BASE_URI;
        } else {
            $this->_baseUri = rtrim($options['base_uri'], '/');
        }
        if (!isset($options['events_uri'])) {
            $this->_eventsUri = self::DEFAULT_EVENTS_URI;
        } else {
            $this->_eventsUri = rtrim($options['events_uri'], '/');
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

        $this->_eventProcessor = new EventProcessor($sdkKey, $options);

        $this->_featureRequester = $this->getFeatureRequester($sdkKey, $options);
    }

    /**
     * @param string $sdkKey
     * @param mixed[] $options
     * @return FeatureRequester
     */
    private function getFeatureRequester($sdkKey, array $options)
    {
        if (isset($options['feature_requester']) && $options['feature_requester'] instanceof FeatureRequester) {
            return $options['feature_requester'];
        }

        if (isset($options['feature_requester_class'])) {
            $featureRequesterClass = $options['feature_requester_class'];
        } else {
            $featureRequesterClass = GuzzleFeatureRequester::class;
        }

        if (!is_a($featureRequesterClass, FeatureRequester::class, true)) {
            throw new \InvalidArgumentException;
        }
        return new $featureRequesterClass($this->_baseUri, $sdkKey, $options);
    }

    /**
     * Calculates the value of a feature flag for a given user.
     *
     * @param string $key The unique key for the feature flag
     * @param LDUser $user The end user requesting the flag
     * @param mixed $default The default value of the flag
     *
     * @return mixed The result of the Feature Flag evaluation, or $default if any errors occurred.
     */
    public function variation($key, $user, $default = false)
    {
        $default = $this->_get_default($key, $default);

        if ($this->_offline) {
            return $default;
        }

        try {
            if (!is_null($user) && $user->isKeyBlank()) {
                $this->_logger->warning("User key is blank. Flag evaluation will proceed, but the user will not be stored in LaunchDarkly.");
            }
            try {
                $flag = $this->_featureRequester->getFeature($key);
            } catch (InvalidSDKKeyException $e) {
                $this->handleInvalidSDKKey();
                return $default;
            }

            if (is_null($flag)) {
                $this->_sendFlagRequestEvent($key, $user, null, $default, $default);
                return $default;
            }
            if (is_null($user) || is_null($user->getKey())) {
                $this->_sendFlagRequestEvent($key, $user, null, $default, $default, $flag->getVersion());
                $this->_logger->warning("Variation called with null user or null user key! Returning default value");
                return $default;
            }
            $evalResult = $flag->evaluate($user, $this->_featureRequester);
            if (!$this->isOffline() && $this->_send_events) {
                foreach ($evalResult->getPrerequisiteEvents() as $e) {
                    $this->_eventProcessor->enqueue($e);
                }
            }
            if ($evalResult !== null && $evalResult->getValue() !== null) {
                $this->_sendFlagRequestEvent($key, $user, $evalResult->getVariation(), $evalResult->getValue(), $default, $flag->getVersion());
                return $evalResult->getValue();
            } else {
                $this->_sendFlagRequestEvent($key, $user, null, $default, $default, $flag->getVersion());
                return $default;
            }
        } catch (\Exception $e) {
            $this->_logger->error("Caught $e");
        }
        try {
            $this->_sendFlagRequestEvent($key, $user, null, $default, $default);
        } catch (\Exception $e) {
            $this->_logger->error("Caught $e");
        }
        return $default;
    }


    /** @deprecated Use variation() instead.
     * @param $key
     * @param $user
     * @param bool $default
     * @return mixed
     */
    public function toggle($key, $user, $default = false)
    {
        $this->_logger->warning("Deprecated function: toggle() called. Use variation() instead.");
        return $this->variation($key, $user, $default);
    }

    /**
     * Returns whether the LaunchDarkly client is in offline mode.
     *
     */
    public function isOffline()
    {
        return $this->_offline;
    }

    /**
     * Tracks that a user performed an event.
     *
     * @param $eventName string The name of the event
     * @param $user LDUser The user that performed the event
     * @param $data mixed
     */
    public function track($eventName, $user, $data)
    {
        if ($this->isOffline()) {
            return;
        }
        if (is_null($user) || $user->isKeyBlank()) {
            $this->_logger->warning("Track called with null user or null/empty user key!");
        }

        $event = array();
        $event['user'] = $user;
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
    public function identify($user)
    {
        if ($this->isOffline()) {
            return;
        }
        if (is_null($user) || $user->isKeyBlank()) {
            $this->_logger->warning("Track called with null user or null/empty user key!");
        }

        $event = array();
        $event['user'] = $user;
        $event['kind'] = "identify";
        $event['creationDate'] = Util::currentTimeUnixMillis();
        $event['key'] = $user->getKey();
        $this->_eventProcessor->enqueue($event);
    }

    /** Returns an array mapping Feature Flag keys to their evaluated results for a given user.
     *
     * If the result of a flag's evaluation would have returned the default variation, it will have a null entry.
     * If the client is offline, has not been initialized, or a null user or user with null/empty user key, null will be returned.
     * This method will not send analytics events back to LaunchDarkly.
     * <p>
     * The most common use case for this method is to bootstrap a set of client-side feature flags from a back-end service.
     *
     * @param $user LDUser the end user requesting the feature flags
     * @return array()|null Mapping of feature flag keys to their evaluated results for $user
     */
    public function allFlags($user)
    {
        if (is_null($user) || is_null($user->getKey())) {
            $this->_logger->warn("allFlags called with null user or null/empty user key! Returning null");
            return null;
        }
        if ($this->isOffline()) {
            return null;
        }
        try {
            $flags = $this->_featureRequester->getAllFeatures();
        } catch (InvalidSDKKeyException $e) {
            $this->handleInvalidSDKKey();
            return null;
        }
        if ($flags === null) {
            return null;
        }

        /**
         * @param $flag FeatureFlag
         * @return mixed|null
         */
        $eval = function ($flag) use ($user) {
            return $flag->evaluate($user, $this->_featureRequester)->getValue();
        };

        return array_map($eval, $flags);
    }

    /** Generates an HMAC sha256 hash for use in Secure mode: https://github.com/launchdarkly/js-client#secure-mode
     * @param $user LDUser
     * @return string
     */
    public function secureModeHash($user)
    {
        if (is_null($user) || strlen($user->getKey()) === 0) {
            return "";
        }
        return hash_hmac("sha256", $user->getKey(), $this->_sdkKey, false);
    }

    /**
     * Publish events to LaunchDarkly
     * @return bool Whether the events were successfully published
     */
    public function flush()
    {
        try {
            return $this->_eventProcessor->flush();
        } catch (InvalidSDKKeyException $e) {
            $this->handleInvalidSDKKey();
        }
    }

    /**
     * @param $key string
     * @param $user LDUser
     * @param $variation int | null
     * @param $value mixed
     * @param $default
     * @param $version int | null
     * @param string | null $prereqOf
     */
    protected function _sendFlagRequestEvent($key, $user, $variation, $value, $default, $version = null, $prereqOf = null)
    {
        if ($this->isOffline() || !$this->_send_events) {
            return;
        }
        $this->_eventProcessor->enqueue(Util::newFeatureRequestEvent($key, $user, $variation, $value, $default, $version, $prereqOf));
    }

    protected function _get_default($key, $default)
    {
        if (array_key_exists($key, $this->_defaults)) {
            return $this->_defaults[$key];
        } else {
            return $default;
        }
    }

    protected function handleInvalidSDKKey() {
        $this->_logger->error("Received 401 error, no further HTTP requests will be made during lifetime of LDClient since SDK key is invalid");
        $this->_offline = true;
    }
}
