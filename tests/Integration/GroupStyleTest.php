<?php
use PHPUnit\Framework\TestCase;

/**
 * Where a striped group with an emoji is drawn: the picture page (assigned
 * and "+" badges), the admin photo chips, the admin tags page swatches and
 * the plugin page list. Page source only; what the browser paints is E2E.
 */
final class GroupStyleTest extends TestCase
{
    private const FIXTURE_PREFIX = '_test_group_style_';
    private const COLOR = '#d00000';
    private const EMOJI = '270D FE0F';
    private const EMOJI_HTML = '&#x270D;&#xFE0F;';
    private const STRIPES = 'repeating-linear-gradient(45deg,#d00000 0 6px,#fff 6px 12px)';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private int $groupId;
    private int $tagId;
    private string $tagName;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->imageId = $this->fixtures->anyImageId();

        $this->db->query("INSERT INTO piwigo_typetags (name, color, striped, emoji)
            VALUES ('" . self::FIXTURE_PREFIX . "group', '" . self::COLOR . "', 1, '" . self::EMOJI . "')");
        $this->groupId = $this->db->insertId();

        $this->tagName = self::FIXTURE_PREFIX . 'tag';
        $this->db->query("INSERT INTO piwigo_tags (name, url_name, id_typetags)
            VALUES ('{$this->tagName}', '{$this->tagName}', {$this->groupId})");
        $this->tagId = $this->db->insertId();

        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM piwigo_image_tag WHERE tag_id = {$this->tagId}");
        $this->db->query("DELETE FROM piwigo_tags WHERE id = {$this->tagId}");
        $this->db->query("DELETE FROM piwigo_typetags WHERE id = {$this->groupId}");
        $this->ws->logout();
    }

    private function page(string $path): string
    {
        $res = $this->ws->fetchPage($path);
        $this->assertSame(200, $res['http_code']);
        $this->assertStringNotContainsString('Fatal error', $res['body']);
        return $res['body'];
    }

    private function picturePage(): string
    {
        $categoryId = $this->fixtures->categoryIdFor($this->imageId);
        $html = $this->page("/picture.php?/{$this->imageId}/category/{$categoryId}");
        // The injected script builds badges as string literals; only server markup counts here.
        return preg_replace('#<script\b.*?</script>#is', '', $html);
    }

    /** [HAPPY] */
    public function testAStripedGroupRendersTheTabOnThePicturePage(): void
    {
        $this->db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ({$this->imageId}, {$this->tagId})");

        $this->assertSame(1, preg_match('#<div id="Tags".*?</div>#s', $this->picturePage(), $tags));
        $this->assertMatchesRegularExpression(
            '~data-tag-id="' . $this->tagId . '"><span style="[^"]*' . preg_quote(self::STRIPES, '~') . '[^"]*border:1px solid ' . self::COLOR . ';~',
            $tags[0]
        );
    }

    /** [HAPPY] */
    public function testAnEmojiRendersBeforeTheName(): void
    {
        $this->db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ({$this->imageId}, {$this->tagId})");

        $this->assertStringContainsString(
            '<span class="typetag-emoji">' . self::EMOJI_HTML . '</span> ' . $this->tagName . '</span>',
            $this->picturePage()
        );
    }

    /** [HAPPY] the "+" badge carries what the script needs to rebuild it after a click */
    public function testAnUnassignedBadgeCarriesItsStyleAndEmoji(): void
    {
        $this->assertSame(1, preg_match('#<div id="typetags-unassigned".*?</div>#s', $this->picturePage(), $box));
        $this->assertSame(1, preg_match('~<span class="typetag-badge typetag-add" data-tag-id="' . $this->tagId . '"[^>]*>[^<]*<span class="typetag-emoji">' . self::EMOJI_HTML . '</span> ' . $this->tagName . '</span>~', $box[0], $badge));
        $this->assertMatchesRegularExpression('~data-tag-style="[^"]*' . preg_quote(self::STRIPES, '~') . '~', $badge[0]);
        $this->assertStringContainsString('data-tag-emoji="' . self::EMOJI_HTML . '"', $badge[0]);
    }

    /** [HAPPY] */
    public function testTheAdminPhotoChipGetsTheTabAndTheEmoji(): void
    {
        $html = $this->page('/admin.php?page=photo-' . $this->imageId);
        $item = '.selectize-input .item[data-value="~~' . $this->tagId . '~~"]';

        $this->assertStringContainsString($item . ',.selectize-input .item.active[data-value="~~' . $this->tagId . '~~"]{background:' . self::STRIPES, $html);
        $this->assertStringContainsString($item . '::before{content:"\\270D\\FE0F";', $html);
    }

    /** [HAPPY] */
    public function testTheAdminTagsPageSwatchIsStriped(): void
    {
        $html = $this->page('/admin.php?page=tags');

        $this->assertMatchesRegularExpression(
            '~<div class="color-option" data-id=' . $this->groupId . ' .*?<span class="color-sample" style="background:' . preg_quote(self::STRIPES, '~') . '">~s',
            $html
        );
    }

    /** [HAPPY] */
    public function testThePluginPageListsTheGroupWithItsTabAndEmoji(): void
    {
        $html = $this->page('/admin.php?page=plugin-typetags');

        $this->assertMatchesRegularExpression(
            '~<li style="background:' . preg_quote(self::STRIPES, '~') . '[^"]*">.*?' . self::EMOJI_HTML . '</span> ' . self::FIXTURE_PREFIX . 'group~s',
            $html
        );
    }
}
