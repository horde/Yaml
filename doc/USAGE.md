# Document layer usage

The document layer parses YAML into a public AST that preserves
comments, blank lines, anchors, aliases, and per-node style. It is
designed for files that must round-trip through both human edits and
programmatic ones (`.horde.yml`, configuration manifests, fixtures).

The legacy `Horde_Yaml::load()` / `Horde_Yaml::dump()` API still
exists as a separate, standalone parser and dumper. Use it when you
only need an array. It does not forward to the document layer; the
two implementations live side by side without sharing code. See
`doc/examples/legacy-load.php` and `doc/examples/legacy-dump.php`
for runnable demos against the legacy API.

## Loading

Three loaders accept three input shapes:

```php
use Horde\Yaml\Document\YamlFileLoader;
use Horde\Yaml\Document\YamlResourceLoader;
use Horde\Yaml\Document\YamlStringLoader;

$stream = (new YamlFileLoader())->load('config.yml');
$stream = (new YamlStringLoader())->load($yamlSource);
$stream = (new YamlResourceLoader())->load($fp);   // any seekable stream
```

Each returns a `YamlStream` containing one or more `YamlDocument`
instances separated by `---` markers, plus the trivia (comments,
blank lines) between and around them.

## Reading entries

`YamlDocument` exposes scalar entries via `getEntry()`:

```php
$name    = $doc->getEntry('name');         // mixed scalar
$version = $doc->getEntry('version', '0.0.0');  // with default
```

`getEntry()` returns a normalized PHP scalar
(`string|int|float|bool|null`). For nested or complex values, walk
the AST:

```php
$root = $doc->root();              // MapNode | SequenceNode | ScalarNode | AliasNode
$entry = $root->entry('authors');  // returns the MapEntry
$value = $entry?->value();         // the Node value
```

Map and sequence nodes implement `ArrayAccess` for read-only lookup:

```php
$first = $doc->root()['name'];
```

Writes through `ArrayAccess` throw `UnsupportedOperationException`.
Use the explicit setters instead.

## Writing entries

```php
$doc->setEntry('name', 'kronolith');
$doc->setEntry('version', '6.0.0');
```

`setEntry()` accepts `string|int|float|bool|null|Stringable` and
preserves the existing key's position, surrounding trivia, and (when
possible) its scalar style. New keys are appended.

For structural edits (adding sequences, building nested maps), use
the node API directly. See `doc/examples/edit-nested.php`.

## Dumping

```php
use Horde\Yaml\Document\YamlFileDumper;
use Horde\Yaml\Document\YamlResourceDumper;
use Horde\Yaml\Document\YamlStringDumper;

(new YamlFileDumper())->dump($stream, 'config.yml');     // atomic temp+rename
$yaml = (new YamlStringDumper())->dump($stream);
(new YamlResourceDumper())->dump($stream, $fp);
```

`YamlFileDumper` writes to a temp file in the same directory and
atomically renames it. Partial writes never leave a half-written
config behind.

## Anchors and aliases

Anchors (`&name`) and aliases (`*name`) are first-class. Aliases are
leaf nodes; resolve them via `target()`:

```php
$alias = $node;                         // AliasNode
$resolved = $alias->target();           // the anchored Node
```

Aliases are scoped to their document. The document's `AnchorIndex`
is rebuilt on every load.

## Merge keys

The `<<` key inside a mapping is a YAML merge key. Its value (an
alias to another mapping, or a sequence of such aliases) is folded
into the resolved view of the surrounding mapping.

```yaml
defaults: &defaults
  driver: mysql
  port: 3306

production:
  <<: *defaults
  database: prod
```

```php
$root = $stream->getDocument(0)->root();
$prod = $root->entry('production')->getValue();
$prod->resolved();
// => ['driver' => 'mysql', 'port' => 3306, 'database' => 'prod']
```

Rules:

- The `<<` entry is preserved in the AST (`entries()` and
  `mergeEntry()` both return it).
- `resolved()` returns the merged view; the literal `<<` key does
  not appear there.
- An explicit key in the merging map overrides any merged-in key
  with the same name.
- For a sequence of aliases, earlier aliases win over later ones.
- A merge whose source is not a mapping (sequence, scalar) is a
  silent no-op. Only explicit keys appear in the resolved view.
- A merge cycle (`a: <<: *b`, `b: <<: *a` or self-merge) throws
  `StructuralException`.

Merge keys do not survive `setEntry()` on the merging map: writes
go to the merging map's children, shadowing the merge. Editing the
anchor target's map is the way to change merged-in values.

## Multi-line plain scalars

A plain (unquoted) scalar can wrap across lines as long as each
continuation line is indented strictly more than the parent block
context. Adjacent continuation lines join with a single space; a
blank line between content lines folds to one `\n` in the value.

```yaml
description: This is a long description
  that wraps across two lines
  without any quoting.
```

`getValue()` returns `"This is a long description that wraps across two lines without any quoting."`. `getRawSource()` returns the verbatim source bytes for round-trip.

Rules:

