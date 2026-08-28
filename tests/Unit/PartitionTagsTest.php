<?php
use PHPUnit\Framework\TestCase;

final class PartitionTagsTest extends TestCase
{
    private function tag($id, $name, $color)
    {
        return array('id' => $id, 'name' => $name, 'url_name' => $name, 'color' => $color);
    }

    public function testNoColouredTags(): void
    {
        $result = typetags_partition_tags(array(), array());
        $this->assertSame(array(), $result['unassigned']);
        $this->assertSame(array(), $result['assigned_colored_ids']);
    }

    public function testOneColouredNoneAssigned(): void
    {
        $result = typetags_partition_tags(array($this->tag(1, 'a', '#fff')), array());
        $this->assertCount(1, $result['unassigned']);
        $this->assertCount(0, $result['assigned_colored_ids']);
    }

    public function testOneColouredAndAssigned(): void
    {
        $result = typetags_partition_tags(array($this->tag(1, 'a', '#fff')), array(1));
        $this->assertCount(0, $result['unassigned']);
        $this->assertCount(1, $result['assigned_colored_ids']);
    }

    public function testManyColouredNoneAssigned(): void
    {
        // Drives State C: an image with no tags at all.
        $all = array($this->tag(1, 'a', '#fff'), $this->tag(2, 'b', '#000'), $this->tag(3, 'c', '#123456'));
        $result = typetags_partition_tags($all, array());
        $this->assertCount(3, $result['unassigned']);
        $this->assertCount(0, $result['assigned_colored_ids']);
    }

    public function testManyColouredAllAssigned(): void
    {
        // Drives State B and box 516: unassigned list is empty.
        $all = array($this->tag(1, 'a', '#fff'), $this->tag(2, 'b', '#000'));
        $result = typetags_partition_tags($all, array(1, 2));
        $this->assertSame(array(), $result['unassigned']);
        $this->assertCount(2, $result['assigned_colored_ids']);
    }

    public function testManyColouredSomeAssigned(): void
    {
        // Drives State A: the only state exercised by the original suite.
        $all = array($this->tag(1, 'a', '#fff'), $this->tag(2, 'b', '#000'), $this->tag(3, 'c', '#123456'));
        $result = typetags_partition_tags($all, array(2));
        $this->assertCount(2, $result['unassigned']);
        $this->assertCount(1, $result['assigned_colored_ids']);
    }

    public function testAssignedIdsContainingNonColouredTagsAreIgnored(): void
    {
        // Drives State D and box 557: a plain (non-colored) tag id in
        // $assigned_ids must not surface in either output list.
        $all = array($this->tag(1, 'a', '#fff'));
        $result = typetags_partition_tags($all, array(1, 999));
        $this->assertSame(array(1), $result['assigned_colored_ids']);
        $this->assertSame(array(), $result['unassigned']);
    }

    public function testPartitionIsCompleteAndDisjoint(): void
    {
        $all = array($this->tag(1, 'a', '#fff'), $this->tag(2, 'b', '#000'), $this->tag(3, 'c', '#123456'));
        $result = typetags_partition_tags($all, array(2));

        $unassignedIds = array_column($result['unassigned'], 'id');
        $union = array_merge($unassignedIds, $result['assigned_colored_ids']);
        sort($union);

        $this->assertSame(array(1, 2, 3), $union);
        $this->assertEmpty(array_intersect($unassignedIds, $result['assigned_colored_ids']));
    }

    public function testColorTextIsAddedToUnassignedOnly(): void
    {
        $all = array($this->tag(1, 'a', '#fff'), $this->tag(2, 'b', '#000'));
        $result = typetags_partition_tags($all, array(2));

        $this->assertArrayHasKey('color_text', $result['unassigned'][0]);
        // assigned_colored_ids holds bare ids, not tag rows.
        $this->assertSame(2, $result['assigned_colored_ids'][0]);
    }

    public function testStringAndIntegerIdsBothMatch(): void
    {
        // Records the loose in_array() comparison: '5' and 5 both match.
        $all = array($this->tag(5, 'a', '#fff'));
        $result = typetags_partition_tags($all, array('5'));
        $this->assertCount(0, $result['unassigned']);
        $this->assertCount(1, $result['assigned_colored_ids']);
    }

    public function testInputOrderIsPreserved(): void
    {
        // The query orders by name; the partition must not reorder.
        $all = array($this->tag(3, 'c', '#fff'), $this->tag(1, 'a', '#000'), $this->tag(2, 'b', '#123456'));
        $result = typetags_partition_tags($all, array());
        $this->assertSame(array(3, 1, 2), array_column($result['unassigned'], 'id'));
    }
}
