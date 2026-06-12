# horde/yaml

Pedantic, lossless and pure-PHP YAML 1.2 parser with byte-identical
round-trip.

This library ships with two layers:

- **Legacy `Horde_Yaml`**: array-only loader/dumper kept for backwards
  compatibility with existing Horde components.
- **Modern `Horde\Yaml\Document`**: comment-preserving load/edit/dump
  pipeline with a public AST, anchor and alias support, and atomic
  file writes. Targets configuration files like `.horde.yml` that
  must round-trip through human and machine edits.

The two layers live side by side. New code should use the document
layer.

## Highlights

- **100% YAML 1.2 conformance.** All 391 cases from the upstream
  yaml-test-suite pass: every spec example loads as the spec says it
  should, every malformed input is rejected.
- **Byte-identical round-trip.** Loading a source file and dumping it
  back without mutation produces the exact same bytes. Comments,
  blank lines, end-of-line spacing, quoting style, and flow layout
  all survive.
- **Comments and trivia are first-class AST nodes.** Standalone
  comments live as siblings of map entries and sequence items. EOL
  comments are properties of their entry. Stream- and document-level
  trivia have their own slots. Position-based insert / remove /
  replace is supported on every container.
- **Strict by default, lenient on opt-in.** `LeniencyPolicy::strictYaml12()`
  is the conformance baseline. `LeniencyPolicy::hordeCompat()` keeps
  the historical tolerance for existing `.horde.yml` files (legacy
  booleans, malformed directives, undefined tag handles, etc.).
- **Pure PHP, no extensions.** ext-mbstring is the only requirement.
  No libyaml, no shell-out.

### Conformance table

| Suite | Cases | Pass |
|---|---|---|
| yaml-test-suite (YAML 1.2 reference) | 391 | 391 (100%) |
| Round-trip probe (comments, whitespace, trivia) | 15 | 15 (100%) |
| Unit tests | 1401 | 1401 (100%) |

## Requirements

- PHP 8.2 or later (8.1 for the legacy parser)
- ext-mbstring

## Installation

```bash
composer require horde/yaml
```

## Document layer at a glance

```php
use Horde\Yaml\Document\YamlFileLoader;
use Horde\Yaml\Document\YamlFileDumper;

$loader = new YamlFileLoader();
$dumper = new YamlFileDumper();

$stream = $loader->load('config.yml');
$doc = $stream->document(0);

$doc->setEntry('name', 'kronolith');
$doc->setEntry('version', '6.0.0');

$dumper->dump($stream, 'config.yml');
```

A clean round-trip (load and dump without mutation) reproduces the
source byte-for-byte. After mutation, every untouched part of the
file stays exactly as it was: comments, blank lines, the spacing
before EOL comments, single vs double quotes, and the layout of
multi-line flow collections. See
`doc/examples/byte-identical-roundtrip.php` for a runnable proof.

To insert a comment at a specific position, use the container's
positional API:

```php
$authors->insertCommentBefore(2, '# Joined the project in 2026.');
```

See `doc/examples/splice-comment.php`.

See `doc/USAGE.md` for the full API and `doc/examples/` for runnable
snippets.

## Documentation

- `doc/USAGE.md`: document layer API guide
- `doc/UPGRADING.md`: migration notes from `Horde_Yaml`
- `doc/examples/`: runnable PHP snippets
- `doc/changelog.yml`: release history

## Testing

```bash
phpunit                                 # unit + integration suites
phpunit -c phpunit-perf.xml.dist        # performance gates
```

The performance suite expects a snapshotted `.horde.yml` corpus at
`test/fixtures/perf/horde-yml-corpus/`. Refresh it from a local
component checkout via:

```bash
./scripts/snapshot-horde-yml-corpus.sh
```

Conformance against the upstream yaml-test-suite is opt-in; run
`git submodule update --init` to fetch the cases, then re-run the
default suite. Triage status lives in
`test/fixtures/conformance/yaml-test-suite-status.php`.

## License

LGPL-2.1. See `LICENSE`.
