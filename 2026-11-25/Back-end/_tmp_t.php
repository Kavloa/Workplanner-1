<?php
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cached = App\Support\AlgorithmCache::get();
foreach ($cached["assignmentDetails"] ?? [] as $d) {
  if ((int)($d["task_id"]??0) !== 12122) continue;
  $sum=0; $by=[]; 
  foreach ($d["assignments"]??[] as $a) {
    $e=$a["employee"]??$a["employee_name"]??"?";
    $h=(float)($a["hours"]??0);
    $sum+=$h; $by[$e]=($by[$e]??0)+$h;
  }
  echo "task12122 plan=".($d["Plan"]??"")." adjusted=".($d["adjusted_plan"]??"")." validation=".($d["validation_plan"]??"")."\n";
  echo "total assigned=$sum by=".json_encode($by)."\n";
  echo "progress_fixed_hours=".($d["progress_fixed_hours"]??"")."\n";
  break;
}
