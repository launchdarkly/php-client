<?php

namespace LaunchDarkly\Tests;

use LaunchDarkly\Operators;
use PHPUnit\Framework\TestCase;

class OperatorsTest extends TestCase
{
    public function testIn()
    {
        $this->assertTrue(Operators::apply("in", "A string to match", "A string to match"));
        $this->assertFalse(Operators::apply("in", "A string to match", true));
        $this->assertTrue(Operators::apply("in", 34, 34));
        $this->assertTrue(Operators::apply("in", 34, 34.0));
        $this->assertFalse(Operators::apply("in", 34, true));
        $this->assertTrue(Operators::apply("in", false, false));
        $this->assertTrue(Operators::apply("in", true, true));
        $this->assertFalse(Operators::apply("in", true, false));
        $this->assertFalse(Operators::apply("in", false, true));
    }

    public function testStartsWith()
    {
        $this->assertTrue(Operators::apply("startsWith", "start", "start"));
        $this->assertTrue(Operators::apply("startsWith", "start plus more", "start"));
        $this->assertFalse(Operators::apply("startsWith", "does not contain", "start"));
        $this->assertFalse(Operators::apply("startsWith", "does not start with", "start"));
    }

    public function testEndsWith()
    {
        $this->assertTrue(Operators::apply("endsWith", "end", "end"));
        $this->assertTrue(Operators::apply("endsWith", "something somethingend", "end"));
        $this->assertFalse(Operators::apply("endsWith", "does not contain", "end"));
        $this->assertFalse(Operators::apply("endsWith", "does not end with", "end"));
    }

    public function testMatches()
    {
        $this->assertTrue(Operators::apply("matches", "anything", ".*"));
        $this->assertTrue(Operators::apply("matches", "darn", "(\\W|^)(baloney|darn|drat|fooey|gosh\\sdarnit|heck)(\\W|$)"));
    }

    public function testParseTime()
    {
        $this->assertEquals(0, Operators::parseTime(0));
        $this->assertEquals(100, Operators::parseTime(100));
        $this->assertEquals(100, Operators::parseTime(100));
        $this->assertEquals(1000, Operators::parseTime("1970-01-01T00:00:01Z"));
        $this->assertEquals(1001, Operators::parseTime("1970-01-01T00:00:01.001Z"));


        $this->assertEquals(null, Operators::parseTime("NOT A REAL TIMESTAMP"));
        $this->assertEquals(null, Operators::parseTime([]));
    }

    public function testSemVer()
    {
        $this->assertTrue(Operators::apply("semVerEqual", "2.0.0", "2.0.0"));
        $this->assertTrue(Operators::apply("semVerEqual", "2.0", "2.0.0"));
        $this->assertTrue(Operators::apply("semVerEqual", "2", "2.0.0"));
        $this->assertTrue(Operators::apply("semVerEqual", "2-rc1", "2.0.0-rc1"));
        $this->assertTrue(Operators::apply("semVerEqual", "2+build2", "2.0.0+build2"));
        $this->assertFalse(Operators::apply("semVerEqual", "2.0.0", "2.0.1"));
        $this->assertTrue(Operators::apply("semVerLessThan", "2.0.0", "2.0.1"));
        $this->assertTrue(Operators::apply("semVerLessThan", "2.0", "2.0.1"));
        $this->assertFalse(Operators::apply("semVerLessThan", "2.0.1", "2.0.0"));
        $this->assertTrue(Operators::apply("semVerLessThan", "2.0.0-rc", "2.0.0"));
        $this->assertTrue(Operators::apply("semVerLessThan", "2.0.0-rc", "2.0.0-rc.beta"));
        $this->assertTrue(Operators::apply("semVerGreaterThan", "2.0.1", "2.0.0"));
        $this->assertFalse(Operators::apply("semVerGreaterThan", "2.0.0", "2.0.1"));
        $this->assertTrue(Operators::apply("semVerGreaterThan", "2.0.0-rc.1", "2.0.0-rc.0"));
        $this->assertFalse(Operators::apply("semVerLessThan", "2.0.0", "xbad%ver"));
        $this->assertFalse(Operators::apply("semVerGreaterThan", "2.0.0", "xbad%ver"));
    }
}
