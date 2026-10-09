<?php
use PHPUnit\Framework\TestCase;

/**
 * The CSS that colours one tag's chip in the admin photo properties and
 * Batch Manager tag fields (selectize items).
 */
final class ChipCssTest extends TestCase
{
    private const TODAYS_RULES_FOR_TAG_7 =
        '.selectize-input .item[data-value="~~7~~"],.selectize-input .item.active[data-value="~~7~~"]'
        . '{background-color:#007DAD !important;color:#fff !important;}'
        . '.selectize-input .item[data-value="~~7~~"] .remove,.selectize-input .item.active[data-value="~~7~~"] .remove'
        . '{color:#fff !important;}';

    // [HAPPY]
    public function testAPlainGroupKeepsTodaysRules(): void
    {
        $this->assertSame(self::TODAYS_RULES_FOR_TAG_7, typetags_chip_css(7, '#007DAD', false, ''));
    }

    // [HAPPY]
    public function testAStripedGroupGetsTheTabBorderAndBlackText(): void
    {
        $css = typetags_chip_css(7, '#d00000', true, '');

        $this->assertStringContainsString(
            'background:repeating-linear-gradient(45deg,#d00000 0 6px,' . TYPETAGS_STRIPE_COLOR . ' 6px 12px) left/18px 100% no-repeat,' . TYPETAGS_STRIPE_COLOR . ' !important;',
            $css
        );
        $this->assertStringContainsString('border:1px solid #d00000 !important;', $css);
        $this->assertStringContainsString('padding-left:24px !important;', $css);
        $this->assertSame(2, substr_count($css, 'color:' . TYPETAGS_STRIPED_TEXT . ' !important;'));
        $this->assertStringNotContainsString('background-color:', $css);
    }

    // [HAPPY]
    public function testAnEmojiIsPutBeforeTheName(): void
    {
        $css = typetags_chip_css(7, '#e4e6e3', false, '1F5BC FE0F');

        $this->assertStringContainsString(
            '.selectize-input .item[data-value="~~7~~"]::before{content:"\\1F5BC\\FE0F";',
            $css
        );
        $this->assertStringStartsWith(self::TODAYS_RULES_FOR_TAG_7, typetags_chip_css(7, '#007DAD', false, '1F5BC'));
    }

    // [NEG]
    public function testNoEmojiAddsNoBeforeRule(): void
    {
        $this->assertStringNotContainsString('::before', typetags_chip_css(7, '#d00000', true, ''));
    }
}
