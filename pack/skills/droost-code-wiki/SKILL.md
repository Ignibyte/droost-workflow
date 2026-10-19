---
name: droost-code-wiki
description: Read this project's wiki and the inventory of every installed module and theme with `droost code:wiki`. Use before planning or changing code, to learn what the project already has and how it documents itself. Plan does not close until this has been asked.
---

# `droost code:wiki`

The codebase wiki (the pages people wrote, in `droost/wiki`) and, generated
beside them from the project's files, an **inventory** of every installed
contrib and custom module and theme: its version (composer.lock), what it
needs and provides (its `*.info.yml`, routes, services and permissions), and
whether it is enabled as of the last configuration export.

```sh
droost code:wiki                      # list the pages and the inventory
droost code:wiki camps                # read a page (".md" is optional)
droost code:wiki inventory            # every installed module and theme
droost code:wiki inventory/paragraphs # one of them
droost code:wiki --query=registration # search the pages and the inventory
```

Read the answer's `message` first; `data` holds the page or the lists.

- "Enabled" is as of the last `config:export`: a module switched on or off
  since is not reflected, and the page says when the export was written.
- Nothing here needs the site: it reads files, so it answers while Drupal is
  broken.
- In the plan phase, `plan_grounded` holds the phase until the run's ledger
  shows a `code:wiki` call and a `code:tree` call.
