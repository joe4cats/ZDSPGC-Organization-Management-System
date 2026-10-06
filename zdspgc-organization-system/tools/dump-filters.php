<?php
// Temporary helper: dump the supported filter keys of each repo's where().
$dir = 'C:/Users/Acer/Downloads/agent-spec-main/zdspgc-organization-system/includes/models';
$repos = ['OrgRepo', 'StudentRepo', 'MemberRepo', 'AttendanceRepo', 'EventRepo'];
foreach ($repos as $f) {
    $src = file_get_contents($dir . '/' . $f . '.php');
    $i = strpos($src, 'function where(');
    if ($i === false) {
        echo str_pad($f, 16) . "(no where())\n";
        continue;
    }
    $seg = substr($src, $i, 3000);
    // keys written directly as $filters['key']
    preg_match_all('/filters\[\'([a-z_]+)\'\]/', $seg, $m1);
    // keys written in a foreach map: 'key' => 'column'
    preg_match_all('/\'([a-z_]+)\'\s*=>\s*\'[a-z_.]+\'/', $seg, $m2);
    $keys = array_unique(array_filter(array_merge($m1[1], $m2[1])));
    echo str_pad($f, 16) . implode(', ', $keys) . "\n";
}
