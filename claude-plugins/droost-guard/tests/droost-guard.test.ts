import { expect, test } from 'claude-code/testing'
import { refusal } from '../hooks/register.js'

test('the agent cannot install or load a mod', async ($, on) => {
  on('tool.call', () => ({ result: 'ran' }))
  for (const command of [
    'claude plugin install x@y --scope project',
    'claude plugin marketplace add ./mods',
    'claude --plugin-dir ./mod -p hi',
    'CLAUDE_CODE_PLUGIN_DIRS=/tmp/m claude',
  ]) {
    const out = await $.tool.call({ tool: 'Bash', command })
    expect(out.deny).toMatch(/OPERATOR/)
  }
  const write = await $.tool.call({ tool: 'Write', file_path: '/home/a/.claude/dev-mods/s/hooks/register.js', content: 'x' })
  expect(write.deny).toMatch(/loads plugins from/)
})

test('reading what is installed, and ordinary work, pass', async ($, on) => {
  on('tool.call', () => ({ result: 'ran' }))
  for (const command of ['claude plugin list', 'claude plugin validate ./mod', 'ls', 'git commit -m "claude plugin install is the operator s"']) {
    expect(await $.tool.call({ tool: 'Bash', command })).toEqual({ result: 'ran' })
  }
})

test('refusal() names the route', () => {
  expect(refusal({ tool: 'Bash', command: 'claude plugin enable a@b' })).toMatch(/installs or enables/)
  expect(refusal({ tool: 'Read', file_path: '/x/.claude/plugins/a' })).toBe(null)
})
