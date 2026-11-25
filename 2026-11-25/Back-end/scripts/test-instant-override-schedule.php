<?php
/**
 * Live probe: InstantOverrideScheduleApply distributes by share % without algo.
 * Run: php scripts/test-instant-override-schedule.php [taskId]
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ProjectTask;
use App\Support\AlgorithmCache;
use App\Support\InstantOverrideScheduleApply;

$taskId = isset($argv[1]) ? (int) $argv[1] : 12256;
$task = ProjectTask::query()->with(['part.project'])->find($taskId);
if (! $task) {
    fwrite(STDERR, "Task {$taskId} missing\n");
    exit(1);
}

$task->override_1 = $task->override_1 ?: 'Nora Keshk';
$task->override_2 = $task->override_2 ?: 'Rehaf Hamed';
$task->override_share_1 = '70';
$task->override_share_2 = '30';
$task->override_share_3 = null;
$task->save();
$task->refresh();

echo "Task #{$task->id} {$task->task_name} plan={$task->plan} pri={$task->pri}\n";
echo "Overrides {$task->override_1}/{$task->override_2} shares {$task->override_share_1}/{$task->override_share_2}\n";

$impact = InstantOverrideScheduleApply::applyForTasks([$task]);
echo 'Impact: '.json_encode($impact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

$cache = AlgorithmCache::get() ?? [];
$detail = collect($cache['assignmentDetails'] ?? [])->first(fn ($d) => (int) ($d['task_id'] ?? 0) === $taskId);
$hours = [];
foreach (($detail['assignments'] ?? []) as $row) {
    $e = trim((string) ($row['employee'] ?? ''));
    if ($e === '') {
        continue;
    }
    $hours[$e] = ($hours[$e] ?? 0) + (float) ($row['hours'] ?? 0);
}
echo 'Cache hours: '.json_encode($hours).PHP_EOL;
echo 'Mode: '.($detail['mode'] ?? 'n/a').PHP_EOL;

$plan = (float) $task->plan;
$ok70 = abs(($hours['Nora Keshk'] ?? -1) - round($plan * 0.7)) <= 1.05
    || abs(($hours[$task->override_1] ?? -1) - round($plan * 0.7)) <= 1.05;
$ok30 = abs(($hours['Rehaf Hamed'] ?? -1) - round($plan * 0.3)) <= 1.05
    || abs(($hours[$task->override_2] ?? -1) - round($plan * 0.3)) <= 1.05;

if (! ($impact['applied'] ?? false) || ! $ok70 || ! $ok30) {
    fwrite(STDERR, "FAIL instant split\n");
    exit(1);
}

echo "PASS instant override schedule reflects 70/30\n";
exit(0);
