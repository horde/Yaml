<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Parser;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\YamlStream;

/**
 * Composes Scanner, Parser, and Resolver into the load operation.
 *
 * The pipeline is the parser side's only public entry point;
 * loaders are thin wrappers that obtain bytes from their source and
 * invoke this class.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/05-parser-strategy-2026-06-12.md §1.2
 */
final class Pipeline
{
    private Scanner $scanner;
    private Parser $parser;
    private Resolver $resolver;
    private LeniencyPolicy $policy;

    public function __construct(
        ?Scanner $scanner = null,
        ?Parser $parser = null,
        ?Resolver $resolver = null,
        bool $legacyBooleans = false,
        ?TagRegistry $tagRegistry = null,
        bool $recognizeTimestamps = false,
        ?LeniencyPolicy $policy = null,
    ) {
        $this->policy = $policy ?? LeniencyPolicy::hordeCompat();
        $this->scanner = $scanner ?? new Scanner($this->policy);
        $this->parser = $parser ?? new Parser($this->policy);
        $this->resolver = $resolver ?? new Resolver(
            legacyBooleans: $legacyBooleans,
            tagRegistry: $tagRegistry,
            recognizeTimestamps: $recognizeTimestamps,
            policy: $this->policy,
        );
    }

    public function parse(string $source): YamlStream
    {
        $tokens = $this->scanner->scan($source);
        $stream = $this->parser->parse($tokens);
        // Round-trip: a source ending in `\n` (or `\r\n`) must round-
        // trip with one. Set the flag from the source bytes so the
        // emitter's terminal-newline check fires when appropriate.
        if ($source !== '' && str_ends_with($source, "\n")) {
            $stream->setTrailingNewline(true);
        }
        // Round-trip: the scanner normalises CRLF to LF so the rest of the
        // parser sees one line ending, and the emitter already writes whatever
        // the stream names - but nothing recorded what the source used, so a
        // CRLF document was silently rewritten with LF.
        //
        // Set only when EVERY newline is CRLF. A file with mixed endings has no
        // single ending to preserve, and quietly picking one would rewrite the
        // other; LF leaves that document visibly changed rather than subtly, so
        // a caller comparing input to output can still tell.
        $lf = substr_count($source, "\n");
        if ($lf > 0 && substr_count($source, "\r\n") === $lf) {
            $stream->setLineEnding("\r\n");
        }
        $this->resolver->resolve($stream);

        return $stream;
    }
}
