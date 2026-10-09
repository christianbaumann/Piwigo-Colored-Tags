<?php
use PHPUnit\Framework\TestCase;

final class EmojiRenderTest extends TestCase
{
    // [HAPPY]
    public function testHtmlForm(): void
    {
        $this->assertSame('&#x270D;', typetags_emoji_html('270D'));
        $this->assertSame('&#x1F5BC;&#xFE0F;', typetags_emoji_html('1F5BC FE0F'));
    }

    // [HAPPY] each escape ends at the next backslash, so no separator is needed between them
    public function testCssForm(): void
    {
        $this->assertSame('\\270D', typetags_emoji_css('270D'));
        $this->assertSame('\\1F5BC\\FE0F', typetags_emoji_css('1F5BC FE0F'));
    }

    // [BVA]
    public function testNoEmojiRendersNothing(): void
    {
        $this->assertSame('', typetags_emoji_html(''));
        $this->assertSame('', typetags_emoji_css(''));
        $this->assertSame('Kirmes', typetags_badge_label('Kirmes', ''));
    }

    // [HAPPY] the emoji is its own element, so the picture-page script can read the name without it
    public function testTheLabelPutsTheEmojiInFrontOfTheName(): void
    {
        $this->assertSame(
            '<span class="typetag-emoji">&#x270D;&#xFE0F;</span> Kirmes 1958',
            typetags_badge_label('Kirmes 1958', '270D FE0F')
        );
    }
}
