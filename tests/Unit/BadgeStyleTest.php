<?php
use PHPUnit\Framework\TestCase;

/**
 * The inline style of one badge. Non-striped groups must keep exactly the
 * string the plugin rendered before groups could be striped, so nothing
 * already on screen moves.
 */
final class BadgeStyleTest extends TestCase
{
    private const TODAYS_STYLE_FOR_YELLOW =
        'background-color:#FFFFB6;color:#000;padding:2px 8px;border-radius:12px;display:inline-block;';

    // [HAPPY]
    public function testANonStripedGroupKeepsTodaysStyle(): void
    {
        $this->assertSame(self::TODAYS_STYLE_FOR_YELLOW, typetags_badge_style('#FFFFB6', false));
    }

    // [HAPPY]
    public function testAStripedGroupGetsTheTabAndBorderInItsColour(): void
    {
        $style = typetags_badge_style('#d00000', true);

        $this->assertStringContainsString(
            'repeating-linear-gradient(45deg,#d00000 0 6px,' . TYPETAGS_STRIPE_COLOR . ' 6px 12px) left/18px 100% no-repeat,' . TYPETAGS_STRIPE_COLOR . ';',
            $style
        );
        $this->assertStringContainsString('border:1px solid #d00000;', $style);
        $this->assertStringContainsString('padding:2px 8px 2px 24px;', $style);
        $this->assertStringNotContainsString('background-color:', $style);
    }

    // [ECP] the tab sits on white, so the text is black whatever the group colour
    public function testStripedTextIsAlwaysBlack(): void
    {
        foreach (array('#000000', '#FFFFB6') as $color)
        {
            $this->assertStringContainsString(';color:' . TYPETAGS_STRIPED_TEXT . ';', typetags_badge_style($color, true));
            $this->assertSame(TYPETAGS_STRIPED_TEXT, typetags_text_color($color, true));
        }
        $this->assertSame('#fff', typetags_text_color('#000000', false));
    }

    // [HAPPY] small round samples on the admin tags page get the stripes over their whole area
    public function testASwatchIsTheColourOrTheStripes(): void
    {
        $this->assertSame('#77A600', typetags_swatch('#77A600', false));
        $this->assertSame(
            'repeating-linear-gradient(45deg,#d00000 0 6px,' . TYPETAGS_STRIPE_COLOR . ' 6px 12px)',
            typetags_swatch('#d00000', true)
        );
    }
}
