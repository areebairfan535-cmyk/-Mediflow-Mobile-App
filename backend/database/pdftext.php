<?php
/**
 * The readable text out of a PDF, for asserting on in tests.
 *
 * A PDF's page content is a compressed stream, so `strings` finds nothing in
 * it — which looks exactly like an empty document. Inflate the streams, then
 * pull out the literals the text operators draw.
 */
$file = $argv[1] ?? 'prescription.pdf';
$raw  = file_get_contents($file);

preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $streams);

$decoded = '';
foreach ($streams[1] as $s) {
    $out = @gzuncompress($s);
    if ($out === false) {
        $out = @gzinflate($s);
    }
    $decoded .= $out === false ? $s : $out;
}

// Literal strings: ( ... ) with backslash escapes.
preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/', $decoded, $literals);

$words = array_map(
    static fn (string $x): string => stripcslashes($x),
    $literals[1] ?? [],
);

echo implode("\n", $words), "\n";
