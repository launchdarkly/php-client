<?php
namespace LaunchDarkly;

use LaunchDarkly\Impl\EventFactory;
use LaunchDarkly\Impl\NullEventProcessor;
use LaunchDarkly\Integrations\Guzzle;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * A client for the LaunchDarkly API.
 */
class LDClient
{
    /** @var string */
    const DEFAULT_BASE_URI = 'https://app.launchdarkly.com';
    /** @var string */
    const DEFAULT_EVENTS_URI = 'https://events.launchdarkly.com';
    /**
     * The current SDK version.
     * @var string
     */
    const VERSION = '3.9.0';

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
    /** @var EventFactory */
    protected $_eventFactoryDefault;
    /** @var EventFactory */
    protected $_eventFactoryWithReasons;

    /**
     * Creates a new client instance that connects to LaunchDarkly.
     *
     * @param string $sdkKey The SDK key for your account
     * @param array $options Client configuration settings
     *     - `base_uri`: Base URI of the LaunchDarkly service. Change this if you are connecting to a Relay Proxy instance instead of
     * directly to LaunchDarkly.
     *     - `events_uri`: Base URI for sending events to LaunchDarkly. Change this if you are forwarding events through a Relay Proxy instance.
     *     - `timeout`: The maximum length of an HTTP request in seconds. Defaults to 3.
     *     - `connect_timeout`: The maximum number of seconds to wait while trying to connect to a server. Defaults to 3.
     *     - `cache`: An optional implementation of Guzzle's [CacheStorageInterface](https://github.com/Kevinrob/guzzle-cache-middleware/blob/master/src/Storage/CacheStorageInterface.php).
     * Defaults to an in-memory cache.
     *     - `send_events`: If set to false, disables the sending of events to LaunchDarkly. Defaults to true.
     *     - `logger`: An optional implementation of [Psr\Log\LoggerInterface](https://www.php-fig.org/psr/psr-3/). Defaults to a
     * Monolog\Logger sending all messages to the PHP error_log.
     *     - `offline`: If set to true, disables all network calls and always returns the default value for flags. Defaults to false.
     *     - `feature_requester`: An optional {@link \LaunchDarkly\FeatureRequester} implementation, or a class or factory for one.
     * Defaults to {@link \LaunchDarkly\Integrations\Guzzle::featureRequester()}.
     *     - `feature_requester_class`: Deprecated, equivalent to feature_requester.
     *     - `event_publisher`: An optional {@link \LaunchDarkly\EventPublisher} implementation, or a class or factory for one.
     * Defaults to {@link \LaunchDarkly\Integrations\Curl::eventPublisher()}.
     *     - `event_publisher_class`: Deprecated, equivalent to event_publisher.
     *     - `all_attributes_private`: If set to true, no user attributes (other than the key) will be sent back to LaunchDarkly.
     * Defaults to false.
     *     - `private_attribute_names`: An optional array of user attribute names to be marked private. Any users sent to LaunchDarkly
     * with this configuration active will have attributes with these names removed. You can also set private attributes on a
     * per-user basis in LDUserBuilder.
     *     - Other options may be available depending on any features you are using from the LaunchDarkly\Integrations namespace.
     *
     * @return LDClient
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

        $this->_eventFactoryDefault = new EventFactory(false);
        $this->_eventFactoryWithReasons = new EventFactory(true);

        if (isset($options['event_processor'])) {
            $ep = $options['event_processor'];
            if (is_callable($ep)) {
                $ep = $ep($sdkKey, $options);
            }
            $this->_eventProcessor = $ep;
        } elseif ($this->_offline || !$this->_send_events) {
            $this->_eventProcessor = new NullEventProcessor($sdkKey, $options);
        } else {
            $this->_eventProcessor = new EventProcessor($sdkKey, $options);
        }

        $this->_featureRequester = $this->getFeatureRequester($sdkKey, $options);
    }

    /**
     * @param string $sdkKey
     * @param mixed[] $options
     * @return FeatureRequester
     */
    private function getFeatureRequester($sdkKey, array $options)
    {
        if (isset($options['feature_requester']) && $options['feature_requester']) {
            $fr = $options['feature_requester'];
        } elseif (isset($options['feature_requester_class']) && $options['feature_requester_class']) {
            $fr = $options['feature_requester_class'];
        } else {
            $fr = Guzzle::featureRequester();
        }
        if ($fr instanceof FeatureRequester) {
            return $fr;
        }
        if (is_callable($fr)) {
            return $fr($this->_baseUri, $sdkKey, $options);
        }
        if (is_a($fr, FeatureRequester::class, true)) {
            return new $fr($this->_baseUri, $sdkKey, $options);
        }
        throw new \InvalidArgumentException('invalid feature_requester');
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
        $detail = $this->variationDetailInternal($key, $user, $default, $this->_eventFactoryDefault);
        return $detail->getValue();
    }

