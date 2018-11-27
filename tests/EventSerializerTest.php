<?php
namespace LaunchDarkly\Tests;

use LaunchDarkly\EventSerializer;
use LaunchDarkly\LDUserBuilder;
use PHPUnit\Framework\TestCase;

class EventSerializerTest extends TestCase
{
    private function getUser()
    {
        return (new LDUserBuilder('abc'))
            ->firstName('Sue')
            ->custom(array('bizzle' => 'def', 'dizzle' => 'ghi'))
            ->build();
    }

    private function getUserSpecifyingOwnPrivateAttr()
    {
        return (new LDUserBuilder('abc'))
            ->firstName('Sue')
            ->customAttribute('bizzle', 'def')
            ->privateCustomAttribute('dizzle', 'ghi')
            ->build();
    }

    private function getFullUserResult()
    {
        return array(
            'key' => 'abc',
            'firstName' => 'Sue',
            'custom' => array('bizzle' => 'def', 'dizzle' => 'ghi')
        );
    }

    private function getUserResultWithAllAttrsHidden()
    {
        return array(
            'key' => 'abc',
            'privateAttrs' => array('bizzle', 'dizzle', 'firstName')
        );
    }

    private function getUserResultWithSomeAttrsHidden()
    {
        return array(
            'key' => 'abc',
            'custom' => array('dizzle' => 'ghi'),
            'privateAttrs' => array('bizzle', 'firstName')
        );
    }

    private function getUserResultWithOwnSpecifiedAttrHidden()
    {
        return array(
            'key' => 'abc',
            'firstName' => 'Sue',
            'custom' => array('bizzle' => 'def'),
            'privateAttrs' => array('dizzle')
        );
    }

    private function makeEvent($user)
    {
        return array(
            'creationDate' => 1000000,
            'key' => 'abc',
            'kind' => 'thing',
            'user' => $user
        );
    }

    private function getJsonForUserBySerializingEvent($user)
    {
        $es = new EventSerializer(array());
        $event = $this->makeEvent($user);
        return json_decode($es->serializeEvents(array($event)), true)[0]['user'];
    }

    public function testAllUserAttrsSerialized()
    {
        $es = new EventSerializer(array());
        $event = $this->makeEvent($this->getUser());
        $json = $es->serializeEvents(array($event));
        $expected = $this->makeEvent($this->getFullUserResult());
        $this->assertEquals(array($expected), json_decode($json, true));
    }

    public function testAllUserAttrsPrivate()
    {
        $es = new EventSerializer(array('all_attributes_private' => true));
        $event = $this->makeEvent($this->getUser());
        $json = $es->serializeEvents(array($event));
        $expected = $this->makeEvent($this->getUserResultWithAllAttrsHidden());
        $this->assertEquals(array($expected), json_decode($json, true));
    }

    public function testSomeUserAttrsPrivate()
    {
        $es = new EventSerializer(array('private_attribute_names' => array('firstName', 'bizzle')));
        $event = $this->makeEvent($this->getUser());
        $json = $es->serializeEvents(array($event));
        $expected = $this->makeEvent($this->getUserResultWithSomeAttrsHidden());
        $this->assertEquals(array($expected), json_decode($json, true));
    }

    public function testPerUserPrivateAttr()
    {
        $es = new EventSerializer(array());
        $event = $this->makeEvent($this->getUserSpecifyingOwnPrivateAttr());
        $json = $es->serializeEvents(array($event));
        $expected = $this->makeEvent($this->getUserResultWithOwnSpecifiedAttrHidden());
        $this->assertEquals(array($expected), json_decode($json, true));
    }

    public function testPerUserPrivateAttrPlusGlobalPrivateAttrs()
    {
        $es = new EventSerializer(array('private_attribute_names' => array('firstName', 'bizzle')));
        $event = $this->makeEvent($this->getUserSpecifyingOwnPrivateAttr());
        $json = $es->serializeEvents(array($event));
        $expected = $this->makeEvent($this->getUserResultWithAllAttrsHidden());
        $this->assertEquals(array($expected), json_decode($json, true));
    }

    public function testUserKey()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("foo@bar.com", $json['key']);
    }

    public function testEmptyCustom()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertFalse(isset($json['custom']));
    }

    public function testEmptyPrivateCustom()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->privateCustomAttribute("my-key", "my-value")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertFalse(isset($json['custom']));
    }

    public function testUserSecondary()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->secondary("secondary")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("secondary", $json['secondary']);
    }

    public function testUserIP()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->ip("127.0.0.1")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("127.0.0.1", $json['ip']);
    }

    public function testUserCountry()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->country("US")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("US", $json['country']);
    }

    public function testUserEmail()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->email("foo+test@bar.com")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("foo+test@bar.com", $json['email']);
    }

    public function testUserName()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->name("Foo Bar")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("Foo Bar", $json['name']);
    }

    public function testUserAvatar()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->avatar("http://www.gravatar.com/avatar/1")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("http://www.gravatar.com/avatar/1", $json['avatar']);
    }

    public function testUserFirstName()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->firstName("Foo")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("Foo", $json['firstName']);
    }

    public function testUserLastName()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->lastName("Bar")->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals("Bar", $json['lastName']);
    }

    public function testUserAnonymous()
    {
        $builder = new LDUserBuilder("foo@bar.com");
        $user = $builder->anonymous(true)->build();
        $json = $this->getJsonForUserBySerializingEvent($user);
        $this->assertEquals(true, $json['anonymous']);
    }
}
