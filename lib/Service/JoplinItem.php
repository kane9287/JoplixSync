<?php
namespace OCA\Joplix\Service;

/**
 * Reading Joplin's sync item format, shared by the model (what Joplix shows) and the sync engine (what it
 * uploads), so both agree on what an item's id and type_ are.
 *
 * An item file is "<body>\n\n<key>: <value>\n...". The metadata block is the run of non-blank lines at the
 * end of the file; the body is everything before the blank line that precedes it. Body-less items (tags,
 * note-tag links, resources) may consist of the metadata block alone. As in Joplin, a key is the text before
 * the first colon and the value is the rest of the line without surrounding white space.
 *
 * Line endings are normalised to LF first. Joplin writes LF; CRLF or CR only appear in files edited by other
 * tools, and without the normalisation such a file parsed as type_ "1\r" and vanished from the lists.
 */
final class JoplinItem {
    /**
     * @return array{body: string, metaLines: string[], meta: array<string,string>, finalLf: bool}
     */
    public static function parse(string $content): array {
        $finalLf = $content !== '' && ($content[strlen($content) - 1] === "\n" || $content[strlen($content) - 1] === "\r");
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        while ($lines !== [] && trim((string)end($lines)) === '') {
            array_pop($lines);
        }
        $i = count($lines) - 1;
        while ($i >= 0 && trim($lines[$i]) !== '') {
            $i--;
        }
        $metaLines = array_slice($lines, $i + 1);
        $body = $i >= 0 ? implode("\n", array_slice($lines, 0, $i)) : '';
        $meta = [];
        foreach ($metaLines as $line) {
            if (preg_match('/^([A-Za-z0-9_]+):(.*)$/D', $line, $m)) {
                $meta[$m[1]] = trim($m[2]);
            }
        }
        return ['body' => $body, 'metaLines' => $metaLines, 'meta' => $meta, 'finalLf' => $finalLf];
    }

    /** @return array<string,string> the metadata of a serialised item */
    public static function meta(string $content): array {
        return self::parse($content)['meta'];
    }
}
