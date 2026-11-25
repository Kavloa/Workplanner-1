<?php

namespace App\Console\Commands;

use App\Support\StaleOpenTaskAutoComplete;
use Illuminate\Console\Command;

class AutoCompleteStaleTasks extends Command
{
    protected $signature = 'tasks:auto-complete-stale
                            {--dry-run : List matching tasks without marking them done}
                            {--started-days=60 : Minimum age of doc_supply in days}
                            {--idle-days=3 : Require no assignment hours in this many recent days (and none after)}';

    protected $description = 'Auto-complete open tasks started long ago that have no recent/future assignment hours';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $startedDays = max(1, (int) $this->option('started-days'));
        $idleDays = max(1, (int) $this->option('idle-days'));

        $result = StaleOpenTaskAutoComplete::run($dryRun, $startedDays, $idleDays);

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info($prefix.'Stale open-task auto-complete finished.');
        $this->line('Scanned (started ≥ '.$startedDays.'d ago): '.$result['scanned']);
        $this->line(($dryRun ? 'Would complete: ' : 'Completed: ').count($result['tasks']));
        if (! $dryRun) {
            $this->line('Saved: '.$result['completed']);
        }

        if ($result['tasks'] === []) {
            $this->line('No matching tasks.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Task ID', 'Project', 'Task', 'Doc supply', 'Last assignment', 'Completion date'],
            collect($result['tasks'])->map(fn (array $row) => [
                $row['id'],
                ($row['project_name'] ?? '—').' (#'.($row['project_id'] ?? '?').')',
                $row['task_name'] ?? '—',
                $row['doc_supply'] ?? '—',
                $row['last_assignment'] ?? '(none)',
                $row['project_completion_date'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
