# Upgrading

## From `Horde_Yaml` to `Horde\Yaml\Document`

The legacy `Horde_Yaml::load()` / `Horde_Yaml::dump()` API is
unchanged and will keep working. The document layer is a parallel
addition for code that needs comment-preserving round-trip.

You do not need to migrate. Migrate only when:

- you are editing config files programmatically and want to keep
  comments and blank lines, or
- you need anchors, aliases, or per-node style preserved across a
  load + edit + dump cycle.

### Loading

```php
// Legacy: array out
$data = Horde_Yaml::loadFile('config.yml');
$name = $data['name'];

// Document layer: AST out
$stream = (new Horde\Yaml\Document\YamlFileLoader())->load('config.yml');
$name = $stream->document(0)->getEntry('name');
```

### Dumping

```php
// Legacy: array in, string out
$yaml = Horde_Yaml::dump($data);

// Document layer: AST in, string out
$yaml = (new Horde\Yaml\Document\YamlStringDumper())->dump($stream);
```

### YAML 1.2 strictness

The document layer rejects YAML 1.1 boolean spellings (`yes`, `no`,
`on`, `off`). The legacy loader accepts them and converts to
booleans. If you migrate a file that uses these, re-quote or
replace them with `true` / `false`.

The legacy loader auto-resolves timestamps and binary tags. The
document layer leaves them as strings unless an explicit tag is
present.

## Future

Detailed upgrading notes for breaking changes will land here when a
2.x line of the document layer ships. The 1.x line targets stable
API and no behaviour changes after release.
