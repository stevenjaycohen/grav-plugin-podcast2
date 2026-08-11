# Podcast2 regression tests

This dependency-free suite exercises the Podcast2 4.0.2 release against an existing Grav 2 installation. It uses Grav's own Composer autoloader, Page implementation, getID3 plugin, Feed plugin, Twig runtime, and PHP development server; it does not install or contact external services.

Run it from any directory with an explicit Grav root:

```bash
PODCAST2_TEST_GRAV_ROOT=/absolute/path/to/grav php /absolute/path/to/podcast2-repo/tests/run.php
```

The command exits `0` only when every assertion passes. It covers:

- complete PHPDoc coverage for the Podcast2 class and all declared members;
- manifest compatibility, dependency metadata, and podcast Feed blueprint defaults;
- local-audio saves through a regular `Grav\Common\Page\Page`;
- direct and nested `podcast-episode` feed selection and series scoping;
- HTTP status, content types, enclosure URLs, XML validity, and ordinary Feed isolation;
- remote URL, IP-address, redirect, protocol, timeout, byte-limit, system-resolver, and DNS-pinning policy;
- deterministic fake-transport streaming with bounded memory and partial-file cleanup;
- one-pass getID3 analysis during enclosure metadata calculation;
- README requirements, template URL escaping, and rendered episode audio URLs;
- preservation of configured local/remote sources after failures;
- nullable handling of incomplete getID3 results; and
- preservation of legacy Feed template and unrelated Feed settings during save migration.

The runner creates uniquely named Page fixtures only after confirming their paths are absent. Cache, logs, generated WAV files, HTTP server output, and temporary downloads live in a unique system temporary directory. The runner stops only the server process it starts, removes its Page and generated-image fixtures, and compares both Git working-tree states before and after the run.

Prerequisites are PHP 8.3 or newer with cURL, DOM, and the extensions required by Grav, plus an installed Grav 2 site containing the Feed and getID3 plugins. Localhost socket access is required for the HTTP integration case.

The positive path for downloading audio from a real public HTTP(S) server is intentionally not tested. The suite uses a fake streaming transport so it cannot contact a live service; local and private network targets are tested only for rejection.