    /**
     * Calculates the value of a feature flag, and returns an object that describes the way the
     * value was determined.
     *
     * The "reason" property in the result will also be included in analytics events, if you are capturing
     * detailed event data for this flag.
     *
     * @param string $key The unique key for the feature flag
     * @param LDUser $user The end user requesting the flag
     * @param mixed $default The default value of the flag
     *
     * @return EvaluationDetail An EvaluationDetail object that includes the feature flag value
     * and evaluation reason.
     */
    public function variationDetail($key, $user, $default = false)
    {
        return $this->variationDetailInternal($key, $user, $default, $this->_eventFactoryWithReasons);
    }

    /**
     * @param string $key
     * @param LDUser $user
     * @param mixed $default
     * @param EventFactory $eventFactory
     */
    private function variationDetailInternal($key, $user, $default, $eventFactory)
    {
        $default = $this->_get_default($key, $default);

        $errorResult = function ($errorKind) use ($key, $default) {
            return new EvaluationDetail($default, null, EvaluationReason::error($errorKind));
        };
        $sendEvent = function ($detail, $flag) use ($key, $user, $default, $eventFactory) {
            if ($flag) {
                $event = $eventFactory->newEvalEvent($flag, $user, $detail, $default);
            } else {
                $event = $eventFactory->newUnknownFlagEvent($key, $user, $detail);
            }
            $this->_eventProcessor->enqueue($event);
        };

        if ($this->_offline) {
            return $errorResult(EvaluationReason::CLIENT_NOT_READY_ERROR);
        }

        try {
            if (!is_null($user) && $user->isKeyBlank()) {
                $this->_logger->warning("User key is blank. Flag evaluation will proceed, but the user will not be stored in LaunchDarkly.");
            }
            try {
                $flag = $this->_featureRequester->getFeature($key);
            } catch (UnrecoverableHTTPStatusException $e) {
                $this->handleUnrecoverableError();
                return $errorResult(EvaluationReason::EXCEPTION_ERROR);
            }

            if (is_null($flag)) {
                $result = $errorResult(EvaluationReason::FLAG_NOT_FOUND_ERROR);
                $sendEvent($result, null);
                return $result;
            }
            if (is_null($user) || is_null($user->getKey())) {
                $result = $errorResult(EvaluationReason::USER_NOT_SPECIFIED_ERROR);
                $sendEvent($result, $flag);
                $this->_logger->warning("Variation called with null user or null user key! Returning default value");
                return $result;
            }
            $evalResult = $flag->evaluate($user, $this->_featureRequester, $eventFactory);
            foreach ($evalResult->getPrerequisiteEvents() as $e) {
                $this->_eventProcessor->enqueue($e);
            }
            $detail = $evalResult->getDetail();
            if ($detail->isDefaultValue()) {
                $detail = new EvaluationDetail($default, null, $detail->getReason());
            }
            $sendEvent($detail, $flag);
            return $detail;
        } catch (\Exception $e) {
            $this->_logger->error("Caught $e");
            $result = $errorResult(EvaluationReason::EXCEPTION_ERROR);
            try {
                $sendEvent($result, null);
            } catch (\Exception $e) {
                $this->_logger->error("Caught $e");
            }
            return $result;
        }
    }

    /**
     * Deprecated name for variation().
     *
     * @deprecated Use variation() instead.
     * @param string $key The unique key for the feature flag
     * @param LDUser $user The end user requesting the flag
     * @param bool $default The default value of the flag
     * @return mixed
     */
    public function toggle($key, $user, $default = false)
    {
        $this->_logger->warning("Deprecated function: toggle() called. Use variation() instead.");
        return $this->variation($key, $user, $default);
    }

    /**
     * Returns whether the LaunchDarkly client is in offline mode.
     * @return bool
     */
    public function isOffline()
    {
        return $this->_offline;
    }

    /**
     * Tracks that a user performed an event.
     *
     * @param string $eventName The name of the event
     * @param LDUser $user The user that performed the event
     * @param mixed $data Optional additional information to associate with the event
     * @param number $metricValue A numeric value used by the LaunchDarkly experimentation feature in
     *   numeric custom metrics. Can be omitted if this event is used by only non-numeric metrics. This
     *   field will also be returned as part of the custom event for Data Export.
     * @return void
     */
    public function track($eventName, $user, $data = null, $metricValue = null)
    {
        if (is_null($user) || $user->isKeyBlank()) {
            $this->_logger->warning("Track called with null user or null/empty user key!");
            return;
        }
        $this->_eventProcessor->enqueue($this->_eventFactoryDefault->newCustomEvent($eventName, $user, $data, $metricValue));
    }

    /**
     * Reports details about a user.
     *
     * This simply registers the given user properties with LaunchDarkly without evaluating a feature flag.
     * This also happens automatically when you evaluate a flag.
     *
     * @param LDUser $user The user properties
     * @return void
     */
    public function identify($user)
    {
        if (is_null($user) || $user->isKeyBlank()) {
            $this->_logger->warning("Identify called with null user or null/empty user key!");
            return;
        }
        $this->_eventProcessor->enqueue($this->_eventFactoryDefault->newIdentifyEvent($user));
    }

