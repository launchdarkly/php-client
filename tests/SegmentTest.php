<?php
namespace LaunchDarkly\Tests;

use LaunchDarkly\LDUserBuilder;
use LaunchDarkly\Segment;
use PHPUnit\Framework\TestCase;

class SegmentTest extends TestCase
{
    public function testExplicitIncludeUser()
    {
        $json = array(
            'key' => 'test',
            'included' => array('foo'),
            'excluded' => array(),
            'rules' => array(),
            'salt' => 'salt',
            'version' => 1,
            'deleted' => false
        );
        $segment = Segment::decode($json);
        $ub = new LDUserBuilder('foo');
        $this->assertTrue($segment->matchesUser($ub->build()));
    }

    public function testExplicitExcludeUser()
    {
        $json = array(
            'key' => 'test',
            'included' => array(),
            'excluded' => array('foo'),
            'rules' => array(),
            'salt' => 'salt',
            'version' => 1,
            'deleted' => false
        );
        $segment = Segment::decode($json);
        $ub = new LDUserBuilder('foo');
        $this->assertFalse($segment->matchesUser($ub->build()));
    }

    public function testExplicitIncludePasPrecedence()
    {
        $json = array(
            'key' => 'test',
            'included' => array('foo'),
            'excluded' => array('foo'),
            'rules' => array(),
            'salt' => 'salt',
            'version' => 1,
            'deleted' => false
        );
        $segment = Segment::decode($json);
        $ub = new LDUserBuilder('foo');
        $this->assertTrue($segment->matchesUser($ub->build()));
    }

    public function testMatchingRuleWithFullRollout()
    {
        $json = array(
            'key' => 'test',
            'included' => array(),
            'excluded' => array(),
            'salt' => 'salt',
            'rules' => array(
                array(
                    'clauses' => array(
                        array(
                            'attribute' => 'email',
                            'op' => 'in',
                            'values' => array('test@example.com'),
                            'negate' => false
                        )
                    ),
                    'weight' => 100000
                )
            ),
            'version' => 1,
            'deleted' => false
        );
        $segment = Segment::decode($json);
        $ub = new LDUserBuilder('foo');
        $ub->email('test@example.com');
        $this->assertTrue($segment->matchesUser($ub->build()));
    }

    public function testMatchingRuleWithZeroRollout()
    {
        $json = array(
            'key' => 'test',
            'included' => array(),
            'excluded' => array(),
            'salt' => 'salt',
            'rules' => array(
                array(
                    'clauses' => array(
                        array(
                            'attribute' => 'email',
                            'op' => 'in',
                            'values' => array('test@example.com'),
                            'negate' => false
                        )
                    ),
                    'weight' => 0
                )
            ),
            'version' => 1,
            'deleted' => false
        );
        $segment = Segment::decode($json);
        $ub = new LDUserBuilder('foo');
        $ub->email('test@example.com');
        $this->assertFalse($segment->matchesUser($ub->build()));
    }

    public function testMatchingRuleWithMultipleClauses()
    {
        $json = array(
            'key' => 'test',
            'included' => array(),
            'excluded' => array(),
            'salt' => 'salt',
            'rules' => array(
                array(
                    'clauses' => array(
                        array(
                            'attribute' => 'email',
                            'op' => 'in',
                            'values' => array('test@example.com'),
                            'negate' => false
                        ),
                        array(
                            'attribute' => 'name',
                            'op' => 'in',
                            'values' => array('bob'),
                            'negate' => false
                        )
                    ),
                    'weight' => 100000
                )
            ),
            'version' => 1,
            'deleted' => false
        );
        $segment = Segment::decode($json);
        $ub = new LDUserBuilder('foo');
        $ub->email('test@example.com');
        $ub->name('bob');
        $this->assertTrue($segment->matchesUser($ub->build()));
    }

    public function testNonMatchingRuleWithMultipleClauses()
    {
        $json = array(
            'key' => 'test',
            'included' => array(),
            'excluded' => array(),
            'salt' => 'salt',
            'rules' => array(
                array(
                    'clauses' => array(
                        array(
                            'attribute' => 'email',
                            'op' => 'in',
                            'values' => array('test@example.com'),
                            'negate' => false
                        ),
                        array(
                            'attribute' => 'name',
                            'op' => 'in',
                            'values' => array('bill'),
                            'negate' => false
                        )
                    ),
                    'weight' => 100000
                )
            ),
            'version' => 1,
            'deleted' => false
        );
        $segment = Segment::decode($json);
        $ub = new LDUserBuilder('foo');
        $ub->email('test@example.com');
        $ub->name('bob');
        $this->assertFalse($segment->matchesUser($ub->build()));
    }
}
