<?php
/**
 * Forces a known database state and asserts it took effect, so a test never
 * runs over a state it merely hoped for.
 *
 * Cleanup restores what was recorded, but no assertion depends on cleanup
 * having run: cleanup is skipped when a test fails, and a suite that needs
 * it to pass would destroy its own failure evidence.
 */
class FixtureBuilder
{
    private Db $db;

    /** image_id => list of tag_ids assigned before this fixture touched it */
    private array $originalAssignments = array();

    /** user_cache rows recorded as (user_id => nb_available_tags) */
    private ?array $originalTagCounts = null;

    /** tag ids created by this builder, to be deleted on restore */
    private array $createdTagIds = array();

    /** colour group ids created by this builder, deleted on restore after their tags */
    private array $createdGroupIds = array();

    /** the throwaway photo testImageId() made: id, file, album_id; null until asked for */
    private ?array $testImage = null;

    /** Where testImageId() puts its copy, under the Piwigo root. */
    private const TEST_IMAGE_DIR = 'upload/typetags-test/';

    public const STRIPED_GROUP_COLOR = '#d00000';
    public const STRIPED_GROUP_EMOJI = '270D FE0F';

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    // ── State recording ───────────────────────────────────────────────────

    private function recordImage(int $imageId): void
    {
        if (isset($this->originalAssignments[$imageId]))
        {
            return;
        }

        $result = $this->db->query("SELECT tag_id FROM piwigo_image_tag WHERE image_id = $imageId");
        $tagIds = array();
        while ($row = $result->fetch_row())
        {
            $tagIds[] = (int)$row[0];
        }
        $this->originalAssignments[$imageId] = $tagIds;
    }

    public function recordTagCounts(): void
    {
        if ($this->originalTagCounts !== null)
        {
            return;
        }

        $result = $this->db->query('SELECT user_id, nb_available_tags FROM piwigo_user_cache');
        $counts = array();
        while ($row = $result->fetch_assoc())
        {
            $counts[(int)$row['user_id']] = $row['nb_available_tags'];
        }
        $this->originalTagCounts = $counts;
    }

    // ── Lookups ───────────────────────────────────────────────────────────

