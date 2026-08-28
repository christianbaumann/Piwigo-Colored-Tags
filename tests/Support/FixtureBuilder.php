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

    public function anyImageId(): int
    {
        return (int)$this->db->scalar('SELECT id FROM piwigo_images ORDER BY id LIMIT 1');
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
    }
}
