<?php
/**
 * Scenario seeding CLI for the E2E suite.
 *
 * Setup-before, not cleanup-after: every scenario forces its preconditions,
 * FixtureBuilder asserts they took effect, and this script prints the state it
 * achieved so a spec can assert the fixture is what it claims rather than
 * trusting it.
 *
 * The PHPUnit fixtures live and die inside one process. Playwright seeds from a
 * separate short-lived process, so the original state is exported to a snapshot
 * file and re-imported by --restore, which then delegates to the same
 * FixtureBuilder::restore() the integration suite uses.
 *
 * Usage:
 *   php tests/e2e/support/seed.php --scenario=all-assigned --image=1
 *   php tests/e2e/support/seed.php --restore
 *
 * Both forms print one JSON object on stdout. Errors go to stderr with exit 1.
 *
 * This mutates the database. It is not safe against a production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const SNAPSHOT_FILE = __DIR__ . '/../.state/snapshot.json';

/** scenario name on the command line => FixtureBuilder method */
const SCENARIOS = array(
    'some-assigned' => 'someAssignedSomeUnassigned',
    'all-assigned' => 'allColoredAssigned',
    'all-but-one-assigned' => 'allButOneColoredAssigned',
    'no-tags' => 'imageWithNoTags',
    'only-non-colored' => 'onlyNonColoredTags',
    );

function fail(string $message): never
{
    fwrite(STDERR, "seed.php: $message\n");
    exit(1);
}

function parse_args(array $argv): array
{
    $args = array();
    foreach (array_slice($argv, 1) as $arg)
    {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m))
        {
            $args[$m[1]] = $m[2] ?? true;
        }
        else
        {
            fail("unrecognised argument '$arg'");
        }
    }
    return $args;
}

function load_snapshot(): ?array
{
    if (!file_exists(SNAPSHOT_FILE))
    {
        return null;
    }

    $decoded = json_decode(file_get_contents(SNAPSHOT_FILE), true);
    if (!is_array($decoded))
    {
        fail('snapshot file is not valid JSON: ' . SNAPSHOT_FILE);
    }
    return $decoded;
}

function save_snapshot(array $state): void
{
    $dir = dirname(SNAPSHOT_FILE);
    if (!is_dir($dir) and !mkdir($dir, 0777, true) and !is_dir($dir))
    {
        fail("could not create snapshot directory $dir");
    }
    file_put_contents(SNAPSHOT_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

$args = parse_args($argv);
$db = new Db();
$builder = new FixtureBuilder($db);

// ── Restore ───────────────────────────────────────────────────────────────

if (isset($args['restore']))
{
    $snapshot = load_snapshot();
    if ($snapshot === null)
    {
        // Nothing was seeded, so there is nothing to put back. Not an error:
        // afterEach runs even when beforeEach failed before it seeded.
        echo json_encode(array('restored' => false, 'reason' => 'no snapshot')), "\n";
        exit(0);
    }

    $builder->importState($snapshot);
    $builder->restore();
    unlink(SNAPSHOT_FILE);

    echo json_encode(array('restored' => true, 'images' => array_keys($snapshot['assignments'] ?? array()))), "\n";
    exit(0);
}

// ── Seed ──────────────────────────────────────────────────────────────────

$scenario = $args['scenario'] ?? null;
if (!is_string($scenario) or !isset(SCENARIOS[$scenario]))
{
    fail('--scenario must be one of: ' . implode(', ', array_keys(SCENARIOS)));
}

$imageId = isset($args['image']) ? (int)$args['image'] : $builder->anyImageId();
if ($imageId <= 0)
{
    fail('--image must be a positive image id');
}

// Carry forward anything an earlier seed in this test already recorded, so a
// second seed does not overwrite the first one's memory of the original state.
$existing = load_snapshot();
if ($existing !== null)
{
    $builder->importState($existing);
}

// The web-service calls the specs make null nb_available_tags for every user.
$builder->recordTagCounts();

$method = SCENARIOS[$scenario];
$result = $builder->$method($imageId);

save_snapshot($builder->exportState());

$colored = $builder->coloredTagIds();
$assignedColored = array_values(array_intersect($colored, $builder->assignedTagIds($imageId)));

// Both notations of each configured colour, so a spec can compare against what
// the browser reports (getComputedStyle normalises hex to rgb()) without
// re-typing the palette or converting it itself.
$colors = array();
foreach ($builder->coloredTagColors() as $tagId => $hex)
{
    $rgb = sscanf($hex, '#%2x%2x%2x');
    $colors[$tagId] = array(
        'hex' => $hex,
        'rgb' => sprintf('rgb(%d, %d, %d)', $rgb[0], $rgb[1], $rgb[2]),
        );
}

echo json_encode(array(
    'colors' => $colors,
    'scenario' => $scenario,
    'image_id' => $imageId,
    'category_id' => $builder->categoryIdFor($imageId),
    'assigned' => array_values($result['assigned']),
    'unassigned' => array_values($result['unassigned']),
    'assigned_colored_count' => count($assignedColored),
    'unassigned_colored_count' => count($colored) - count($assignedColored),
    'colored_total' => count($colored),
    )), "\n";
