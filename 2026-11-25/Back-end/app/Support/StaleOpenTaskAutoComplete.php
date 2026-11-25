<?php

namespace App\Support;

use App\Http\Controllers\Api\ResourceHistogramController;
use App\Models\HistogramScheduleOverride;
use App\Models\Project;
use App\Models\ProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auto-complete open tasks that started long ago and have gone idle on the schedule.
 *
 * Rules (defaults):
 * - doc_supply is at least 60 days ago
 * - had at least one positive assignment / override hour historically
 * - no positive hours in the last 3 calendar days or any future day
 * - project_completion_date = last assignment day (not planned completion)
 */
class StaleOpenTaskAutoComplete
{
    public const STARTED_DAYS_AGO = 60;

    public const IDLE_LOOKBACK_DAYS = 3;

    /**
     * @return array{
     *   dry_run: bool,
     *   scanned: int,
     *   completed: int,
     *   tasks: list<array{id:int, task_name:?string, project_id:?int, project_name:?string, doc_supply:?string, last_assignment:?string, project_completion_date:string}>
     * }
     */
    public static function run(
        bool $dryRun = false,
        int $startedDaysAgo = self::STARTED_DAYS_AGO,
        int $idleLookbackDays = self::IDLE_LOOKBACK_DAYS,
    ): array {
        $startedDaysAgo = max(1, $startedDaysAgo);
        $idleLookbackDays = max(1, $idleLookbackDays);

        $today = Carbon::now()->startOfDay();
        $startedOnOrBefore = $today->copy()->subDays($startedDaysAgo)->toDateString();
        // "Last 3 days" = today and the previous 2 days → idle if no hours from that cutoff onward.
        $idleFromYmd = $today->copy()->subDays($idleLookbackDays - 1)->toDateString();

        $openTasks = ProjectTask::query()
            ->with(['part.project:id,name,status'])
            ->where(function ($query) {
                $query->where('done', false)->orWhereNull('done');
            })
            ->whereNotNull('doc_supply')
            ->where('doc_supply', '!=', '')
            ->whereHas('part.project', function ($query) {
                $query->where(function ($statusQuery) {
                    $statusQuery->whereNull('status')
                        ->orWhere('status', '!=', 'on_hold');
                });
            })
            ->get([
                'id',
                'part_id',
                'task_name',
                'doc_supply',
                'plan',
                'progress_plan',
                'progress_per',
                'progress_left_hours',
                'done',
                'project_completion_date',
                'progress_updated',
                'dl_done',
                'dl_progress_per',
                'dl_project_completion_date',
                'dl_progress_updated',
            ]);

        $candidateTasks = $openTasks->filter(function (ProjectTask $task) use ($startedOnOrBefore) {
            $docSupply = ReleaseAssignmentsAfterDone::toYmd($task->doc_supply);

            return $docSupply !== null && $docSupply <= $startedOnOrBefore;
        })->values();

        $hoursByTask = self::buildPositiveHoursByTask(
            $candidateTasks->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $toComplete = [];
        foreach ($candidateTasks as $task) {
            $taskId = (int) $task->id;
            $dayHours = $hoursByTask[$taskId] ?? [];
            // Assignment-driven: skip tasks that never received any schedule hours.
            $lastAssignment = self::lastAssignmentDay($dayHours);
            if ($lastAssignment === null) {
                continue;
            }
            if (self::hasHoursOnOrAfter($dayHours, $idleFromYmd)) {
                continue;
            }

            $toComplete[] = [
                'task' => $task,
                'last_assignment' => $lastAssignment,
                'project_completion_date' => $lastAssignment,
            ];
        }

        $preview = array_map(static function (array $row) {
            /** @var ProjectTask $task */
            $task = $row['task'];
            $project = $task->part?->project;

            return [
                'id' => (int) $task->id,
                'task_name' => $task->task_name,
                'project_id' => $project?->id,
                'project_name' => $project?->name,
                'doc_supply' => ReleaseAssignmentsAfterDone::toYmd($task->doc_supply),
                'last_assignment' => $row['last_assignment'],
                'project_completion_date' => $row['project_completion_date'],
            ];
        }, $toComplete);

        if ($dryRun || $toComplete === []) {
            return [
                'dry_run' => $dryRun,
                'scanned' => $candidateTasks->count(),
                'completed' => 0,
                'tasks' => $preview,
            ];
        }

        $completedIds = [];
        $projectIds = [];

        foreach ($toComplete as $row) {
            /** @var ProjectTask $task */
            $task = $row['task'];
            $completionDate = $row['project_completion_date'];

            $payload = TaskDoneProgressSync::applyToPayload([
                'done' => true,
                'progress_per' => '100',
                'plan' => $task->plan,
                'progress_plan' => $task->progress_plan,
                'progress_left_hours' => $task->progress_left_hours,
            ]);

            $updates = [
                'done' => true,
                'progress_per' => (string) ($payload['progress_per'] ?? '100'),
                'progress_left_hours' => (string) ($payload['progress_left_hours'] ?? '0'),
                'project_completion_date' => $completionDate,
                'progress_updated' => $completionDate,
            ];

            // Keep DL mirrors aligned when they already stamp done/progress/completion.
            if (self::hasDlStamp($task)) {
                $updates['dl_done'] = '1';
                $updates['dl_progress_per'] = $updates['progress_per'];
                $updates['dl_project_completion_date'] = $completionDate;
                $updates['dl_progress_updated'] = $completionDate;
            }

            $task->fill($updates);
            $task->save();

            $completedIds[] = (int) $task->id;
            $projectId = (int) ($task->part?->project_id ?? 0);
            if ($projectId > 0) {
                $projectIds[$projectId] = true;
            }
        }

        if ($completedIds !== []) {
            ReleaseAssignmentsAfterDone::apply(null, $completedIds);
            ResourceHistogramController::clearFullPayloadCache(null);

            Log::info('StaleOpenTaskAutoComplete completed tasks', [
                'count' => count($completedIds),
                'task_ids' => $completedIds,
                'started_days_ago' => $startedDaysAgo,
                'idle_lookback_days' => $idleLookbackDays,
                'idle_from' => $idleFromYmd,
            ]);
        }

        // Always flip fully-done projects off "active" (covers this batch and any
        // older tasks that were already done but never status-synced).
        self::syncFullyDoneActiveProjects($projectIds);

        return [
            'dry_run' => false,
            'scanned' => $candidateTasks->count(),
            'completed' => count($completedIds),
            'tasks' => $preview,
        ];
    }

    /**
     * Fire-and-forget for project-create hooks — never breaks the main request.
     */
    public static function runQuietly(
        int $startedDaysAgo = self::STARTED_DAYS_AGO,
        int $idleLookbackDays = self::IDLE_LOOKBACK_DAYS,
    ): void {
        try {
            self::run(false, $startedDaysAgo, $idleLookbackDays);
        } catch (Throwable $e) {
            Log::warning('StaleOpenTaskAutoComplete failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, float>> task_id => [ymd => hours]
     */
    private static function buildPositiveHoursByTask(array $taskIds): array
    {
        $ids = array_fill_keys(
            array_values(array_unique(array_filter(
                array_map('intval', $taskIds),
                fn ($id) => $id > 0,
            ))),
            true,
        );

        if ($ids === []) {
            return [];
        }

        $byTask = [];

        $cached = AlgorithmCache::get();
        $liveDetails = is_array($cached['assignmentDetails'] ?? null)
            ? $cached['assignmentDetails']
            : [];
        $sealedStored = Cache::get(AlgorithmSealedAssignments::CACHE_KEY);
        $sealedDetails = is_array($sealedStored['assignmentDetails'] ?? null)
            ? $sealedStored['assignmentDetails']
            : [];

        foreach (array_merge($liveDetails, $sealedDetails) as $detail) {
            $taskId = (int) ($detail['task_id'] ?? 0);
            if ($taskId <= 0 || ! isset($ids[$taskId])) {
                continue;
            }

            $assignments = is_array($detail['assignments'] ?? null) ? $detail['assignments'] : [];
            foreach ($assignments as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $ymd = ReleaseAssignmentsAfterDone::toYmd($row['date'] ?? null);
                $hours = is_numeric($row['hours'] ?? null) ? (float) $row['hours'] : 0.0;
                if ($ymd === null || $hours <= 0.0001) {
                    continue;
                }
                $byTask[$taskId][$ymd] = ($byTask[$taskId][$ymd] ?? 0.0) + $hours;
            }
        }

        $overrides = HistogramScheduleOverride::query()
            ->whereIn('task_id', array_keys($ids))
            ->get(['task_id', 'hours_by_day']);

        foreach ($overrides as $override) {
            $taskId = (int) $override->task_id;
            if ($taskId <= 0 || ! isset($ids[$taskId])) {
                continue;
            }

            $hoursByDay = is_array($override->hours_by_day) ? $override->hours_by_day : [];
            foreach ($hoursByDay as $day => $value) {
                $ymd = ReleaseAssignmentsAfterDone::toYmd($day);
                $hours = is_numeric($value) ? (float) $value : 0.0;
                if ($ymd === null || $hours <= 0.0001) {
                    continue;
                }
                $byTask[$taskId][$ymd] = ($byTask[$taskId][$ymd] ?? 0.0) + $hours;
            }
        }

        return $byTask;
    }

    /**
     * @param  array<string, float>  $dayHours
     */
    private static function hasHoursOnOrAfter(array $dayHours, string $fromYmd): bool
    {
        foreach ($dayHours as $ymd => $hours) {
            if ($hours > 0.0001 && $ymd >= $fromYmd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, float>  $dayHours
     */
    private static function lastAssignmentDay(array $dayHours): ?string
    {
        $last = null;
        foreach ($dayHours as $ymd => $hours) {
            if ($hours <= 0.0001) {
                continue;
            }
            if ($last === null || $ymd > $last) {
                $last = $ymd;
            }
        }

        return $last;
    }

    private static function hasDlStamp(ProjectTask $task): bool
    {
        foreach (['dl_done', 'dl_progress_per', 'dl_project_completion_date', 'dl_progress_updated'] as $key) {
            if (trim((string) ($task->{$key} ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, true>  $priorityProjectIds
     */
    private static function syncFullyDoneActiveProjects(array $priorityProjectIds = []): void
    {
        $query = Project::query()->where('status', 'active');

        if ($priorityProjectIds !== []) {
            // Prefer touched projects first, then sweep any other stale fully-done actives.
            $priority = Project::query()
                ->whereIn('id', array_keys($priorityProjectIds))
                ->get();
            foreach ($priority as $project) {
                ProjectStatusSync::syncFromTasks($project);
            }
        }

        $staleFullyDone = Project::query()
            ->where('status', 'active')
            ->whereHas('tasks')
            ->whereDoesntHave('tasks', function ($tasks) {
                $tasks->where(function ($open) {
                    $open->where('done', false)->orWhereNull('done');
                });
            })
            ->get();

        foreach ($staleFullyDone as $project) {
            ProjectStatusSync::syncFromTasks($project);
        }
    }
}
