// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/**
 * Force a known database state and return the state that was achieved.
 *
 * seed.php asserts its own preconditions took effect and fails loudly if they
 * did not, so a spec never runs over a state it merely hoped for. The returned
 * object is what a spec should assert against — not a shape guessed from the
 * scenario name.
 *
 * @param {'some-assigned'|'all-assigned'|'all-but-one-assigned'|'no-tags'|'only-non-colored'} scenario
 * @param {number} imageId
 */
function seed(scenario, imageId = 1) {
  const stdout = execFileSync('php', [SEED_SCRIPT, `--scenario=${scenario}`, `--image=${imageId}`], {
    encoding: 'utf8',
  });
  return JSON.parse(stdout);
}

/** Put back whatever the seeds in this test recorded. Safe to call unseeded. */
function restore() {
  execFileSync('php', [SEED_SCRIPT, '--restore'], { encoding: 'utf8' });
}

module.exports = { seed, restore };
