<?php

namespace LaunchDarkly\Tests;

use LaunchDarkly\SemanticVersion;
use PHPUnit\Framework\TestCase;

class SemanticVersionTest extends TestCase
{
    public function testCanParseSimpleCompleteVersion()
    {
        $sv = SemanticVersion::parse('2.3.4');
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(3, $sv->minor);
        $this->assertEquals(4, $sv->patch);
        $this->assertEquals('', $sv->prerelease);
        $this->assertEquals('', $sv->build);
    }

    public function testCanParseVersionWithPrerelease()
    {
        $sv = SemanticVersion::parse('2.3.4-beta1.rc2');
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(3, $sv->minor);
        $this->assertEquals(4, $sv->patch);
        $this->assertEquals('beta1.rc2', $sv->prerelease);
        $this->assertEquals('', $sv->build);
    }

    public function testCanParseVersionWithBuild()
    {
        $sv = SemanticVersion::parse('2.3.4+build2.4');
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(3, $sv->minor);
        $this->assertEquals(4, $sv->patch);
        $this->assertEquals('', $sv->prerelease);
        $this->assertEquals('build2.4', $sv->build);
    }

    public function testCanParseVersionWithPrereleaseAndBuild()
    {
        $sv = SemanticVersion::parse('2.3.4-beta1.rc2+build2.4');
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(3, $sv->minor);
        $this->assertEquals(4, $sv->patch);
        $this->assertEquals('beta1.rc2', $sv->prerelease);
        $this->assertEquals('build2.4', $sv->build);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage not a valid semantic version
     */
    public function testLeadingZeroNotAllowedInMajor()
    {
        SemanticVersion::parse('02.3.4');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage not a valid semantic version
     */
    public function testLeadingZeroNotAllowedInMinor()
    {
        SemanticVersion::parse('2.03.4');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage not a valid semantic version
     */
    public function testLeadingZeroNotAllowedInPatch()
    {
        SemanticVersion::parse('2.3.04');
    }

    public function testZeroByItselfIsAllowed()
    {
        $sv = SemanticVersion::parse('0.3.4');
        $this->assertEquals(0, $sv->major);

        $sv = SemanticVersion::parse('2.0.4');
        $this->assertEquals(0, $sv->minor);

        $sv = SemanticVersion::parse('2.3.0');
        $this->assertEquals(0, $sv->patch);
    }

    public function testCanParseVersionWithMajorOnlyInLooseMode()
    {
        $sv = SemanticVersion::parse('2', true);
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(0, $sv->minor);
        $this->assertEquals(0, $sv->patch);
        $this->assertEquals('', $sv->prerelease);
        $this->assertEquals('', $sv->build);
    }

    public function testCanParseVersionWithMajorAndMinorOnlyInLooseMode()
    {
        $sv = SemanticVersion::parse('2.3', true);
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(3, $sv->minor);
        $this->assertEquals(0, $sv->patch);
        $this->assertEquals('', $sv->prerelease);
        $this->assertEquals('', $sv->build);
    }

    public function testCanParseVersionWithMajorAndPrereleaseOnlyInLooseMode()
    {
        $sv = SemanticVersion::parse('2-beta1', true);
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(0, $sv->minor);
        $this->assertEquals(0, $sv->patch);
        $this->assertEquals('beta1', $sv->prerelease);
        $this->assertEquals('', $sv->build);
    }

    public function testCanParseVersionWithMajorMinorAndPrereleaseOnlyInLooseMode()
    {
        $sv = SemanticVersion::parse('2.3-beta1', true);
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(3, $sv->minor);
        $this->assertEquals(0, $sv->patch);
        $this->assertEquals('beta1', $sv->prerelease);
        $this->assertEquals('', $sv->build);
    }

    public function testCanParseVersionWithMajorAndBuildOnlyInLooseMode()
    {
        $sv = SemanticVersion::parse('2+build1', true);
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(0, $sv->minor);
        $this->assertEquals(0, $sv->patch);
        $this->assertEquals('', $sv->prerelease);
        $this->assertEquals('build1', $sv->build);
    }

    public function testCanParseVersionWithMajorMinorAndBuildOnlyInLooseMode()
    {
        $sv = SemanticVersion::parse('2.3+build1', true);
        $this->assertEquals(2, $sv->major);
        $this->assertEquals(3, $sv->minor);
        $this->assertEquals(0, $sv->patch);
        $this->assertEquals('', $sv->prerelease);
        $this->assertEquals('build1', $sv->build);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage not a valid semantic version
     */
    public function testCannotParseVersionWithMajorOnlyByDefault()
    {
        SemanticVersion::parse('2');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage not a valid semantic version
     */
    public function testCannotParseVersionWithMajorAndMinorOnlyByDefault()
    {
        SemanticVersion::parse('2.3');
    }

    public function testEqualVersionsHaveEqualPrecedence()
    {
        $sv1 = SemanticVersion::parse('2.3.4-beta1');
        $sv2 = SemanticVersion::parse('2.3.4-beta1');
        $this->assertEquals(0, $sv1->comparePrecedence($sv2));
        $this->assertEquals(0, $sv2->comparePrecedence($sv1));
    }

    public function testLowerMajorVersionHasLowerPrecedence()
    {
        $sv1 = SemanticVersion::parse('1.3.4-beta1');
        $sv2 = SemanticVersion::parse('2.3.4-beta1');
        $this->assertEquals(-1, $sv1->comparePrecedence($sv2));
        $this->assertEquals(1, $sv2->comparePrecedence($sv1));
    }

    public function testLowerMinorVersionHasLowerPrecedence()
    {
        $sv1 = SemanticVersion::parse('2.2.4-beta1');
        $sv2 = SemanticVersion::parse('2.3.4-beta1');
        $this->assertEquals(-1, $sv1->comparePrecedence($sv2));
        $this->assertEquals(1, $sv2->comparePrecedence($sv1));
    }

    public function testLowerPatchVersionHasLowerPrecedence()
    {
        $sv1 = SemanticVersion::parse('2.3.3-beta1');
        $sv2 = SemanticVersion::parse('2.3.4-beta1');
        $this->assertEquals(-1, $sv1->comparePrecedence($sv2));
        $this->assertEquals(1, $sv2->comparePrecedence($sv1));
    }

    public function testPrereleaseVersionHasLowerPrecedenceThanRelease()
    {
        $sv1 = SemanticVersion::parse('2.3.4-beta1');
        $sv2 = SemanticVersion::parse('2.3.4');
        $this->assertEquals(-1, $sv1->comparePrecedence($sv2));
        $this->assertEquals(1, $sv2->comparePrecedence($sv1));
    }

    public function testShorterSubsetOfPrereleaseIdentifiersHasLowerPrecedence()
    {
        $sv1 = SemanticVersion::parse('2.3.4-beta1');
        $sv2 = SemanticVersion::parse('2.3.4-beta1.rc1');
        $this->assertEquals(-1, $sv1->comparePrecedence($sv2));
        $this->assertEquals(1, $sv2->comparePrecedence($sv1));
    }

    public function testNumericPrereleaseIdentifiersAreSortedNumerically()
    {
        $sv1 = SemanticVersion::parse('2.3.4-beta1.3');
        $sv2 = SemanticVersion::parse('2.3.4-beta1.23');
        $this->assertEquals(-1, $sv1->comparePrecedence($sv2));
        $this->assertEquals(1, $sv2->comparePrecedence($sv1));
    }

    public function testNonNumericPrereleaseIdentifiersAreSortedAsStrings()
    {
        $sv1 = SemanticVersion::parse('2.3.4-beta1.x3');
        $sv2 = SemanticVersion::parse('2.3.4-beta1.x23');
        $this->assertEquals(1, $sv1->comparePrecedence($sv2));
        $this->assertEquals(-1, $sv2->comparePrecedence($sv1));
    }

    public function testBuildIdentifierDoesNotAffectPrecedence()
    {
        $sv1 = SemanticVersion::parse('2.3.4-beta1+build1');
        $sv2 = SemanticVersion::parse('2.3.4-beta1+build2');
        $this->assertEquals(0, $sv1->comparePrecedence($sv2));
        $this->assertEquals(0, $sv2->comparePrecedence($sv1));
    }
}
