<?php
use PHPUnit\Framework\TestCase;

/**
 * typetags_nfc(): a typed tag name in Unicode normal form C, so a name typed
 * decomposed (a + U+0308) finds the tag stored composed. Requirement: change
 * 2026-10-15-exploratory-testing-answers, "Typed tag names are NFC".
 */
final class TypedTagNfcTest extends TestCase
{
    /** [HAPPY] A decomposed umlaut is composed. */
    public function testADecomposedNameIsComposed(): void
    {
        $this->assertSame("H\u{00E4}user", typetags_nfc("Ha\u{0308}user"));
    }

    /** [ECP] A name already composed is returned as it is. */
    public function testAComposedNameIsUnchanged(): void
    {
        $this->assertSame("H\u{00E4}user", typetags_nfc("H\u{00E4}user"));
    }

    /** [NEG] Bytes that are not UTF-8 come back unchanged, for the name check to refuse. */
    public function testInvalidUtf8IsReturnedUnchanged(): void
    {
        $this->assertSame("Kirmes \xC3(", typetags_nfc("Kirmes \xC3("));
    }
}
