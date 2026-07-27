<?php
require __DIR__ . '/bootstrap.php';
$rows = [
    ['grade_level' => '7', 'grade_level_end' => '10'],
    ['grade_level' => '11', 'grade_level_end' => '12'],
    ['grade_level' => '9', 'grade_level_end' => '11'],
];
$jhs = $shs = 0;
foreach ($rows as $row) {
    $bucket = getSubjectLevelBucket($row['grade_level'], $row['grade_level_end']);
    if ($bucket === 'jhs') {
        $jhs++;
    } elseif ($bucket === 'shs') {
        $shs++;
    }
}
echo "jhs=$jhs shs=$shs\n";