    /**
     * Deprecated alternative to allFlagsState().
     *
     * This returns an array mapping feature flag keys to their evaluated results for a given user.
     *
     * If the result of a flag's evaluation would have returned the default variation, it will have a null entry.
     * If the client is offline, has not been initialized, or a null user or user with null/empty user key, null will be returned.
     * This method will not send analytics events back to LaunchDarkly.
     *
     * The most common use case for this method is to bootstrap a set of client-side feature flags from a back-end
     * service. Current versions of the JavaScript SDK require somewhat different data; for best compatibility,
     * use {@link allFlagsState()} instead.
     *
     * @deprecated Use allFlagsState() instead. Current versions of the client-side SDK will not
     * generate analytics events correctly if you pass the result of allFlags().
     * @param LDUser $user The end user requesting the feature flags
     * @return array|null Mapping of feature flag keys to their evaluated results for $user
     */
    public function allFlags($user)
    {
        $state = $this->allFlagsState($user);
        if (!$state->isValid()) {
            return null;
        }
        return $state->toValuesMap();
    }

    /**
     * Returns an object that encapsulates the state of all feature flags for a given user.
     *
     * This includes the flag values as well as other flag metadata that may be needed by front-end code,
     * since the most common use case for this method is [bootstrapping](https://docs.launchdarkly.com/docs/js-sdk-reference#section-bootstrapping)
     * in conjunction with the JavaScript browser SDK.
     *
     * This method does not send analytics events back to LaunchDarkly.
     *
     * @param LDUser $user The end user requesting the feature flags
     * @param array $options Optional properties affecting how the state is computed:
     *     - `clientSideOnly`: Set this to true to specify that only flags marked for client-side use
     * should be included; by default, all flags are included
     *     - `withReasons`: Set this to true to include evaluation reasons (see {@link variationDetail()})
     * @return FeatureFlagsState a FeatureFlagsState object (will never be null)
     */
    public function allFlagsState($user, $options = array())
    {
        if (is_null($user) || is_null($user->getKey())) {
            $this->_logger->warning("allFlagsState called with null user or null/empty user key! Returning empty state");
            return new FeatureFlagsState(false);
        }
        if ($this->isOffline()) {
            return new FeatureFlagsState(false);
        }
        try {
            $flags = $this->_featureRequester->getAllFeatures();
        } catch (UnrecoverableHTTPStatusException $e) {
            $this->handleUnrecoverableError();
            return new FeatureFlagsState(false);
        }
        if ($flags === null) {
            return new FeatureFlagsState(false);
        }

        $preloadedRequester = new PreloadedFeatureRequester($this->_featureRequester, $flags);
        // This saves us from doing repeated queries for prerequisite flags during evaluation

        $state = new FeatureFlagsState(true);
        $clientOnly = isset($options['clientSideOnly']) && $options['clientSideOnly'];
        $withReasons = isset($options['withReasons']) && $options['withReasons'];
        $detailsOnlyIfTracked = isset($options['detailsOnlyForTrackedFlags']) && $options['detailsOnlyForTrackedFlags'];
        foreach ($flags as $key => $flag) {
            if ($clientOnly && !$flag->isClientSide()) {
                continue;
            }
            $result = $flag->evaluate($user, $preloadedRequester, $this->_eventFactoryDefault);
            $state->addFlag($flag, $result->getDetail(), $withReasons, $detailsOnlyIfTracked);
        }
        return $state;
    }

    /**
     * Associates two users for analytics purposes.
     *
     * This can be helpful in the situation where a person is represented by multiple
     * LaunchDarkly users. This may happen, for example, when a person initially logs into
     * an application-- the person might be represented by an anonymous user prior to logging
     * in and a different user after logging in, as denoted by a different user key.
     *
     * @param LDUser $user the newly identified user.
     * @param LDUser $previousUser the previously identified user.
     * @return void
     */
    public function alias($user, $previousUser)
    {
        if (is_null($user) || is_null($user->getKey())) {
            $this->_logger->warning("Alias called with null user or null/empty user!");
            return;
        }

        if (is_null($previousUser) || is_null($previousUser->getKey())) {
            $this->_logger->warning("Alias called with null user or null/empty previousUser!");
            return;
        }

        $event = $this->_eventFactoryDefault->newAliasEvent($user, $previousUser);
        $this->_eventProcessor->enqueue($event);
    }

    /**
     * Generates an HMAC sha256 hash for use in Secure mode.
     *
     * See: https://docs.launchdarkly.com/docs/js-sdk-reference#section-secure-mode
     *
     * @param LDUser $user
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
     * Publishes any pending analytics events to LaunchDarkly.
     *
     * This is normally done automatically by the SDK.
     * @return bool Whether the events were successfully published
     */
    public function flush()
    {
        try {
            return $this->_eventProcessor->flush();
        } catch (UnrecoverableHTTPStatusException $e) {
            $this->handleUnrecoverableError();
        }
    }

    protected function _get_default($key, $default)
    {
        if (array_key_exists($key, $this->_defaults)) {
            return $this->_defaults[$key];
        } else {
            return $default;
        }
    }

    protected function handleUnrecoverableError()
    {
        $this->_logger->error("Due to an unrecoverable HTTP error, no further HTTP requests will be made during lifetime of LDClient");
        $this->_offline = true;
    }
}
