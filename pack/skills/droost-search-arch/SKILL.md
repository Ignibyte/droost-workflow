---
name: droost-search-arch
description: Search this project's documentation as plain text with `droost search:arch` — the wiki, the inventory of installed modules and themes, and every module's and theme's README and docs. Use to find which module already does something, or how one is meant to be used.
---

# `droost search:arch`

A plain text search over what the project says about itself: the wiki's
pages, the inventory's generated page for each installed module and theme,
and each module's and theme's README and `docs/`. Every word must appear.

```sh
droost search:arch "paragraphs translation"
droost search:arch "commerce checkout pane" --limit=10
```

- Each result names a path you can open, its kind (wiki, inventory, readme,
  doc), the module it belongs to, and a snippet with the words marked.
- It reads the code index's documents when `droost code:index` has built
  them, and the files themselves when it has not; the answer says which.
- It is deliberately plain: for the code's structure use `droost code:tree`,
  for a symbol `droost code:symbol`, for symbols and wiki text together
  `droost search:code`.
