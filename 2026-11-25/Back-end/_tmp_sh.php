<?php
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cached = App\Support\AlgorithmCache::get();
$details = $cached["assignmentDetails"] ?? [];
$days = [];
$for12122 = [];
foreach ($details as $d) {
  $tid = (int)($d["task_id"] ?? 0);
  foreach ($d["assignments"] ?? [] as $a) {
    $emp = (string)($a["employee"] ?? $a["employee_name"] ?? "");
    if (stripos($emp, "Shaimaa") === false) continue;
    $day = substr((string)($a["date"] ?? $a["day"] ?? ""), 0, 10);
    $h = (float)($a["hours"] ?? 0);
    if ($day < "2026-10-01" || $day > "2026-12-15") continue;
    if (!isset($days[$day])) $days[$day] = [];
    $days[$day][] = ["task"=>$tid, "name"=>$d["task_name"] ?? "", "h"=>$h, "proj"=>$d["project_name"] ?? $d["Project"] ?? ""];
    if ($tid === 12122) $for12122[] = "$day:$h";
  }
}
ksort($days);
foreach ($days as $day => $rows) {
  if ($day < "2026-11-10") continue;
  $sum = array_sum(array_column($rows, "h"));
  echo "$day total=$sum\n";
  foreach ($rows as $r) echo "  #{$r['task']} {$r['name']} {$r['h']}h\n";
}
echo "\n12122 all Shaimaa:\n".implode(", ", $for12122)."\n";
echo "12122 count=".count($for12122)." sum=".array_sum(array_map(fn($x)=> (float)explode(":",$x)[1], $for12122))."\n";
