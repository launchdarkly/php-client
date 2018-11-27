<?php
namespace LaunchDarkly\Tests;

use LaunchDarkly\EvaluationReason;
use PHPUnit\Framework\TestCase;

class EvaluationReasonTest extends TestCase
{
    public function testOffReasonSerialization()
    {
        $reason = EvaluationReason::off();
        $json = json_encode($reason);
        $this->assertEquals(array('kind' => 'OFF'), json_decode($json, true));
        $this->assertEquals('OFF', (string)$reason);
    }

    public function testFallthroughReasonSerialization()
    {
        $reason = EvaluationReason::fallthrough();
        $json = json_encode($reason);
        $this->assertEquals(array('kind' => 'FALLTHROUGH'), json_decode($json, true));
        $this->assertEquals('FALLTHROUGH', (string)$reason);
    }

    public function testTargetMatchReasonSerialization()
    {
        $reason = EvaluationReason::targetMatch();
        $json = json_encode($reason);
        $this->assertEquals(array('kind' => 'TARGET_MATCH'), json_decode($json, true));
        $this->assertEquals('TARGET_MATCH', (string)$reason);
    }

    public function testRuleMatchReasonSerialization()
    {
        $reason = EvaluationReason::ruleMatch(0, 'id');
        $json = json_encode($reason);
        $this->assertEquals(array('kind' => 'RULE_MATCH', 'ruleIndex' => 0, 'ruleId' => 'id'),
            json_decode($json, true));
        $this->assertEquals('RULE_MATCH(0,id)', (string)$reason);
    }

    public function testPrerequisiteFailedReasonSerialization()
    {
        $reason = EvaluationReason::prerequisiteFailed('key');
        $json = json_encode($reason);
        $this->assertEquals(array('kind' => 'PREREQUISITE_FAILED', 'prerequisiteKey' => 'key'),
            json_decode($json, true));
        $this->assertEquals('PREREQUISITE_FAILED(key)', (string)$reason);
    }

    public function testErrorReasonSerialization()
    {
        $reason = EvaluationReason::error(EvaluationReason::EXCEPTION_ERROR);
        $json = json_encode($reason);
        $this->assertEquals(array('kind' => 'ERROR', 'errorKind' => 'EXCEPTION'), json_decode($json, true));
        $this->assertEquals('ERROR(EXCEPTION)', (string)$reason);
    }
}
