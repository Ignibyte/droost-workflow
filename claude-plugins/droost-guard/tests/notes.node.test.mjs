// The mod's guidance, tested under node. Claude Code's own test runner
// (`claude plugin test`) needs mods switched on, which is served from
// Anthropic's side; these functions are plain JavaScript and need no host.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { NOTES, grounded, isSpec, noteFor, refusal, traverses } from '../hooks/register.js'

test('a search of core, contrib or vendor is a traversal', () => {
  for (const e of [
    { tool: 'Grep', pattern: 'hook_tokens', path: 'web/core' },
    { tool: 'Grep', pattern: 'x', path: '/var/www/html/web/modules/contrib/canvas' },
    { tool: 'Glob', pattern: 'web/core/**/*.php' },
    { tool: 'Bash', command: 'grep -rn "function hook_tokens" web/core/modules/system' },
    { tool: 'Bash', command: 'find vendor/drush -name "*.php" | head' },
    { tool: 'Bash', command: 'rg -l LocalActionManager web/core' },
    { tool: 'Bash', command: 'cd /var/www/html && grep -r Trash web/modules/contrib/trash/src' },
    { tool: 'Bash', command: 'cd web/core/modules/system && grep -rn hook_tokens .' },
  ]) assert.equal(traverses(e), true, JSON.stringify(e))
})

test('the project\'s own code, and reading one file, are not', () => {
  for (const e of [
    { tool: 'Grep', pattern: 'core', path: 'web/modules/custom' },
    { tool: 'Bash', command: 'grep -rn "core" web/modules/custom' },
    { tool: 'Bash', command: 'cat web/core/lib/Drupal.php' },
    { tool: 'Bash', command: 'ddev drush status' },
    { tool: 'Read', file_path: 'web/core/lib/Drupal.php' },
    { tool: 'Glob', pattern: 'web/modules/custom/**/*.yml' },
    // P8 run 1: a listing piped into grep searches no code of vendor's.
    { tool: 'Bash', command: 'cd /x/site; ddev drush cget system.theme; ls vendor/bin | grep -i droost' },
    { tool: 'Bash', command: 'cat vendor/composer/installed.json | grep droost/workflow' },
  ]) assert.equal(traverses(e), false, JSON.stringify(e))
})

test('the spec is recognised by its place', () => {
  assert.equal(isSpec('/var/www/html/droost/droost-workflow/tmp-spec-t1.md'), true)
  assert.equal(isSpec('/var/www/html/droost/droost-workflow/spec-t1.md'), true, 'the full spec at high and above (S5-5)')
  assert.equal(isSpec('droost/droost-workflow/history/run-1.spec.md'), false)
})

test('a knowledge call grounds its run, and one made under no run does not', () => {
  const ledger = [
    { tool: 'workflow:run', outcome: 'ok', run: 'run-a' },
    { tool: 'search:code', outcome: 'fail', run: 'run-a' },
    { tool: 'code:symbol', outcome: 'ok', run: 'run-b' },
    { tool: 'droost_symbol', outcome: 'ok', run: 'run-a' },
  ].map((r) => JSON.stringify(r)).join('\n')
  assert.equal(grounded(ledger, 'run-a'), false, 'a run verb, a failed search and a retired tool id ground nothing')
  assert.equal(grounded(ledger, 'run-b'), true)
  assert.equal(grounded(ledger + '\n' + JSON.stringify({ tool: 'brief', outcome: 'ok', run: null }), 'run-a'), false, 'a lookup before the run opened is no run\'s (F-331)')
  assert.equal(grounded(JSON.stringify({ tool: 'brief', outcome: 'ok', run: null }), null), true, 'with no run open, any lookup')
  assert.equal(grounded('not json\n', 'run-a'), false)
})

test('a search earns a note the first time in a phase and every tenth', () => {
  const seen = {}
  const e = { tool: 'Grep', pattern: 'x', path: 'web/core' }
  const notes = Array.from({ length: 20 }, () => noteFor(e, 'code', seen, true))
  assert.deepEqual(notes.map((n, i) => (n ? i + 1 : 0)).filter(Boolean), [1, 10, 20])
  assert.equal(noteFor(e, 'test', seen, true), NOTES.traverse, 'a new phase starts again')
})

test('the spec earns the ground note until droost was asked', () => {
  const seen = {}
  const e = { tool: 'Write', file_path: 'droost/droost-workflow/tmp-spec-t1.md' }
  assert.equal(noteFor(e, 'plan', seen, false), NOTES.ground)
  assert.equal(noteFor(e, 'plan', seen, false), null)
  assert.equal(noteFor(e, 'plan', {}, true), null)
})

test('a new file of any shape earns no note: how to build is the agent\'s', () => {
  assert.equal(noteFor({ tool: 'Write', file_path: 'web/modules/custom/kc/src/Form/AForm.php' }, 'code', {}, true), null)
})

test('notes never refuse, and refusals are unchanged', () => {
  assert.equal(refusal({ tool: 'Grep', path: 'web/core' }), null)
  assert.match(refusal({ tool: 'Bash', command: 'claude plugin install x@y' }), /installs or enables/)
})
