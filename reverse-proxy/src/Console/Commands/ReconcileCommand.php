<?php

namespace Chromozone\ReverseProxy\Console\Commands;

use App\Models\Role;
use Chromozone\ReverseProxy\Models\ProxyTarget;
use Chromozone\ReverseProxy\Services\ReconcileService;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'p:reverse-proxy:reconcile
        {--repair : Recreate missing entries and rewrite drifted ones}
        {--prune : Delete plugin-owned proxy entries whose local route no longer exists}';

    protected $description = 'Compare proxy routes against the proxy manager and report or fix differences';

    public function handle(ReconcileService $reconciler): int
    {
        $targets = ProxyTarget::all();

        if ($targets->isEmpty()) {
            $this->warn('No proxy targets are configured.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        foreach ($targets as $target) {
            $this->info("Reconciling {$target->name} ({$target->base_url})");

            try {
                $report = $reconciler->reconcile(
                    $target,
                    (bool) $this->option('repair'),
                    (bool) $this->option('prune'),
                );
            } catch (Exception $exception) {
                $this->error('  ' . $exception->getMessage());
                $exitCode = self::FAILURE;

                continue;
            }

            foreach (['detached', 'missing', 'drifted', 'duplicated', 'orphaned', 'foreign', 'repaired', 'pruned'] as $section) {
                foreach ($report[$section] as $line) {
                    $this->line(sprintf('  [%s] %s', $section, $line));
                }
            }

            foreach ($report['errors'] as $line) {
                $this->error('  [error] ' . $line);
            }

            if (array_sum(array_map('count', $report)) === 0) {
                $this->line('  in sync');
            }

            if ($report['detached'] !== []) {
                $this->warn('  detached routes point at ports their server no longer owns; --repair deletes them');
            }

            if ($report['foreign'] !== []) {
                $this->warn('  foreign entries belong to another panel sharing this proxy manager and are never touched');
            }

            if (!$this->option('repair') && ($report['missing'] !== [] || $report['drifted'] !== [] || $report['detached'] !== [])) {
                $this->comment('  re-run with --repair to fix the above');
            }

            if (!$this->option('prune') && ($report['orphaned'] !== [] || $report['duplicated'] !== [])) {
                $this->comment('  re-run with --prune to delete the orphaned and duplicate entries above');
            }

            if ($this->hasUnresolvedWork($report)) {
                $exitCode = self::FAILURE;
            }

            $this->alertAdmins($target->name, $report);
        }

        return $exitCode;
    }

    /**
     * The scheduled run is otherwise completely silent, so problems that need a
     * human would sit in cron output nobody reads. Only genuinely actionable
     * states notify: routine drift that repair already fixed does not.
     *
     * @param  array<string, list<string>>  $report
     */
    private function alertAdmins(string $targetName, array $report): void
    {
        $problems = array_merge($report['errors'], $report['detached']);

        if ($problems === []) {
            return;
        }

        $admins = Role::getRootAdmin()->users;

        if ($admins->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(trans('reverse-proxy::strings.reconcile_alert'))
            ->body($targetName . ': ' . implode('; ', array_slice($problems, 0, 5))
                . (count($problems) > 5 ? ' (+' . (count($problems) - 5) . ' more)' : ''))
            ->warning()
            ->sendToDatabase($admins);
    }

    /**
     * Whether anything actionable was left unresolved, so a cron watching exit
     * codes learns about drift instead of only about transport errors. In repair
     * or prune mode the corresponding categories are expected to have been dealt
     * with, so only genuine failures count.
     *
     * @param  array<string, list<string>>  $report
     */
    private function hasUnresolvedWork(array $report): bool
    {
        if ($report['errors'] !== []) {
            return true;
        }

        if (!$this->option('repair') && ($report['detached'] !== [] || $report['missing'] !== [] || $report['drifted'] !== [])) {
            return true;
        }

        return !$this->option('prune') && ($report['orphaned'] !== [] || $report['duplicated'] !== []);
    }
}
