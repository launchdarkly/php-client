<?php
namespace LaunchDarkly\Tests;

use InvalidArgumentException;
use LaunchDarkly\EvaluationReason;
use LaunchDarkly\FeatureFlag;
use LaunchDarkly\FeatureRequester;
use LaunchDarkly\LDClient;
use LaunchDarkly\LDUser;
use LaunchDarkly\LDUserBuilder;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class LDClientTest extends TestCase
{
    public function testDefaultCtor()
    {
        $this->assertInstanceOf(LDClient::class, new LDClient("BOGUS_SDK_KEY"));
    }

    private function makeOffFlagWithValue($key, $value)
    {
        $flagJson = array(
            'key' => $key,
            'version' => 100,
            'deleted' => false,
            'on' => false,
            'targets' => array(),
            'prerequisites' => array(),
            'rules' => array(),
            'offVariation' => 1,
            'fallthrough' => array('variation' => 0),
            'variations' => array('FALLTHROUGH', $value),
            'salt' => ''
        );
        return FeatureFlag::decode($flagJson);
    }

    private function makeFlagThatEvaluatesToNull($key)
    {
        $flagJson = array(
            'key' => $key,
            'version' => 100,
            'deleted' => false,
            'on' => false,
            'targets' => array(),
            'prerequisites' => array(),
            'rules' => array(),
            'offVariation' => null,
            'fallthrough' => array('variation' => 0),
            'variations' => array('none'),
            'salt' => ''
        );
        return FeatureFlag::decode($flagJson);
    }

    public function testVariationReturnsFlagValue()
    {
        $flag = $this->makeOffFlagWithValue('feature', 'value');
        MockFeatureRequester::$flags = array('feature' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $value = $client->variation('feature', new LDUser('userkey'), 'default');
        $this->assertEquals('value', $value);
    }

    public function testVariationDetailReturnsFlagValue()
    {
        $flag = $this->makeOffFlagWithValue('feature', 'value');
        MockFeatureRequester::$flags = array('feature' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $detail = $client->variationDetail('feature', new LDUser('userkey'), 'default');
        $this->assertEquals('value', $detail->getValue());
        $this->assertFalse($detail->isDefaultValue());
        $this->assertEquals(1, $detail->getVariationIndex());
        $this->assertEquals(EvaluationReason::off(), $detail->getReason());
    }

    public function testVariationReturnsDefaultIfFlagEvaluatesToNull()
    {
        $flag = $this->makeFlagThatEvaluatesToNull('feature');
        MockFeatureRequester::$flags = array('feature' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $value = $client->variation('feature', new LDUser('userkey'), 'default');
        $this->assertEquals('default', $value);
    }

    public function testVariationDetailReturnsDefaultIfFlagEvaluatesToNull()
    {
        $flag = $this->makeFlagThatEvaluatesToNull('feature');
        MockFeatureRequester::$flags = array('feature' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $detail = $client->variationDetail('feature', new LDUser('userkey'), 'default');
        $this->assertEquals('default', $detail->getValue());
        $this->assertTrue($detail->isDefaultValue());
        $this->assertNull($detail->getVariationIndex());
        $this->assertEquals(EvaluationReason::off(), $detail->getReason());
    }

    public function testVariationReturnsDefaultForUnknownFlag()
    {
        MockFeatureRequester::$flags = array();
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $this->assertEquals('argdef', $client->variation('foo', new LDUser('userkey'), 'argdef'));
    }

    public function testVariationDetailReturnsDefaultForUnknownFlag()
    {
        MockFeatureRequester::$flags = array();
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $detail = $client->variationDetail('foo', new LDUser('userkey'), 'default');
        $this->assertEquals('default', $detail->getValue());
        $this->assertTrue($detail->isDefaultValue());
        $this->assertNull($detail->getVariationIndex());
        $this->assertEquals(EvaluationReason::error(EvaluationReason::FLAG_NOT_FOUND_ERROR), $detail->getReason());
    }

    public function testVariationReturnsDefaultFromConfigurationForUnknownFlag()
    {
        MockFeatureRequester::$flags = array();
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false,
            'defaults' => array('foo' => 'fromarray')
        ));

        $this->assertEquals('fromarray', $client->variation('foo', new LDUser('userkey'), 'argdef'));
    }

    public function testVariationSendsEvent()
    {
        $flag = $this->makeOffFlagWithValue('flagkey', 'flagvalue');
        MockFeatureRequester::$flags = array('flagkey' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => true
        ));

        $user = new LDUser('userkey');
        $client->variation('flagkey', new LDUser('userkey'), 'default');
        $proc = $this->getPrivateField($client, '_eventProcessor');
        $queue = $this->getPrivateField($proc, '_queue');
        $this->assertEquals(1, sizeof($queue));
        $event = $queue[0];
        $this->assertEquals('feature', $event['kind']);
        $this->assertEquals('flagkey', $event['key']);
        $this->assertEquals($flag->getVersion(), $event['version']);
        $this->assertEquals('flagvalue', $event['value']);
        $this->assertEquals(1, $event['variation']);
        $this->assertEquals($user, $event['user']);
        $this->assertEquals('default', $event['default']);
        $this->assertFalse(isset($event['reason']));
    }

    public function testVariationDetailSendsEvent()
    {
        $flag = $this->makeOffFlagWithValue('FUCKINGWEIRDflagkey', 'flagvalue');
        MockFeatureRequester::$flags = array('FUCKINGWEIRDflagkey' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => true
        ));

        $user = new LDUser('userkey');
        $client->variationDetail('FUCKINGWEIRDflagkey', $user, 'default');
        $proc = $this->getPrivateField($client, '_eventProcessor');
        $queue = $this->getPrivateField($proc, '_queue');
        $this->assertEquals(1, sizeof($queue));
        $event = $queue[0];
        $this->assertEquals('feature', $event['kind']);
        $this->assertEquals('FUCKINGWEIRDflagkey', $event['key']);
        $this->assertEquals($flag->getVersion(), $event['version']);
        $this->assertEquals('flagvalue', $event['value']);
        $this->assertEquals(1, $event['variation']);
        $this->assertEquals($user, $event['user']);
        $this->assertEquals('default', $event['default']);
        $this->assertEquals(array('kind' => 'OFF'), $event['reason']);
    }

    public function testVariationSendsEventForUnknownFlag()
    {
        MockFeatureRequester::$flags = array();
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => true
        ));

        $user = new LDUser('userkey');
        $client->variation('flagkey', new LDUser('userkey'), 'default');
        $proc = $this->getPrivateField($client, '_eventProcessor');
        $queue = $this->getPrivateField($proc, '_queue');
        $this->assertEquals(1, sizeof($queue));
        $event = $queue[0];
        $this->assertEquals('feature', $event['kind']);
        $this->assertEquals('flagkey', $event['key']);
        $this->assertNull($event['version']);
        $this->assertEquals('default', $event['value']);
        $this->assertNull($event['variation']);
        $this->assertEquals($user, $event['user']);
        $this->assertEquals('default', $event['default']);
        $this->assertFalse(isset($event['reason']));
    }

    public function testVariationDetailSendsEventForUnknownFlag()
    {
        MockFeatureRequester::$flags = array();
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => true
        ));

        $user = new LDUser('userkey');
        $client->variationDetail('flagkey', new LDUser('userkey'), 'default');
        $proc = $this->getPrivateField($client, '_eventProcessor');
        $queue = $this->getPrivateField($proc, '_queue');
        $this->assertEquals(1, sizeof($queue));
        $event = $queue[0];
        $this->assertEquals('feature', $event['kind']);
        $this->assertEquals('flagkey', $event['key']);
        $this->assertNull($event['version']);
        $this->assertEquals('default', $event['value']);
        $this->assertNull($event['variation']);
        $this->assertEquals($user, $event['user']);
        $this->assertEquals('default', $event['default']);
        $this->assertEquals(array('kind' => 'ERROR', 'errorKind' => 'FLAG_NOT_FOUND'), $event['reason']);
    }

    public function testAllFlagsReturnsFlagValues()
    {
        $flagJson = array(
            'key' => 'feature',
            'version' => 100,
            'deleted' => false,
            'on' => false,
            'targets' => array(),
            'prerequisites' => array(),
            'rules' => array(),
            'offVariation' => 1,
            'fallthrough' => array('variation' => 0),
            'variations' => array('fall', 'off', 'on'),
            'salt' => ''
        );
        $flag = FeatureFlag::decode($flagJson);

        MockFeatureRequester::$flags = array('feature' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $builder = new LDUserBuilder(3);
        $user = $builder->build();
        $values = $client->allFlags($user);

        $this->assertEquals(array('feature' => 'off'), $values);
    }

    public function testAllFlagsStateReturnsState()
    {
        $flagJson = array(
            'key' => 'feature',
            'version' => 100,
            'deleted' => false,
            'on' => false,
            'targets' => array(),
            'prerequisites' => array(),
            'rules' => array(),
            'offVariation' => 1,
            'fallthrough' => array('variation' => 0),
            'variations' => array('fall', 'off', 'on'),
            'salt' => '',
            'trackEvents' => true,
            'debugEventsUntilDate' => 1000
        );
        $flag = FeatureFlag::decode($flagJson);

        MockFeatureRequester::$flags = array('feature' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $builder = new LDUserBuilder(3);
        $user = $builder->build();
        $state = $client->allFlagsState($user);

        $this->assertTrue($state->isValid());
        $this->assertEquals(array('feature' => 'off'), $state->toValuesMap());
        $expectedState = array(
            'feature' => 'off',
            '$flagsState' => array(
                'feature' => array(
                    'variation' => 1,
                    'version' => 100,
                    'trackEvents' => true,
                    'debugEventsUntilDate' => 1000
                )
            ),
            '$valid' => true
        );
        $this->assertEquals($expectedState, $state->jsonSerialize());
    }

    public function testAllFlagsStateReturnsStateWithReasons()
    {
        $flagJson = array(
            'key' => 'feature',
            'version' => 100,
            'deleted' => false,
            'on' => false,
            'targets' => array(),
            'prerequisites' => array(),
            'rules' => array(),
            'offVariation' => 1,
            'fallthrough' => array('variation' => 0),
            'variations' => array('fall', 'off', 'on'),
            'salt' => '',
            'trackEvents' => true,
            'debugEventsUntilDate' => 1000
        );
        $flag = FeatureFlag::decode($flagJson);

        MockFeatureRequester::$flags = array('feature' => $flag);
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $builder = new LDUserBuilder(3);
        $user = $builder->build();
        $state = $client->allFlagsState($user, array('withReasons' => true));

        $this->assertTrue($state->isValid());
        $this->assertEquals(array('feature' => 'off'), $state->toValuesMap());
        $expectedState = array(
            'feature' => 'off',
            '$flagsState' => array(
                'feature' => array(
                    'variation' => 1,
                    'version' => 100,
                    'trackEvents' => true,
                    'debugEventsUntilDate' => 1000,
                    'reason' => array('kind' => 'OFF')
                )
            ),
            '$valid' => true
        );
        $this->assertEquals($expectedState, $state->jsonSerialize());
    }

    public function testAllFlagsStateCanFilterForClientSideFlags()
    {
        $flagJson = array('key' => 'server-side-1', 'version' => 1, 'on' => false, 'salt' => '', 'deleted' => false,
            'targets' => array(), 'rules' => array(), 'prerequisites' => array(), 'fallthrough' => array(),
            'offVariation' => 0, 'variations' => array('a'), 'clientSide' => false);
        $flag1 = FeatureFlag::decode($flagJson);
        $flagJson['key'] = 'server-side-2';
        $flag2 = FeatureFlag::decode($flagJson);
        $flagJson['key'] = 'client-side-1';
        $flagJson['clientSide'] = true;
        $flagJson['variations'] = array('value1');
        $flag3 = FeatureFlag::decode($flagJson);
        $flagJson['key'] = 'client-side-2';
        $flagJson['variations'] = array('value2');
        $flag4 = FeatureFlag::decode($flagJson);
        MockFeatureRequester::$flags = array(
            $flag1->getKey() => $flag1, $flag2->getKey() => $flag2, $flag3->getKey() => $flag3, $flag4->getKey() => $flag4
        );
        $client = new LDClient("someKey", array(
            'feature_requester_class' => MockFeatureRequester::class,
            'events' => false
            ));

        $builder = new LDUserBuilder(3);
        $user = $builder->build();
        $state = $client->allFlagsState($user, array('clientSideOnly' => true));

        $this->assertTrue($state->isValid());
        $this->assertEquals(array('client-side-1' => 'value1', 'client-side-2' => 'value2'), $state->toValuesMap());
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testOnlyValidFeatureRequester()
    {
        new LDClient("BOGUS_SDK_KEY", ['feature_requester_class' => \stdClass::class]);
    }

    public function testSecureModeHash()
    {
        $client = new LDClient("secret", ['offline' => true]);
        $user = new LDUser("Message");
        $this->assertEquals("aa747c502a898200f9e4fa21bac68136f886a0e27aec70ba06daf2e2a5cb5597", $client->secureModeHash($user));
    }

    public function testLoggerInterfaceWarn()
    {
        // Use LoggerInterface impl, instead of concreate Logger from Monolog, to demonstrate the problem with `warn`.
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMock();

        $logger->expects(self::atLeastOnce())->method('warning');

        $client = new LDClient('secret', [
            'logger' => $logger,
        ]);

        $user = new LDUser('');

        $client->variation('MyFeature', $user);
    }

    private function getPrivateField(&$object, $fieldName)
    {
        $reflection = new \ReflectionClass(get_class($object));
        $field = $reflection->getProperty($fieldName);
        $field->setAccessible(true);

        return $field->getValue($object);
    }
}