- Continuation indent must exceed the parent block indent.
- Reserved indicators at line start (`-`, `?`, `[`, `]`, `{`, `}`,
  `,`, `&`, `*`, `!`, `|`, `>`, quotes, `%`, `@`, `` ` ``, `#`)
  terminate the scalar.
- A `: ` (colon followed by space) inside a continuation line is
  illegal. It would otherwise be ambiguous with a new mapping
  entry. `bar:baz` (adjacent colon) is fine.
- A `#` preceded by space inside a continuation is illegal. It
  would otherwise look like a comment.
- Document markers `---` and `...` at column 1 always terminate.

`setValue()` clears the multi-line layout: subsequent emission
puts the value on a single line. To keep a custom multi-line
layout, set `rawSource` directly via `setRawSource()`.

## Tag resolution

The document layer resolves the YAML 1.2 core schema tags on
scalar values:

| Tag | Effect |
|---|---|
| `!!str`  | force string interpretation |
| `!!int`  | parse as integer (decimal, `0o…`, `0x…`); throws on non-numeric |
| `!!float`| parse as float; accepts `.inf`/`.nan`/`-.inf`; throws on non-numeric |
| `!!bool` | accept `true`/`false` (any case); throws on other input |
| `!!null` | accept empty or `null` spelling; throws on other input |

```yaml
explicit:
  - !!str 42        # the string "42"
  - !!int "42"      # the integer 42
  - !!bool true     # the boolean true
  - !!float 5       # the float 5.0
  - !!null null     # the null value
```

Custom tags (`!Foo`, `!<urn:x:bar>`) are tokenised and preserved
on the AST (`ScalarNode::getTag()`) but their values are returned
as strings. Stage 12 will land a tag handler registry for
domain-specific resolution.

`%TAG` directives are tokenised at the stream level but not yet
honoured during resolution (Stage 12). Cases that rely on them
will be processed against the unmapped shorthand and may error
under strict tag coercion.

## Legacy boolean spellings

By default the document layer is strict YAML 1.2: only `true` /
`false` (any case) parse as booleans. `yes`, `no`, `on`, `off`,
`y`, `n` and their case variants stay strings.

Pre-1.2 `.horde.yml` files commonly use `yes` / `no` for booleans.
Pass `legacyBooleans: true` to the loader to recognise them:

```php
$stream = (new YamlFileLoader(legacyBooleans: true))->load('config.yml');
```

Coverage under the flag: `y`, `Y`, `yes`, `Yes`, `YES`, `on`, `On`,
`ON` → `true`; `n`, `N`, `no`, `No`, `NO`, `off`, `Off`, `OFF` →
`false`. Quoted scalars (`"yes"`, `'no'`) stay strings under the
flag. Quoting is the explicit signal to keep the lexical form.

The flag does not change scanning or emission. Source bytes are
preserved on the AST; round-trip emits the original lexical form
regardless of the flag's setting. Only `getValue()` differs.
Setting a value through `setValue(true)` always emits `true` (1.2
spelling). The flag affects loading, not authoring.

## Block scalars

Block scalars (`|` literal, `>` folded) preserve newlines as-is or
fold them, optionally with a chomping indicator and an explicit
indent.

Header syntax:

```
|         # literal, clip (default chomp): one trailing newline
|-        # literal, strip: no trailing newlines
|+        # literal, keep: preserve all trailing blank lines
>         # folded, clip
>-        # folded, strip
>+        # folded, keep
|2        # literal, clip, explicit indent indicator (parent + 2)
|-2       # chomp before indent works
|2-       # indent before chomp works
| # comment
          # an end-of-line comment after the indicator is fine
```

Indent indicators are digits 1–9. Zero is illegal and throws
`ParseException`. Without an explicit indicator the consumer
auto-detects the content indent from the first non-empty line.

Folding rules (`>`):

- A single line break between content lines folds to a space.
- A blank line between content lines folds to `\n`.
- More-indented lines (relative to the detected indent) keep their
  layout. This is useful for embedded code in folded scalars.

Round-trip preserves block-scalar source bytes verbatim. Editing a
scalar via `setValue()` clears the layout; the emitter then
synthesises a default literal/folded form.

## Trivia: comments and blank lines

Comments and blank lines are first-class addressable AST nodes. They
live as siblings of map entries and sequence items in the parent
container's children list, not as metadata attached to a nearby node.
End-of-line comments are the one exception: they are stored as a
property of the entry or item they sit on, since they cannot
grammatically separate from that line.

The library captures four positional kinds of trivia:

| Position | Where it lives |
|---|---|
| Standalone, inside a map or sequence | `MapNode` / `SequenceNode` `children()` list, between entries |
| End-of-line, on an entry or item | `MapEntry::getEolComment()`, `SequenceItem::getEolComment()` |
| Stream leading (before any directive or content) | `YamlStream::getLeadingTrivia()` |
| Stream trailing (after the last document) | `YamlStream::getTrailingTrivia()` |
| Document trailing (between this doc and the next `---`) | `YamlDocument::getTrailingTrivia()` |

Inserting and removing comments uses positional methods on the
parent container. References can be an integer index, a sibling
node, a `MapEntry`/`SequenceItem`, or another `CommentNode`.

```php
use Horde\Yaml\Document\Node\CommentNode;

// Append a comment at the end of a sequence.
$authors->appendComment('# end of list');

// Splice a comment between item 1 and item 2.
$authors->insertCommentBefore(2, '# Joined the project in 2026.');

// Insert before / after a specific item or comment.
$item = $authors->item(0);
$authors->insertCommentAfter($item, '# Note about the first author.');

// Remove a previously inserted comment.
$authors->removeComment($comment);
```

The `MapNode` API is symmetric: `appendComment`,
`insertCommentBefore`, `insertCommentAfter`, `removeComment`. Both
containers also have `appendBlankLines(int $count = 1)` for spacing.

End-of-line comments preserve the gap (spaces or tabs) between the
preceding value and the `#`. Synthesized comments without an explicit
gap default to two spaces on emit. To force a different gap on a
hand-built `CommentNode`, call `setGap()`:

```php
$eol = new CommentNode('# inline');
$eol->setGap(' ');
$entry->setEolComment($eol);
```

See `doc/examples/splice-comment.php` for a runnable example.

## Round-trip

The document layer's contract is byte-identical round-trip: load any
valid YAML 1.2 source, dump it back without mutating the AST, and
the output equals the input byte-for-byte. After mutation, every
untouched part of the file stays exactly as it was.

What survives unchanged on a clean round-trip:

- Standalone comments at every position (between map entries, between
  sequence items, before the first document marker, between
  documents, after the last document, in a comment-only file).
- End-of-line comments and the exact whitespace gap before each `#`.
- Blank-line groups, including their count.
- Single vs double quotes vs plain scalar style on each scalar.
- Block-scalar layout (`|` literal, `>` folded, chomp indicators,
  explicit indent indicators, the verbatim source bytes).
- Multi-line plain scalar continuation indent.
- Multi-line flow collection layout (line breaks and item indents
  inside `[…]` and `{…}`).
- The trailing newline (or absence thereof) on the file.
- Stream-level directives (`%YAML`, `%TAG`).

After `setEntry()` or other AST edits, only the touched node's
representation changes. The surrounding source bytes are emitted
verbatim from their captured form.

```php
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;

$source = file_get_contents('config.yml');
$stream = (new YamlStringLoader())->load($source);
$output = (new YamlStringDumper())->dump($stream);
assert($output === $source);   // byte-identical
```

See `doc/examples/byte-identical-roundtrip.php` for a self-contained
proof.

## Errors

All exceptions extend `Horde\Yaml\Document\Exception` (which is a
`HordeRuntimeException`). The hierarchy:

| Exception | When |
|---|---|
| `ParseException`         | malformed YAML |
| `StructuralException`    | well-formed but semantically invalid (e.g. duplicate map key) |
| `DuplicateKeyException`  | strict duplicate-key enforcement (subclass of Structural) |
| `UnresolvedAliasException` | `*name` with no matching `&name` in the same document |
| `EncodingException`      | input is not valid UTF-8 |
| `IoException`            | file or stream I/O failure |
| `FileNotFoundException`  | load target missing |
| `KeyNotFoundException`   | strict `getEntry()` lookup miss (subclass of OutOfRange) |
| `OutOfRangeException`    | document or sequence index out of bounds |
| `InvalidAccessException` | API misuse (e.g. wrong node type for the call) |
| `EmitException`          | dumper could not represent a value |
| `UnsupportedOperationException` | `ArrayAccess` write attempts |

## YAML 1.2 strictness

The document layer is strict YAML 1.2 (Stage 1 §B9):

- `yes`, `no`, `on`, `off` are strings, not booleans
- only `true` / `false` (any case) parse as booleans
- octals require the `0o` prefix
- timestamps and binary tags are not auto-resolved

If a `.horde.yml` written for the legacy loader uses `yes`/`no` for
booleans, it loads as strings here. Re-quote or replace with
`true`/`false`.

## Conformance

The library passes 391 of 391 cases in the upstream yaml-test-suite
(YAML 1.2 reference suite). Every spec example loads as the spec
says it should; every malformed input is rejected.

```bash
git submodule update --init   # fetch the suite
phpunit test/integration/ConformanceTest.php
```

The conformance harness runs under `LeniencyPolicy::strictYaml12()`,
which rejects every non-spec deviation. By default the loaders use
`LeniencyPolicy::hordeCompat()`, which keeps the historical
tolerance for existing `.horde.yml` files. Switch policies on the
loader constructor:

```php
use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\YamlStringLoader;

$strict = new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
$compat = new YamlStringLoader(policy: LeniencyPolicy::hordeCompat());
```

Each policy flag toggles exactly one tolerated deviation. See the
`LeniencyPolicy` class for the full list.

## Performance ceilings

The default suite skips the perf gate. To run it:

```bash
phpunit -c phpunit-perf.xml.dist
```

Current ceilings:

- monorepo round-trip (191 .horde.yml files): under 5 seconds
- single typical file (<10 KB): under 100 ms load + dump
- peak memory: under 100× input size

These are regression detectors. The realised numbers are well
below them on a developer machine.
