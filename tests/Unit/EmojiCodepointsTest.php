<?php
use PHPUnit\Framework\TestCase;

/**
 * A group's emoji is stored as code points ("1F5BC FE0F"), because the
 * table is utf8mb3 and cannot hold a four-byte character.
 */
final class EmojiCodepointsTest extends TestCase
{
    // [ECP] pasted character
    public function testAPastedEmojiBecomesItsCodePoints(): void
    {
        $this->assertSame('1F5BC FE0F', typetags_emoji_codepoints("\u{1F5BC}\u{FE0F}"));
        $this->assertSame('270D FE0F', typetags_emoji_codepoints("\u{270D}\u{FE0F}"));
    }

    // [ECP] typed hex, any case, surrounding blanks
    public function testTypedCodePointsAreNormalised(): void
    {
        $this->assertSame('1F5BC FE0F', typetags_emoji_codepoints(' 1f5bc   fe0f '));
    }

    // [ECP] the U+ notation
    public function testTheUPlusNotationIsAccepted(): void
    {
        $this->assertSame('1F5BC FE0F', typetags_emoji_codepoints('U+1F5BC u+FE0F'));
    }

    // [BVA] empty means "no emoji"
    public function testEmptyMeansNone(): void
    {
        $this->assertSame('', typetags_emoji_codepoints(''));
        $this->assertSame('', typetags_emoji_codepoints('   '));
    }

    // [BVA] the last Unicode code point and the first one past it
    public function testTheUnicodeRangeIsTheBound(): void
    {
        $this->assertSame('10FFFF', typetags_emoji_codepoints('10FFFF'));
        $this->assertFalse(typetags_emoji_codepoints('110000'));
    }

    // [BVA] the number of code points
    public function testEightCodePointsAreTheMaximum(): void
    {
        $eight = implode(' ', array_fill(0, TYPETAGS_EMOJI_MAX_CODEPOINTS, '1F600'));
        $this->assertSame($eight, typetags_emoji_codepoints($eight));
        $this->assertFalse(typetags_emoji_codepoints($eight . ' 1F600'));
    }

    // [BVA] the surrogate range D800-DFFF is refused at both edges, its neighbours are not
    public function testTheSurrogateRangeIsRefusedAtBothEdges(): void
    {
        $this->assertSame('D7FF', typetags_emoji_codepoints('D7FF'));
        $this->assertFalse(typetags_emoji_codepoints('D800'));
        $this->assertFalse(typetags_emoji_codepoints('DFFF'));
        $this->assertSame('E000', typetags_emoji_codepoints('E000'));
    }

    // [ECP] pasted characters separated by blanks, as the docblock allows
    public function testPastedCharactersSeparatedByBlanksAreNormalised(): void
    {
        $this->assertSame('1F5BC FE0F', typetags_emoji_codepoints("\u{1F5BC} \u{FE0F}"));
    }

    // [NEG]
    public function testGarbageIsRefused(): void
    {
        foreach (array('hello', '1F5BC;', '#1F5BC', 'U+', '0', 'D800') as $input)
        {
            $this->assertFalse(typetags_emoji_codepoints($input), $input);
        }
        $this->assertFalse(typetags_emoji_codepoints("\xF0\x9F"), 'invalid UTF-8');
    }

    // [NEG] pasted and typed mixed, or text pasted with the emoji: letters would be stored as code points
    public function testAsciiTextIsNoPartOfAnEmoji(): void
    {
        $this->assertFalse(typetags_emoji_codepoints("\u{1F5BC} FE0F"));
        $this->assertFalse(typetags_emoji_codepoints("Bild \u{270D}"));
        foreach (array('20', 'A', '41', '7F') as $typed)
        {
            $this->assertFalse(typetags_emoji_codepoints($typed), $typed);
        }
    }

    // [BVA] a keycap emoji starts with an ASCII digit, # or *, the only ASCII it may hold
    public function testAKeycapKeepsItsAsciiBase(): void
    {
        $this->assertSame('31 FE0F 20E3', typetags_emoji_codepoints("1\u{FE0F}\u{20E3}"));
        $this->assertSame('23 FE0F 20E3', typetags_emoji_codepoints('23 FE0F 20E3'));
        $this->assertSame('80', typetags_emoji_codepoints('80'));
    }

    // [ERR] a ZWJ sequence stays one emoji; this records the behaviour, no requirement asks for it
    public function testAZwjSequenceIsKeptIntact(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $this->assertSame('1F468 200D 1F469 200D 1F467', typetags_emoji_codepoints($family));
    }
}