    public function coloredTagIds(): array
    {
        $result = $this->db->query('SELECT id FROM piwigo_tags WHERE id_typetags IS NOT NULL ORDER BY name');
        $ids = array();
        while ($row = $result->fetch_row())
        {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }

    /**
     * tag id => configured colour, straight from the table the plugin reads.
     *
     * Lets a test assert the colour the browser actually paints without a second,
     * hand-typed copy of the palette going stale next to the real one.
     */
    public function coloredTagColors(): array
    {
        $result = $this->db->query('
SELECT t.id, tt.color
  FROM piwigo_tags AS t
  INNER JOIN piwigo_typetags AS tt ON t.id_typetags = tt.id
');
        $colors = array();
        while ($row = $result->fetch_assoc())
        {
            $colors[(int)$row['id']] = $row['color'];
        }
        return $colors;
    }

    /** ids of the tags whose colour group is striped */
    public function stripedTagIds(): array
    {
        $result = $this->db->query('
SELECT t.id
  FROM piwigo_tags AS t
  INNER JOIN piwigo_typetags AS tt ON t.id_typetags = tt.id
  WHERE tt.striped = 1
');
        $ids = array();
        while ($row = $result->fetch_row())
        {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }

    /**
     * A striped colour group with an emoji, and one tag in it. The installed
     * palette may hold no striped group at all, so the specs for the striped
     * look bring their own.
     *
     * @return array{tag_id: int, group_id: int}
     */
    public function createStripedGroup(): array
    {
        $this->db->query("INSERT INTO piwigo_typetags (name, color, striped, emoji)
            VALUES ('_fixture_striped_group', '" . self::STRIPED_GROUP_COLOR . "', 1, '" . self::STRIPED_GROUP_EMOJI . "')");
        $groupId = $this->db->insertId();
        $this->createdGroupIds[] = $groupId;

        $this->db->query("INSERT INTO piwigo_tags (name, url_name, id_typetags)
            VALUES ('_fixture_striped_tag', '_fixture_striped_tag', $groupId)");
        $tagId = $this->db->insertId();
        $this->createdTagIds[] = $tagId;

        if (!in_array($tagId, $this->stripedTagIds()))
        {
            throw new RuntimeException("Fixture tag $tagId did not come out striped");
        }
        return array('tag_id' => $tagId, 'group_id' => $groupId);
    }

    /**
     * A photo of this suite's own, made once per builder: a copy of the first
     * gallery PNG in a public album of its own. Never a real photo: with
     * plugins/photoinfo active, every tag change writes the photo's file.
     * What the copy brings along of persons and keywords is removed, so its
     * tags are only the ones a scenario gives it.
     */
    public function testImageId(): int
    {
        if ($this->testImage !== null)
        {
            return $this->testImage['id'];
        }

        $source = (string)$this->db->scalar(
            "SELECT path FROM piwigo_images WHERE path LIKE '%.png' AND width IS NOT NULL ORDER BY id LIMIT 1"
        );
        $sourceFile = PIWIGO_ROOT . ltrim($source, './');
        $dir = PIWIGO_ROOT . self::TEST_IMAGE_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
        {
            throw new RuntimeException("cannot create $dir");
        }
        $name = 'typetags-test-' . bin2hex(random_bytes(8)) . '.png';
        if (!is_file($sourceFile) or !copy($sourceFile, $dir . $name))
        {
            throw new RuntimeException("cannot copy $sourceFile to $dir$name");
        }
        self::stripCopiedMetadata($dir . $name);

        $dimensions = getimagesize($dir . $name);
        $dbPath = './' . self::TEST_IMAGE_DIR . $name;
        $this->db->query(
            'INSERT INTO piwigo_images (file, path, date_available, filesize, width, height) VALUES (' .
            "'$name', '$dbPath', NOW(), " . (int)ceil(filesize($dir . $name) / 1024) . ', ' .
            (int)$dimensions[0] . ', ' . (int)$dimensions[1] . ')'
        );
        $imageId = $this->db->insertId();

        $this->db->query(
            "INSERT INTO piwigo_categories (name, id_uppercat, uppercats, rank, global_rank, status, visible) " .
            "VALUES ('typetags-test-$name', NULL, '', 1, '1', 'public', 'true')"
        );
        $albumId = $this->db->insertId();
        $this->db->query("UPDATE piwigo_categories SET uppercats = '$albumId', global_rank = '$albumId' WHERE id = $albumId");
        $this->db->query("INSERT INTO piwigo_image_category (image_id, category_id) VALUES ($imageId, $albumId)");

        $this->testImage = array('id' => $imageId, 'file' => $dir . $name, 'album_id' => $albumId);
        if ($imageId <= 0 or $albumId <= 0 or $this->categoryIdFor($imageId) !== $albumId)
        {
            throw new RuntimeException('the test photo and its album were not created');
        }

        return $imageId;
    }

    /**
     * Removes what a copied gallery photo brings along of persons and of
     * photoinfo's tag writes, and asserts none is left. The pwginfo group is
     * deleted as a whole, which needs no -config.
     */
    private static function stripCopiedMetadata(string $file): void
    {
        $fields = array('XMP-mwg-rs:RegionInfo', 'XMP-iptcExt:PersonInImage', 'XMP-dc:Subject', 'IPTC:Keywords',
            'XMP-lr:HierarchicalSubject', 'XMP-pwginfo:all');
        $deletes = array_map(fn ($field) => escapeshellarg('-' . $field . '='), $fields);
        $reads = array_map(fn ($field) => escapeshellarg('-' . $field), $fields);

        $output = array();
        $status = 1;
        exec('exiftool -q -overwrite_original ' . implode(' ', $deletes) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        if ($status !== 0)
        {
            throw new RuntimeException("cannot strip the copied metadata of $file: " . implode("\n", $output));
        }

        $left = array();
        exec('exiftool -s3 ' . implode(' ', $reads) . ' ' . escapeshellarg($file) . ' 2>&1', $left);
        if (trim(implode("\n", $left)) !== '')
        {
            throw new RuntimeException("regions or keywords left in $file: " . implode("\n", $left));
        }
    }

    /**
     * An id above every real image. Stays inside image_id's mediumint unsigned
     * range (max 16777215, install/piwigo_structure-mysql.sql:206) — a larger
     * value is silently clipped to the column max by INSERT IGNORE rather than
     * rejected, which would silently retarget any test using it.
     */
    public function nonexistentImageId(): int
    {
        return (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images') + 1000;
    }

    /** A category the image is in, so picture.php can be reached for it. */
    public function categoryIdFor(int $imageId): int
    {
        $id = $this->db->scalar("SELECT category_id FROM piwigo_image_category WHERE image_id = $imageId ORDER BY category_id LIMIT 1");
        if ($id === null)
        {
            throw new RuntimeException("Image $imageId is in no category, so it has no picture.php URL");
        }
        return (int)$id;
    }

    /**
     * The tag of that name, if the server created one while a test ran, so
     * restore() removes it.
     */
    public function trackTagNamed(string $name): ?int
    {
        $id = $this->db->scalar("SELECT id FROM piwigo_tags WHERE name = '" . $this->db->escape($name) . "'");
        if ($id === null)
        {
            return null;
        }
        $this->createdTagIds[] = (int)$id;
        return (int)$id;
    }

    public function ensurePlainTagId(): int
    {
        $existing = $this->db->scalar('SELECT id FROM piwigo_tags WHERE id_typetags IS NULL LIMIT 1');
        if ($existing !== null)
        {
            return (int)$existing;
        }

        $this->db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('_fixture_plain_tag', '_fixture_plain_tag')");
        $id = $this->db->insertId();
        $this->createdTagIds[] = $id;
        return $id;
    }

    // ── Scenarios ─────────────────────────────────────────────────────────

    /** State A: at least one colored tag assigned and at least one unassigned. */
    public function someAssignedSomeUnassigned(int $imageId): array
    {
        $colored = $this->coloredTagIds();
        if (count($colored) < 2)
        {
            throw new RuntimeException('someAssignedSomeUnassigned needs at least 2 colored tags, found ' . count($colored));
        }

        $this->recordImage($imageId);
        $this->clearTags($imageId);

        $assigned = array($colored[0]);
        $unassigned = array_slice($colored, 1);
        $this->assign($imageId, $assigned);

        $this->assertState($imageId, $assigned);

        return array('assigned' => $assigned, 'unassigned' => $unassigned);
    }

    /** State B: every colored tag assigned, so the unassigned list is empty. */
    public function allColoredAssigned(int $imageId): array
    {
        $colored = $this->coloredTagIds();
        if (count($colored) === 0)
        {
            throw new RuntimeException('allColoredAssigned needs at least 1 colored tag');
        }

        $this->recordImage($imageId);
        $this->clearTags($imageId);
        $this->assign($imageId, $colored);
        $this->assertState($imageId, $colored);

        return array('assigned' => $colored, 'unassigned' => array());
    }

    /**
     * Exactly one colored tag left unassigned.
     *
     * Differs from someAssignedSomeUnassigned in the case it covers, not just
     * in its numbers: it is the only fixture from which assigning one more tag
     * empties the unassigned list, which is the transition box 540 describes.
     */
    public function allButOneColoredAssigned(int $imageId): array
    {
        $colored = $this->coloredTagIds();
        if (count($colored) < 2)
        {
            throw new RuntimeException('allButOneColoredAssigned needs at least 2 colored tags, found ' . count($colored));
        }

        $unassigned = array(array_pop($colored));
        $assigned = $colored;

        $this->recordImage($imageId);
        $this->clearTags($imageId);
        $this->assign($imageId, $assigned);
        $this->assertState($imageId, $assigned);

        return array('assigned' => $assigned, 'unassigned' => $unassigned);
    }

    /** State C: no tags at all, so #Tags is absent from the rendered page. */
    public function imageWithNoTags(int $imageId): array
    {
        $this->recordImage($imageId);
        $this->clearTags($imageId);
        $this->assertState($imageId, array());

        return array('assigned' => array(), 'unassigned' => $this->coloredTagIds());
    }

    /** State D: only non-colored tags, so no badge carries a remove button. */
    public function onlyNonColoredTags(int $imageId): array
    {
        $plainTagId = $this->ensurePlainTagId();

        $this->recordImage($imageId);
        $this->clearTags($imageId);
        $this->assign($imageId, array($plainTagId));
        $this->assertState($imageId, array($plainTagId));

        return array('assigned' => array($plainTagId), 'unassigned' => $this->coloredTagIds());
    }

    /** State E: one colored and one plain tag assigned together, so both share the Tags row. */
    public function oneColoredAndPlainAssigned(int $imageId): array
    {
        $colored = $this->coloredTagIds();
        if (count($colored) === 0)
        {
            throw new RuntimeException('oneColoredAndPlainAssigned needs at least 1 colored tag');
        }
        $plainTagId = $this->ensurePlainTagId();

        $this->recordImage($imageId);
        $this->clearTags($imageId);
        $assigned = array($colored[0], $plainTagId);
        $this->assign($imageId, $assigned);
        $this->assertState($imageId, $assigned);

        return array('assigned' => $assigned, 'unassigned' => array_slice($colored, 1));
    }

    // ── Primitives ────────────────────────────────────────────────────────

    private function clearTags(int $imageId): void
    {
        $this->db->query("DELETE FROM piwigo_image_tag WHERE image_id = $imageId");
    }

    private function assign(int $imageId, array $tagIds): void
    {
        foreach ($tagIds as $tagId)
        {
            $tagId = (int)$tagId;
            $this->db->query("INSERT IGNORE INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageId, $tagId)");
        }
    }

    /** Asserts the forced state actually took effect. */
    private function assertState(int $imageId, array $expectedTagIds): void
    {
        $result = $this->db->query("SELECT tag_id FROM piwigo_image_tag WHERE image_id = $imageId");
        $actual = array();
        while ($row = $result->fetch_row())
        {
            $actual[] = (int)$row[0];
        }

        sort($actual);
        $expected = array_map('intval', $expectedTagIds);
        sort($expected);

        if ($actual !== $expected)
        {
            throw new RuntimeException(
                "Fixture did not take effect on image $imageId. Expected tags [" .
                implode(',', $expected) . '] but found [' . implode(',', $actual) . ']'
            );
        }
    }

    public function assignedTagIds(int $imageId): array
    {
        $result = $this->db->query("SELECT tag_id FROM piwigo_image_tag WHERE image_id = $imageId ORDER BY tag_id");
        $ids = array();
        while ($row = $result->fetch_row())
        {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }

    // ── Cross-process state handoff ───────────────────────────────────────
    //
    // The E2E suite seeds from one short-lived CLI process and restores from a
    // later one, so the recorded original state has to outlive the process that
    // captured it. Exporting and re-importing keeps restore() as the single
    // definition of how state is put back — the alternative was a second,
    // independently written restore path in the seeding CLI.

    public function exportState(): array
    {
        return array(
            'assignments' => $this->originalAssignments,
            'tag_counts' => $this->originalTagCounts,
            'created_tag_ids' => $this->createdTagIds,
            'created_group_ids' => $this->createdGroupIds,
            'test_image' => $this->testImage,
            );
    }

    public function importState(array $state): void
    {
        // A JSON round trip turns integer array keys into strings; cast back so
        // restore() writes the same values it would have written in-process.
        $assignments = array();
        foreach ($state['assignments'] ?? array() as $imageId => $tagIds)
        {
            $assignments[(int)$imageId] = array_map('intval', $tagIds);
        }
        $this->originalAssignments = $assignments;

        $this->originalTagCounts = null;
        if (isset($state['tag_counts']) and is_array($state['tag_counts']))
        {
            $counts = array();
            foreach ($state['tag_counts'] as $userId => $count)
            {
                $counts[(int)$userId] = $count === null ? null : (int)$count;
            }
            $this->originalTagCounts = $counts;
        }

        $this->createdTagIds = array_map('intval', $state['created_tag_ids'] ?? array());
        $this->createdGroupIds = array_map('intval', $state['created_group_ids'] ?? array());
        $this->testImage = isset($state['test_image']) ? $state['test_image'] : null;
    }

    // ── Restore ───────────────────────────────────────────────────────────

    public function restore(): void
    {
        foreach ($this->originalAssignments as $imageId => $tagIds)
        {
            $this->clearTags($imageId);
            $this->assign($imageId, $tagIds);
        }
        $this->originalAssignments = array();

        if ($this->originalTagCounts !== null)
        {
            foreach ($this->originalTagCounts as $userId => $count)
            {
                $value = $count === null ? 'NULL' : (int)$count;
                $this->db->query("UPDATE piwigo_user_cache SET nb_available_tags = $value WHERE user_id = $userId");
            }
            $this->originalTagCounts = null;
        }

        foreach ($this->createdTagIds as $tagId)
        {
            $this->db->query("DELETE FROM piwigo_image_tag WHERE tag_id = $tagId");
            $this->db->query("DELETE FROM piwigo_tags WHERE id = $tagId");
        }
        $this->createdTagIds = array();

        foreach ($this->createdGroupIds as $groupId)
        {
            $this->db->query("DELETE FROM piwigo_typetags WHERE id = $groupId");
        }
        $this->createdGroupIds = array();

        if ($this->testImage !== null)
        {
            $imageId = (int)$this->testImage['id'];
            $albumId = (int)$this->testImage['album_id'];
            $this->db->query("DELETE FROM piwigo_image_tag WHERE image_id = $imageId");
            $this->db->query("DELETE FROM piwigo_image_category WHERE image_id = $imageId OR category_id = $albumId");
            $this->db->query("DELETE FROM piwigo_images WHERE id = $imageId");
            $this->db->query("DELETE FROM piwigo_categories WHERE id = $albumId");
            foreach (glob($this->testImage['file'] . '*') as $leftover)
            {
                @unlink($leftover);
            }
            $derivatives = PIWIGO_ROOT . '_data/i/' . self::TEST_IMAGE_DIR . pathinfo($this->testImage['file'], PATHINFO_FILENAME);
            foreach (glob($derivatives . '-*') as $derivative)
            {
                @unlink($derivative);
            }
            $this->testImage = null;
        }
    }
}
