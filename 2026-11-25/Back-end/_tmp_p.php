<?php
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cached = App\Support\AlgorithmCache::get();
foreach ($cached["assignmentDetails"] ?? [] as $d) {
  if ((int)($d["task_id"]??0) !== 12122) continue;
  $keys = ["progress_fixed","progress_fixed_hours","progress_anchor","override_share_targets","totals_by_employee","end_dates","remaining","mode","AL","Doc_Supply","completion"];
  foreach ($keys as $k) echo "$k=".json_encode($d[$k]??null)."\n";
  $rows = DB::table("histogram_schedule_overrides")->where("task_id",12122)->get();
  echo "pins=".$rows->count()."\n";
  break;
}
