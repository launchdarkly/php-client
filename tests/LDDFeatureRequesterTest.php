<?php

namespace LaunchDarkly\Tests;

use LaunchDarkly\FeatureFlag;
use LaunchDarkly\LDDFeatureRequester;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class LDDFeatureRequesterTest extends TestCase
{
    /** @var ClientInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $predisClient;

    /** @var LoggerInterface */
    private $logger;

    protected function setUp()
    {
        parent::setUp();

        $this->logger = new NullLogger();

        $this->predisClient = $this->getMockBuilder(ClientInterface::class)
            ->setMethods(['hget'])
            ->getMockForAbstractClass();
    }

    public function testGetFeature()
    {
        $sut = new LDDFeatureRequester('example.com', 'MySdkKey', [
            'logger' => $this->logger,
            'predis_client' => $this->predisClient,
        ]);

        $this->predisClient->method('hget')->with('launchdarkly:features', 'foo')
            ->willReturn(json_encode([
                'key' => 'foo',
                'version' => 14,
                'on' => true,
                'prerequisites' => [],
                'salt' => 'c3lzb3BzLXRlc3Q=',
                'sel' => '8ed13de1bfb14507ba7e6dde01f3e035',
                'targets' => [
                    [
                        'values' => [],
                        'variation' => 0,
                    ],
                    [
                        'values' => [],
                        'variation' => 1,
                    ],
                ],
                'rules' => [],
                'fallthrough' => [
                    'variation' => 0,
                ],
                'offVariation' => null,
                'variations' => [
                    true,
                    false,
                ],
                'deleted' => false,
            ]));

        $featureFlag = $sut->getFeature('foo');

        self::assertInstanceOf(FeatureFlag::class, $featureFlag);
        self::assertTrue($featureFlag->isOn());
    }
}
