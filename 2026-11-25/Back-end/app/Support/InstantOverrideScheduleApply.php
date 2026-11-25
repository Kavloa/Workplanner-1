<?php

namespace App\Support;

use App\Http\Controllers\Api\ResourceHistogramController;
use App\Models\EmployeeAvailability;
use App\Models\HistogramScheduleOverride;
use App\Models\ProjectTask;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * On PDE/DL save (no algorithm run): redistribute a multi-override task onto the
 * histogram schedule using Allocation % shares. Lower-priority work is displaced
 * automatically; higher-priority displacements are applied with warnings.
 */
class InstantOverrideScheduleApply
{
    private const MAX_PACK_WEEKDAYS = 120;

    /**
     * @param  list<ProjectTask|array<string, mixed>>  $tasks
     * @return array{
     *   applied: bool,
     *   task_count: int,
     *   displaced_lower: list<array<string, mixed>>,
     *   displaced_higher: list<array<string, mixed>>,
     *   unplaced: list<array<string, mixed>>,
     *   warnings: list<string>
     * }
     */
    public static function applyForTasks(array $tasks, ?Request $request = null): array
    {
        $empty = [
            'applied' => false,
            'task_count' => 0,
            'displaced_lower' => [],
            'displaced_higher' => [],
            'unplaced' => [],
            'warnings' => [],
        ];

        $candidates = [];
        foreach ($tasks as $task) {
            $normalized = self::normalizeTask($task);
            if ($normalized !== null) {
                $candidates[] = $normalized;
            }
        }

        if ($candidates === []) {
            return $empty;
        }

        EmployeeNames::warmEmployeeCache();
        $lock = Cache::lock('algorithm-cache-write', 30);
        $lockAcquired = false;

        try {
            $lock->block(15);
            $lockAcquired = true;

            $cached = AlgorithmCache::get($request) ?? [];
            $details = is_array($cached['assignmentDetails'] ?? null)
                ? $cached['assignmentDetails']
                : [];

            $displacedLower = [];
            $displacedHigher = [];
            $unplaced = [];
            $warnings = [];
            $appliedCount = 0;
            $touchedPinKeys = [];

            foreach ($candidates as $task) {
                $result = self::applyOneTask($details, $task);
                $details = $result['details'];
                if (! $result['changed']) {
                    if ($result['warnings'] !== []) {
                        $warnings = array_merge($warnings, $result['warnings']);
                    }
                    continue;
                }

                $appliedCount++;
                $displacedLower = array_merge($displacedLower, $result['displaced_lower']);
                $displacedHigher = array_merge($displacedHigher, $result['displaced_higher']);
                $unplaced = array_merge($unplaced, $result['unplaced']);
                $warnings = array_merge($warnings, $result['warnings']);

                foreach ($result['pin_employees'] as $employee) {
                    $touchedPinKeys[$task['id'].'|'.mb_strtolower($employee)] = [
                        'task_id' => $task['id'],
                        'employee' => $employee,
                    ];
                }
                foreach ($result['displaced_pin_employees'] as $meta) {
                    $key = $meta['task_id'].'|'.mb_strtolower($meta['employee']);
                    $touchedPinKeys[$key] = $meta;
                }
            }

            if ($appliedCount === 0) {
                return [
                    ...$empty,
                    'warnings' => array_values(array_unique($warnings)),
                ];
            }

            foreach ($touchedPinKeys as $meta) {
                $pinTaskId = (int) $meta['task_id'];
                if ($pinTaskId <= 0 || ! ProjectTask::query()->whereKey($pinTaskId)->exists()) {
                    continue;
                }

                $hoursByDay = HistogramScheduleOverrides::extractEmployeeHours(
                    $details,
                    $pinTaskId,
                    (string) $meta['employee'],
                );
                if ($hoursByDay === []) {
                    HistogramScheduleOverride::query()
                        ->where('task_id', $pinTaskId)
                        ->where('employee', (string) $meta['employee'])
                        ->delete();
                    continue;
                }

                HistogramScheduleOverride::query()->updateOrCreate(
                    [
                        'task_id' => $pinTaskId,
                        'employee' => (string) $meta['employee'],
                    ],
                    ['hours_by_day' => $hoursByDay],
                );
            }

            AlgorithmCache::put([
                ...$cached,
                'assignmentDetails' => $details,
            ], $request);

            ResourceHistogramController::clearFullPayloadCache($request);

            $warnings = array_values(array_unique($warnings));
            if ($displacedHigher !== []) {
                $names = array_values(array_unique(array_map(
                    static fn (array $row) => trim((string) ($row['task_name'] ?? 'Task #'.$row['task_id'])),
                    $displacedHigher,
                )));
                $warnings[] = 'Higher-priority work was trimmed to fit this split: '.implode(', ', array_slice($names, 0, 6))
                    .(count($names) > 6 ? '…' : '').'.';
            }

            return [
                'applied' => true,
                'task_count' => $appliedCount,
                'displaced_lower' => $displacedLower,
                'displaced_higher' => $displacedHigher,
                'unplaced' => $unplaced,
                'warnings' => $warnings,
            ];
        } finally {
            if ($lockAcquired) {
                $lock->release();
            }
            EmployeeNames::clearEmployeeCache();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @param  array{
     *   id:int, task_name:string, plan:float, pri:float, doc_supply:string,
     *   completion:?string, project_id:?int, project_name:?string,
     *   overrides: list<array{slot:int, name:string, share:float}>
     * }  $task
     * @return array{
     *   details: array<int, array<string, mixed>>,
     *   changed: bool,
     *   displaced_lower: list<array<string, mixed>>,
     *   displaced_higher: list<array<string, mixed>>,
     *   unplaced: list<array<string, mixed>>,
     *   warnings: list<string>,
     *   pin_employees: list<string>,
     *   displaced_pin_employees: list<array{task_id:int, employee:string}>
     * }
     */
    private static function applyOneTask(array $details, array $task): array
    {
        $empty = [
            'details' => $details,
            'changed' => false,
            'displaced_lower' => [],
            'displaced_higher' => [],
            'unplaced' => [],
            'warnings' => [],
            'pin_employees' => [],
            'displaced_pin_employees' => [],
        ];

        $overrides = $task['overrides'];
        $percents = array_map(static fn (array $row) => (float) $row['share'], $overrides);
        $targets = self::splitPlanByPercents($task['plan'], $percents);
        if (array_sum($targets) <= 0) {
            return $empty;
        }

        $employeeNames = array_map(static fn (array $row) => $row['name'], $overrides);
        $days = self::workingDays(
            $task['doc_supply'],
            $task['completion'] ?? Carbon::parse($task['doc_supply'])->addWeekdays(self::MAX_PACK_WEEKDAYS)->toDateString(),
        );
        if ($days === []) {
            return [
                ...$empty,
                'warnings' => ["Task #{$task['id']} has no workable days between doc supply and completion."],
            ];
        }

        $capIndex = self::buildCapIndexForEmployees($employeeNames, $days[0], $days[array_key_last($days)]);

        // Ensure a detail row exists for this task.
        $detailIndex = null;
        foreach ($details as $index => $detail) {
            if ((int) ($detail['task_id'] ?? 0) === $task['id']) {
                $detailIndex = $index;
                break;
            }
        }
        if ($detailIndex === null) {
            $details[] = [
                'task_id' => $task['id'],
                'TaskName' => $task['task_name'],
                'Pri' => $task['pri'],
                'Plan' => $task['plan'],
                'project_id' => $task['project_id'],
                'project_name' => $task['project_name'],
                'mode' => 'INSTANT_OVERRIDE_SHARE_SPLIT',
                'assignments' => [],
            ];
            $detailIndex = array_key_last($details);
        } else {
            $details[$detailIndex]['Pri'] = $task['pri'];
            $details[$detailIndex]['Plan'] = $task['plan'];
            $details[$detailIndex]['TaskName'] = $task['task_name'];
            $details[$detailIndex]['mode'] = 'INSTANT_OVERRIDE_SHARE_SPLIT';
        }

        // Clear prior hours for these override employees on this task.
        $assignments = is_array($details[$detailIndex]['assignments'] ?? null)
            ? $details[$detailIndex]['assignments']
            : [];
        $nameSet = [];
        foreach ($employeeNames as $name) {
            $nameSet[mb_strtolower($name)] = true;
        }
        $assignments = array_values(array_filter(
            $assignments,
            static function (array $row) use ($nameSet) {
                $emp = EmployeeNames::canonicalize((string) ($row['employee'] ?? ''))
                    ?? trim((string) ($row['employee'] ?? ''));

                return $emp === '' || ! isset($nameSet[mb_strtolower($emp)]);
            },
        ));
        $details[$detailIndex]['assignments'] = $assignments;

        $displacedLower = [];
        $displacedHigher = [];
        $unplaced = [];
        $warnings = [];
        $displacedPins = [];
        $pinEmployees = [];

        foreach ($overrides as $index => $override) {
            $target = (float) ($targets[$index] ?? 0);
            if ($target <= 0.0001) {
                continue;
            }

            $employee = $override['name'];
            $pinEmployees[] = $employee;
            $remaining = $target;

            foreach ($days as $day) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $cap = EmployeeAvailabilityHours::resolveDailyCapForEmployee(
                    $capIndex,
                    $employee,
                    $day,
                    false,
                );
                if ($cap === null) {
                    $cap = EmployeeAvailabilityHours::DEFAULT_WORKDAY_HOURS;
                }
                if ($cap <= 0.0001) {
                    continue;
                }

                $usedElsewhere = self::employeeHoursOnDayExcludingTask($details, $employee, $day, $task['id']);
                $free = max(0.0, (float) $cap - $usedElsewhere);
                $need = min($remaining, (float) $cap);

                if ($free + 0.0001 < $need) {
                    $shortfall = round($need - $free, 4);
                    $displace = self::displaceOtherTasksOnDay(
                        $details,
                        $employee,
                        $day,
                        $shortfall,
                        $task['id'],
                        $task['pri'],
                        $task['task_name'],
                    );
                    $details = $displace['details'];
                    $displacedLower = array_merge($displacedLower, $displace['displaced_lower']);
                    $displacedHigher = array_merge($displacedHigher, $displace['displaced_higher']);
                    $displacedPins = array_merge($displacedPins, $displace['pin_employees']);
                    $usedElsewhere = self::employeeHoursOnDayExcludingTask($details, $employee, $day, $task['id']);
                    $free = max(0.0, (float) $cap - $usedElsewhere);
                }

                $place = min($remaining, $free);
                if ($place <= 0.0001) {
                    continue;
                }

                $details[$detailIndex]['assignments'][] = [
                    'employee' => $employee,
                    'date' => $day,
                    'hours' => round($place, 4),
                ];
                $remaining = round($remaining - $place, 4);
            }

            if ($remaining > 0.05) {
                $unplaced[] = [
                    'task_id' => $task['id'],
                    'task_name' => $task['task_name'],
                    'employee' => $employee,
                    'hours' => $remaining,
                    'target' => $target,
                ];
                $warnings[] = sprintf(
                    '%s: could not place %.1fh of %.1fh for %s in the supply→completion window.',
                    $task['task_name'],
                    $remaining,
                    $target,
                    $employee,
                );
            }
        }

        // Keep assignments sorted.
        usort($details[$detailIndex]['assignments'], static function (array $left, array $right) {
            $dateCmp = strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return strcasecmp((string) ($left['employee'] ?? ''), (string) ($right['employee'] ?? ''));
        });

        return [
            'details' => $details,
            'changed' => true,
            'displaced_lower' => $displacedLower,
            'displaced_higher' => $displacedHigher,
            'unplaced' => $unplaced,
            'warnings' => $warnings,
            'pin_employees' => $pinEmployees,
            'displaced_pin_employees' => $displacedPins,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array{
     *   details: array<int, array<string, mixed>>,
     *   displaced_lower: list<array<string, mixed>>,
     *   displaced_higher: list<array<string, mixed>>,
     *   pin_employees: list<array{task_id:int, employee:string}>
     * }
     */
    private static function displaceOtherTasksOnDay(
        array $details,
        string $employee,
        string $day,
        float $hoursNeeded,
        int $sourceTaskId,
        float $sourcePri,
        string $sourceTaskName,
    ): array {
        $candidates = [];
        foreach ($details as $detailIndex => $detail) {
            $otherTaskId = (int) ($detail['task_id'] ?? 0);
            if ($otherTaskId <= 0 || $otherTaskId === $sourceTaskId) {
                continue;
            }
            $otherPri = is_numeric($detail['Pri'] ?? null) ? (float) $detail['Pri'] : PHP_FLOAT_MAX;
            $otherName = (string) ($detail['TaskName'] ?? $detail['task_name'] ?? ('Task #'.$otherTaskId));

            foreach ($detail['assignments'] ?? [] as $rowIndex => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $assignee = EmployeeNames::canonicalize((string) ($row['employee'] ?? ''))
                    ?? trim((string) ($row['employee'] ?? ''));
                if ($assignee === '' || strcasecmp($assignee, $employee) !== 0) {
                    continue;
                }
                $rowDay = self::normalizeDate((string) ($row['date'] ?? ''));
                $hours = is_numeric($row['hours'] ?? null) ? (float) $row['hours'] : 0.0;
                if ($rowDay !== $day || $hours <= 0.0001) {
                    continue;
                }

                $candidates[] = [
                    'detail_index' => $detailIndex,
                    'row_index' => $rowIndex,
                    'task_id' => $otherTaskId,
                    'task_name' => $otherName,
                    'pri' => $otherPri,
                    'hours' => $hours,
                    'employee' => $assignee,
                    'is_higher_priority' => $otherPri < $sourcePri - 0.0001,
                ];
            }
        }

        // Lower priority first (higher Pri number), then higher-priority last.
        usort($candidates, static function (array $a, array $b) {
            if ($a['is_higher_priority'] !== $b['is_higher_priority']) {
                return $a['is_higher_priority'] ? 1 : -1;
            }
            $priCmp = $b['pri'] <=> $a['pri'];
            if ($priCmp !== 0) {
                return $priCmp;
            }

            return $b['hours'] <=> $a['hours'];
        });

        $remaining = $hoursNeeded;
        $displacedLower = [];
        $displacedHigher = [];
        $pinEmployees = [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0.0001) {
                break;
            }

            $detailIndex = $candidate['detail_index'];
            $rowIndex = $candidate['row_index'];
            if (! isset($details[$detailIndex]['assignments'][$rowIndex])) {
                continue;
            }

            $current = (float) ($details[$detailIndex]['assignments'][$rowIndex]['hours'] ?? 0);
            if ($current <= 0.0001) {
                continue;
            }

            $cut = min($current, $remaining);
            $next = round($current - $cut, 4);
            if ($next <= 0.0001) {
                unset($details[$detailIndex]['assignments'][$rowIndex]);
                $details[$detailIndex]['assignments'] = array_values($details[$detailIndex]['assignments']);
            } else {
                $details[$detailIndex]['assignments'][$rowIndex]['hours'] = $next;
            }

            $entry = [
                'task_id' => $candidate['task_id'],
                'task_name' => $candidate['task_name'],
                'employee' => $candidate['employee'],
                'date' => $day,
                'hours' => $cut,
                'pri' => $candidate['pri'],
                'source_task_id' => $sourceTaskId,
                'source_task_name' => $sourceTaskName,
                'source_pri' => $sourcePri,
            ];
            if ($candidate['is_higher_priority']) {
                $displacedHigher[] = $entry;
            } else {
                $displacedLower[] = $entry;
            }

            $pinEmployees[] = [
                'task_id' => $candidate['task_id'],
                'employee' => $candidate['employee'],
            ];
            $remaining = round($remaining - $cut, 4);
        }

        return [
            'details' => $details,
            'displaced_lower' => $displacedLower,
            'displaced_higher' => $displacedHigher,
            'pin_employees' => $pinEmployees,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private static function employeeHoursOnDayExcludingTask(
        array $details,
        string $employee,
        string $day,
        int $excludeTaskId,
    ): float {
        $total = 0.0;
        foreach ($details as $detail) {
            if ((int) ($detail['task_id'] ?? 0) === $excludeTaskId) {
                continue;
            }
            foreach ($detail['assignments'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $assignee = EmployeeNames::canonicalize((string) ($row['employee'] ?? ''))
                    ?? trim((string) ($row['employee'] ?? ''));
                if ($assignee === '' || strcasecmp($assignee, $employee) !== 0) {
                    continue;
                }
                $rowDay = self::normalizeDate((string) ($row['date'] ?? ''));
                $hours = is_numeric($row['hours'] ?? null) ? (float) $row['hours'] : 0.0;
                if ($rowDay === $day && $hours > 0) {
                    $total += $hours;
                }
            }
        }

        return round($total, 4);
    }

    /**
     * @param  ProjectTask|array<string, mixed>  $task
     * @return array{
     *   id:int, task_name:string, plan:float, pri:float, doc_supply:string,
     *   completion:?string, project_id:?int, project_name:?string,
     *   overrides: list<array{slot:int, name:string, share:float}>
     * }|null
     */
    private static function normalizeTask(ProjectTask|array $task): ?array
    {
        if ($task instanceof ProjectTask) {
            $task->loadMissing(['part:id,project_id', 'part.project:id,name']);
            $data = $task->toArray();
            $data['project_id'] = $task->part?->project_id;
            $data['project_name'] = $task->part?->project?->name;
        } else {
            $data = $task;
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || ! empty($data['done'])) {
            return null;
        }

        $overrides = [];
        for ($slot = 1; $slot <= 3; $slot++) {
            $name = trim((string) ($data['override_'.$slot] ?? ''));
            if ($name === '') {
                continue;
            }
            $canon = EmployeeNames::canonicalize($name) ?? $name;
            $rawShare = $data['override_share_'.$slot] ?? $data['Override_Share_'.$slot] ?? null;
            $overrides[] = [
                'slot' => $slot,
                'name' => $canon,
                'share' => ($rawShare !== null && $rawShare !== '' && is_numeric($rawShare))
                    ? max(0.0, (float) $rawShare)
                    : null,
            ];
        }

        if (count($overrides) < 2) {
            return null;
        }

        $shares = array_column($overrides, 'share');
        $anyShare = false;
        foreach ($shares as $share) {
            if ($share !== null) {
                $anyShare = true;
                break;
            }
        }
        if (! $anyShare) {
            $defaults = count($overrides) === 2 ? [50.0, 50.0] : [40.0, 30.0, 30.0];
            foreach ($overrides as $index => &$override) {
                $override['share'] = (float) ($defaults[$index] ?? 0);
            }
            unset($override);
        } else {
            $filled = [];
            $defaults = count($overrides) === 2 ? [50.0, 50.0] : [40.0, 30.0, 30.0];
            foreach ($overrides as $index => $override) {
                $filled[] = $override['share'] !== null
                    ? (float) $override['share']
                    : (float) ($defaults[$index] ?? 0);
            }
            $sum = array_sum($filled);
            if ($sum <= 0.001) {
                $filled = $defaults;
                $sum = array_sum($filled);
            } elseif (abs($sum - 100) > 0.5) {
                $filled = array_map(static fn ($value) => ($value * 100) / $sum, $filled);
            }
            foreach ($overrides as $index => &$override) {
                $override['share'] = (float) $filled[$index];
            }
            unset($override);
        }

        $plan = null;
        if (isset($data['progress_left_hours']) && is_numeric($data['progress_left_hours']) && (float) $data['progress_left_hours'] > 0) {
            $plan = (float) $data['progress_left_hours'];
        } elseif (isset($data['plan']) && is_numeric($data['plan']) && (float) $data['plan'] > 0) {
            $plan = (float) $data['plan'];
        }
        if ($plan === null) {
            return null;
        }

        $docSupply = self::normalizeDate((string) ($data['doc_supply'] ?? ''));
        if ($docSupply === null) {
            return null;
        }

        $completion = self::normalizeDate((string) ($data['completion'] ?? ''));
        $pri = is_numeric($data['pri'] ?? null) ? (float) $data['pri'] : PHP_FLOAT_MAX;

        return [
            'id' => $id,
            'task_name' => (string) ($data['task_name'] ?? ('Task #'.$id)),
            'plan' => $plan,
            'pri' => $pri,
            'doc_supply' => $docSupply,
            'completion' => $completion,
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'project_name' => isset($data['project_name']) ? (string) $data['project_name'] : null,
            'overrides' => $overrides,
        ];
    }

    /**
     * @param  list<string>  $employeeNames
     * @return array<int, array<string, float>>
     */
    private static function buildCapIndexForEmployees(array $employeeNames, string $start, string $end): array
    {
        $employeeIds = [];
        foreach ($employeeNames as $name) {
            $employee = EmployeeNames::findByAnyName($name);
            if ($employee) {
                $employeeIds[(int) $employee->id] = true;
            }
        }
        if ($employeeIds === []) {
            return [];
        }

        $rows = EmployeeAvailability::query()
            ->whereIn('employee_id', array_keys($employeeIds))
            ->whereBetween('date', [$start, $end])
            ->get(['employee_id', 'date', 'total', 'total_ava']);

        $index = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->date)->toDateString();
            $hours = EmployeeAvailabilityHours::parseTimeToHours($row->total_ava ?? $row->total ?? '00:00');
            $index[(int) $row->employee_id][$day] = $hours;
        }

        return $index;
    }

    /**
     * @param  list<float>  $percents
     * @return list<int>
     */
    private static function splitPlanByPercents(float $plan, array $percents): array
    {
        $count = count($percents);
        $planInt = (int) round($plan);
        if ($count === 0 || $planInt <= 0) {
            return array_fill(0, $count, 0);
        }

        $raw = array_map(static fn ($percent) => $planInt * (((float) $percent) / 100), $percents);
        $hours = array_map(static fn ($value) => (int) floor($value + 1e-8), $raw);
        $leftover = $planInt - array_sum($hours);
        $order = range(0, $count - 1);
        usort($order, static function (int $left, int $right) use ($raw, $hours) {
            $fraction = ($raw[$right] - $hours[$right]) <=> ($raw[$left] - $hours[$left]);

            return $fraction !== 0 ? $fraction : $left <=> $right;
        });
        foreach ($order as $index) {
            if ($leftover <= 0) {
                break;
            }
            $hours[$index]++;
            $leftover--;
        }

        return $hours;
    }

    /**
     * @return list<string>
     */
    private static function workingDays(string $start, string $end): array
    {
        $days = [];
        $count = 0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if ($day->isWeekend()) {
                continue;
            }
            $days[] = $day->toDateString();
            $count++;
            if ($count >= self::MAX_PACK_WEEKDAYS) {
                break;
            }
        }

        return $days;
    }

    private static function normalizeDate(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $matches) === 1) {
            return $matches[1];
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
