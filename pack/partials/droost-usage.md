# Using droost from the workflow

droost is what the agent knows about this codebase: its code, its structure,
the modules and themes it has installed, and its wiki. This workflow is how
it works. Every phase leans on it, and every phase has to cope with part of
it being unavailable.

## How to call it

droost is one command, `droost`, installed in the project's own
`tools/droost/` vendor directory. It boots no Drupal and never calls drush,
so it answers while the site is broken, which is when you most need it.

```bash
droost                                   # every command, one line each
droost code:wiki help                    # one command's arguments (`<command> help`)
droost code:wiki camps                   # run one: arguments as words, --name=value, or one JSON object
droost code:tree camps
droost search:arch "camp registration"
```

On DDEV, run it in the container (`ddev droost …` when the project has that
command, else `ddev exec tools/droost/vendor/bin/droost …`). Every answer is
JSON, and a command exits 1 when it says no. Every knowledge call is
recorded in the run's tool-call ledger by droost itself, and plan reads that
ledger: a call you only describe is not a call.

## The three knowledge commands plan requires

- **`droost code:wiki [page]`**: the project's wiki (`droost/wiki`) and a
  generated page for every installed contrib module and theme: its version
  from `composer.lock`, what it needs and provides from its own YAML, and
  whether it is enabled according to the last configuration export (the page
  says how old that export is).
- **`droost code:tree [module|path]`**: the code's structure from the code
  index: modules and themes, their files, classes, functions and methods,
  and each module's hooks, services and routes.
- **`droost search:arch <words>`**: plain text search over the documentation:
  the wiki, the inventory, each module's and theme's README and docs.

Plan is held until the ledger shows a `code:wiki` and a `code:tree` call
since the run began. `brief` alone is not looking.

## The rest

- `droost code:symbol <name>`: where a class, interface, function or method
  is defined.
- `droost code:graph <symbol>`: what calls it, what it injects, what it
  implements; `service:<id>`, `route:<name>` and `hook:<name>` work too.
- `droost search:code <query>`: symbol names and wiki text, lexically, and the
  nearest code chunks when `droost.yml` names an embeddings endpoint. The
  answer's `mode` says which ran.
- `droost code:docs`: module documentation across core, contrib and custom.
- `droost code:deprecations`: Drupal core deprecations, offline.
- `droost brief`: the project in one answer, from its files.

## What needs what

- **The code index** (`droost code:index`, about a minute the first time and
  seconds after) feeds `code:tree`, `code:symbol`, `code:graph`, and
  `search:code`. Without it they say so rather than answering empty.
  `search:arch` falls back to reading the files.
- **The wiki** exists only if someone wrote it. droost writes no page prose;
  `droost code:wiki status` says which pages are stale against their sources.
- **The running site is not droost's.** Content, the database, active
  configuration and rendered pages are read with the project's own tools
  (drush, the browser). droost answers about the code and the files.

So on a checkout with no index and no wiki, droost can still read the
inventory and the documentation, and little else. Plan with what you could
check, and say in every artefact which of your foundations you could not.
This is worth stating plainly because the opposite assumption is the easy
one to make, and it produces exactly the failure this workflow exists to
prevent: an agent that believes it verified something it never could.

## When a command is unavailable

Say so, in the artefact you are producing, where the person reading it will
see it.

The failure mode this workflow exists to prevent is not "a command was
unavailable", which is normal. It is a run that could not check something,
did not say so, and produced a report indistinguishable from one where
everything was verified. An unchecked thing recorded as unchecked is fine. An
unchecked thing recorded as passed is a lie the next person acts on.
