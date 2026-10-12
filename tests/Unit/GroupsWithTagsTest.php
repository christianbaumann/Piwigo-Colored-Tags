<?php
use PHPUnit\Framework\TestCase;

/**
 * typetags_groups_with_tags() hands each colour group its tags for the
 * read-only group page. Requirement: task 10 of the role-permissions change,
 * "render groups with colour, stripes, emoji and their tags".
 */
final class GroupsWithTagsTest extends TestCase
{
    private const GROUPS = array(
        array('id' => '1', 'name' => 'Orte'),
        array('id' => '2', 'name' => 'Personen'),
        );

    /** [HAPPY] Each tag lands in its own group, in the order it came in. */
    public function testEachTagLandsInItsGroupInOrder(): void
    {
        $tags = array(
            array('id' => 10, 'name' => 'Anna', 'id_typetags' => '2'),
            array('id' => 11, 'name' => 'Berg', 'id_typetags' => '1'),
            array('id' => 12, 'name' => 'Carl', 'id_typetags' => '2'),
            );

        $groups = typetags_groups_with_tags(self::GROUPS, $tags);

        $this->assertSame(array(11), array_column($groups[0]['tags'], 'id'));
        $this->assertSame(array(10, 12), array_column($groups[1]['tags'], 'id'));
    }

    /** [NEG] A tag in no group, or in a group not listed, is left out. */
    public function testATagOutsideTheListedGroupsIsLeftOut(): void
    {
        $tags = array(
            array('id' => 20, 'name' => 'frei', 'id_typetags' => null),
            array('id' => 21, 'name' => 'leer', 'id_typetags' => ''),
            array('id' => 22, 'name' => 'fremd', 'id_typetags' => '99'),
            );

        $groups = typetags_groups_with_tags(self::GROUPS, $tags);

        $this->assertSame(array(), $groups[0]['tags']);
        $this->assertSame(array(), $groups[1]['tags']);
    }

    /** [ECP] A group without a tag the account sees is still listed, with no tags. */
    public function testEveryGroupIsKeptEvenWithoutTags(): void
    {
        $groups = typetags_groups_with_tags(self::GROUPS, array());

        $this->assertSame(array('Orte', 'Personen'), array_column($groups, 'name'));
        $this->assertSame(array(array(), array()), array_column($groups, 'tags'));
    }
}
