// The mod's guidance, tested under node. Claude Code's own test runner
// (`claude plugin test`) needs mods switched on, which is served from
// Anthropic's side; these functions are plain JavaScript and need no host.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { NOTES, consulted, generatorShape, isSpec, noteFor, refusal, traverses } from '../hooks/register.js'

test('a search of core, contrib or vendor is a traversal', () => {
  for (const e of [
    { tool: 'Grep', pattern: 'hook_tokens', path: 'web/core' },
    { tool: 'Grep', pattern: 'x', path: '/var/www/html/web/modules/contrib/canvas' },
    { tool: 'Glob', pattern: 'web/core/**/*.php' },
    { tool: 'Bash', command: 'grep -rn "function hook_tokens" web/core/modules/system' },
    { tool: 'Bash', command: 'find vendor/drush -name "*.php" | head' },
    { tool: 'Bash', command: 'rg -l LocalActionManager web/core' },
    { tool: 'Bash', command: 'cd /var/www/html && grep -r Trash web/modules/contrib/trash/src' },
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
  ]) assert.equal(traverses(e), false, JSON.stringify(e))
})

test('a generator\'s file in custom code has a shape, elsewhere none', () => {
  assert.equal(generatorShape('web/modules/custom/kc/src/Plugin/Block/LeagueBlock.php'), 'a block plugin')
  assert.equal(generatorShape('/var/www/html/web/modules/custom/kc/kc.routing.yml'), 'routes')
  assert.equal(generatorShape('web/themes/custom/kc/components/card/card.component.yml'), 'a single-directory component')
  assert.equal(generatorShape('web/modules/custom/kc/src/CampAudience.php'), null)
  assert.equal(generatorShape('web/modules/contrib/x/src/Plugin/Block/A.php'), null)
  assert.equal(generatorShape('tests/e2e/rinks.spec.ts'), null)
})

test('the spec is recognised by its place', () => {
  assert.equal(isSpec('/var/www/html/droost/droost-workflow/tmp-spec-t1.md'), true)
  assert.equal(isSpec('droost/droost-workflow/history/run-1.spec.md'), false)
})

test('a consult counts for its run, and for no run', () => {
  const ledger = [
    { tool: 'droost_decide', outcome: 'ok', run: 'run-a' },
    { tool: 'droost_consult', outcome: 'fail', run: 'run-a' },
    { tool: 'droost_consult', outcome: 'ok', run: 'run-b' },
  ].map((r) => JSON.stringify(r)).join('\n')
  assert.equal(consulted(ledger, 'run-a'), false)
  assert.equal(consulted(ledger, 'run-b'), true)
  assert.equal(consulted(ledger + '\n' + JSON.stringify({ tool: 'droost_consult', outcome: 'ok', run: null }), 'run-a'), true)
  assert.equal(consulted('not json\n', 'run-a'), false)
})

test('a search earns a note the first time in a phase and every tenth', () => {
  const seen = {}
  const e = { tool: 'Grep', pattern: 'x', path: 'web/core' }
  const notes = Array.from({ length: 20 }, () => noteFor(e, 'code', seen, true))
  assert.deepEqual(notes.map((n, i) => (n ? i + 1 : 0)).filter(Boolean), [1, 10, 20])
  assert.equal(noteFor(e, 'test', seen, true), NOTES.traverse, 'a new phase starts again')
})

test('the spec earns the consult note until it is consulted', () => {
  const seen = {}
  const e = { tool: 'Write', file_path: 'droost/droost-workflow/tmp-spec-t1.md' }
  assert.equal(noteFor(e, 'plan', seen, false), NOTES.consult)
  assert.equal(noteFor(e, 'plan', seen, false), null)
  assert.equal(noteFor(e, 'plan', {}, true), null)
})

test('a hand-written generator file earns one note per kind per phase', () => {
  const seen = {}
  const a = { tool: 'Write', file_path: 'web/modules/custom/kc/src/Form/AForm.php' }
  const b = { tool: 'Write', file_path: 'web/modules/custom/kc/src/Form/BForm.php' }
  assert.match(noteFor(a, 'code', seen, true), /\(a form\)/)
  assert.equal(noteFor(b, 'code', seen, true), null)
  assert.equal(noteFor({ tool: 'Edit', file_path: a.file_path }, 'code', {}, true), null, 'an edit is not a new file')
})

test('notes never refuse, and refusals are unchanged', () => {
  assert.equal(refusal({ tool: 'Grep', path: 'web/core' }), null)
  assert.match(refusal({ tool: 'Bash', command: 'claude plugin install x@y' }), /installs or enables/)
})
