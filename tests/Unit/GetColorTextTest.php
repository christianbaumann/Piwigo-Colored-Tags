<?php
use PHPUnit\Framework\TestCase;

final class GetColorTextTest extends TestCase
{
    // --- Happy path ---

    public function testSevenCharLightColourGetsBlackText(): void
    {
        $this->assertSame('#000', get_color_text('#FFFFB6'));
    }

    public function testSevenCharDarkColourGetsWhiteText(): void
    {
        $this->assertSame('#fff', get_color_text('#007DAD'));
    }

    public function testFourCharShorthandIsSupported(): void
    {
        $this->assertSame('#000', get_color_text('#fff'));
        $this->assertSame('#fff', get_color_text('#000'));
    }

    public function testAllConfiguredPaletteColoursResolve(): void
    {
        // The 8 live colours configured for the plugin's default palette.
        $palette = array('#FFFFB6', '#007DAD', '#E53935', '#43A047', '#8E24AA', '#FB8C00', '#3949AB', '#00897B');

        foreach ($palette as $color)
        {
            $result = get_color_text($color);
            $this->assertContains($result, array('#000', '#fff'), "palette colour $color");
        }
    }

    // --- Boundary values ($l > 0.45) ---

    public function testThresholdJustBelowGetsWhiteText(): void
    {
        // #00E500: l = 0.449020
        $this->assertSame('#fff', get_color_text('#00E500'));
    }

    public function testThresholdJustAboveGetsBlackText(): void
    {
        // #00E600: l = 0.450980
        $this->assertSame('#000', get_color_text('#00E600'));
    }

    public function testThresholdIsUnreachableOnEightBitChannels(): void
    {
        // l = (min+max)/2 where min and max are each k/255 for integer k in
        // 0..255 (the 7-char path). l == 0.45 requires min_k+max_k = 229.5,
        // but the sum of two integers is never a non-integer. Documented as
        // a proof over the domain, not a single sampled input.
        for ($min_k = 0; $min_k <= 255; $min_k++)
        {
            for ($max_k = $min_k; $max_k <= 255; $max_k++)
            {
                $this->assertNotEquals(229.5, $min_k + $max_k);
            }
        }
    }

    public function testFourCharThresholdBoundary(): void
    {
        $this->assertSame('#fff', get_color_text('#0d0'));
        $this->assertSame('#000', get_color_text('#0e0'));
    }

    public function testExtremes(): void
    {
        $this->assertSame('#fff', get_color_text('#000000'));
        $this->assertSame('#000', get_color_text('#ffffff'));
    }

    // --- Negative and edge ---

    public function testMalformedLengthReturnsSafeDefault(): void
    {
        // Reproduces the defect fixed in Phase 2 (plan Phase 2, item 1):
        // $rgb is never initialised for a length other than 4 or 7, so
        // min($rgb) throws TypeError.
        //
        // '#000000_overlong' is here because the mutation run of 2026-08-29 found
        // the over-length inputs were not discriminating: `strlen($color) == 7`
        // mutated to `>= 7` survived, since str_repeat('a', 1000) parses as a light
        // colour under the mutant and returns '#000' anyway — the same answer the
        // guard gives. This input reads as '#000000' under the mutant, so it returns
        // '#fff' there and '#000' here, and the mutant dies.
        foreach (array('#12345', '#', 'ab', str_repeat('a', 1000), '#000000_overlong') as $input)
        {
            $this->assertSame('#000', get_color_text($input), "input: $input");
        }
    }

    public function testEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', get_color_text(''));
    }

    public function testZeroStringIsTreatedAsEmpty(): void
    {
        // Accident of empty(): '0' is falsy in PHP, so this short-circuits
        // before the length check ever runs.
        $this->assertSame('', get_color_text('0'));
    }

    public function testNullIsTreatedAsEmpty(): void
    {
        $this->assertSame('', get_color_text(null));
    }

    public function testNonHexOfCorrectLengthDoesNotThrow(): void
    {
        // hexdec() ignores invalid characters rather than rejecting the
        // input (it emits a PHP deprecation notice on 8.1+, but does not
        // throw), so garbage of the right length is accepted, not rejected.
        $this->assertSame('#fff', @get_color_text('notahex'));
        $this->assertSame('#fff', @get_color_text('#GGGGGG'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame(get_color_text('#ffffb6'), get_color_text('#FFFFB6'));
    }

    public function testLeadingHashIsNotValidated(): void
    {
        // Characterization: no requirement backs this, it just records what
        // happens today. '1234567' is 7 chars, so it is processed as if it
        // were a colour, hash or not — no throw, one of the two known outputs.
        $this->assertContains(get_color_text('1234567'), array('#000', '#fff'));
    }
}
