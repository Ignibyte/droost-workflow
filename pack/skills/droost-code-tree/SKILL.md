---
name: droost-code-tree
description: See the structure of this project's code with `droost code:tree`: its modules and themes, their files, classes, functions and methods, and each module's hooks, services and routes. Use before planning or changing code. Plan does not close until this has been asked.
---

# `droost code:tree`

The code's structure, read from droost's code index (built from files by
`droost code:index`; no site needed).

```sh
droost code:tree                          # modules and themes, custom first
droost code:tree camps                    # one module: files, symbols, hooks, services, routes
droost code:tree web/modules/custom/camps/src/Form   # the files under a path
droost code:tree camps --limit=1000       # more symbols than the default 400
```

- A module's outline lists each file with its classes, functions and
  methods in order (`class Drupal\camps\Form\CampForm:12`, then its
  methods), the hooks it implements, and the services and routes it
  declares.
- "No code index yet" means `droost code:index` has not run; run it (the
  first build of a site takes a minute, later ones only re-read what
  changed).
- For one symbol's callers or dependencies use `droost code:graph`; to find
  a symbol by name, `droost code:symbol`.
- In the plan phase, `plan_grounded` holds the phase until the run's ledger
  shows a `code:tree` call and a `code:wiki` call.
