<?php
declare(strict_types=1);

/**
 * Retrieval debugger: prints what the API understands and shortlists for a message,
 * without calling a model. Useful for checking standards coverage after a catalog rebuild.
 *
 *   php tests/debug_retrieval.php "ISO 6506" "ASTM B117" ...
 */

require __DIR__ . '/../lib/security.php';
require __DIR__ . '/../lib/catalog.php';

$messages = array_slice($argv, 1);
if ($messages === []) {
    $messages = [
        'ISO 6506', 'ASTM E18', 'ASTM B117', 'ASTM D412', 'ASTM D638', 'ISO 6892-1',
        'ISO 2039-2', 'ASTM D2240', 'cannabis potency testing equipment',
        'martindale abrasion for car seat fabric', 'steel rebar tensile 1000 kN',
        'salt spray chamber for corrosion', 'HPLC for cannabis potency',
        'melt flow index for polypropylene',
    ];
}

$index = wot_catalog_index();
printf("catalog: %d products\n\n", count($index));

foreach ($messages as $message) {
    $query     = wot_understand($message, [], $index);
    $retrieval = wot_retrieve($query, $index, 10);
    printf("MESSAGE: %s\n", $message);
    printf("  standards: %s\n", implode(', ', $query['standards']) ?: '-');
    printf("  types:     %s\n", implode(' / ', $query['types']) ?: '-');
    printf("  cats:      %s\n", implode(' / ', $query['cat_hints']) ?: '-');
    printf("  subject:   %s\n", implode(', ', $query['subject']) ?: '-');
    printf("  capacity:  %s\n", is_array($query['capacity']) ? $query['capacity']['value'] . ' ' . $query['capacity']['unit'] : '-');
    printf("  pass: %s (%d candidates)\n", $retrieval['pass'], count($retrieval['candidates']));
    foreach (array_slice($retrieval['candidates'], 0, 5) as $candidate) {
        printf("    - %s | %s\n", $candidate['name'], $candidate['url']);
    }
    echo "\n";
}
