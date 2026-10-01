<?php
// Convert a Wikipedia parse API response (stdin) into the text a volunteer reads aloud:
// only paragraphs and section headings, no infobox, references, tables or edit links.
$data = json_decode(stream_get_contents(STDIN), true);
$html = $data['parse']['text']['*'] ?? '';
$html = preg_replace('#<(sup|style|script)\b.*?</\1>#si', '', $html);
$html = preg_replace('#<span class="mw-editsection.*?</span></span>#si', '', $html);
preg_match_all('#<(p|h2|h3)\b[^>]*>(.*?)</\1>#si', $html, $m);
$lines = [];
foreach ($m[2] as $chunk) {
    $line = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $line = preg_replace('/[\x{200B}\x{FEFF}]/u', '', $line);
    if (preg_match('/^(Referencias|Notas|Véase también|Bibliografía|Enlaces externos)$/u', $line)) {
        break;
    }
    if ($line !== '') {
        $lines[] = $line;
    }
}
echo implode("\n", $lines), "\n";
