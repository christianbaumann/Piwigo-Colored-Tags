<?php
use PHPUnit\Framework\TestCase;

final class CheckColorTest extends TestCase
{
    // --- Happy path ---

    public function testSixDigitHexIsAccepted(): void
    {
        $this->assertSame('#aabbcc', check_color('aabbcc'));
    }

    public function testThreeDigitHexIsExpanded(): void
    {
        $this->assertSame('#aabbcc', check_color('abc'));
    }

    public function testLeadingHashIsStripped(): void
    {
        $this->assertSame('#aabbcc', check_color('#abc'));
    }

    // --- Boundary values (length after ltrim('#')) ---

    public function testLengthZeroRejected(): void
    {
        $this->assertFalse(check_color(''));
    }

    public function testLengthTwoRejected(): void
    {
        $this->assertFalse(check_color('ab'));
    }

    public function testLengthThreeAccepted(): void
    {
        $this->assertSame('#aabbcc', check_color('abc'));
    }

    public function testLengthFourRejected(): void
    {
        $this->assertFalse(check_color('abcd'));
    }

    public function testLengthFiveRejected(): void
    {
        $this->assertFalse(check_color('abcde'));
    }

    public function testLengthSixAccepted(): void
    {
        $this->assertSame('#aabbcc', check_color('aabbcc'));
    }

    public function testLengthSevenRejected(): void
    {
        $this->assertFalse(check_color('abcdefg'));
    }

    // --- Negative and edge ---

    public function testNonHexCharactersRejected(): void
    {
        $this->assertFalse(check_color('gggggg'));
        $this->assertFalse(check_color('ab c'));
    }

    public function testMultipleLeadingHashesAreAllStripped(): void
    {
        // Characterization of ltrim(): it strips every leading '#', not just one.
        $this->assertSame('#aabbcc', check_color('###abc'));
    }

    public function testCaseIsPreserved(): void
    {
        $this->assertSame('#ABCDEF', check_color('ABCDEF'));
    }

    public function testWhitespaceIsNotTrimmed(): void
    {
        $this->assertFalse(check_color(' abc'));
    }

    // --- Cross-function property ---

    public function testCheckColorOutputNeverMakesGetColorTextThrow(): void
    {
        $accepted = array('abc', 'aabbcc', '#abc', '#aabbcc', 'ABCDEF', '###abc');

        foreach ($accepted as $input)
        {
            $color = check_color($input);
            $this->assertNotFalse($color, "expected $input to be accepted");
            $this->assertContains(get_color_text($color), array('#000', '#fff'), "input: $input");
        }
    }
}
