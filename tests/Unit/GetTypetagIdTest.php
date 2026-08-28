<?php
use PHPUnit\Framework\TestCase;

// The '|' branch of get_typetag_id() touches the database (TYPETAGS_TABLE,
// pwg_query) and is covered at the integration layer. Only the pure
// regex-marker path is exercised here.
final class GetTypetagIdTest extends TestCase
{
    public function testMarkerFormReturnsId(): void
    {
        $this->assertSame('123', get_typetag_id('~~123~~'));
    }

    public function testZeroId(): void
    {
        $this->assertSame('0', get_typetag_id('~~0~~'));
    }

    public function testEmptyMarkerRejected(): void
    {
        $this->assertFalse(get_typetag_id('~~~~'));
    }

    public function testNonNumericMarkerRejected(): void
    {
        $this->assertFalse(get_typetag_id('~~12a~~'));
    }

    public function testWhitespaceInMarkerRejected(): void
    {
        $this->assertFalse(get_typetag_id('~~ 12 ~~'));
    }

    public function testAnchoringIsEnforced(): void
    {
        $this->assertFalse(get_typetag_id('~~123~~x'));
    }

    public function testPlainStringReturnsFalse(): void
    {
        $this->assertFalse(get_typetag_id('plain'));
        $this->assertFalse(get_typetag_id(''));
    }
}
