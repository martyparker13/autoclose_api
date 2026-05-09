<?php

namespace App\Console\Commands;

use App\Models\DealerSyncRun;
use App\Models\IntegrationWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckIntegrationHealthCommand extends Command
{
    protected $signature = 'integrations:health-check {--minutes=30 : Sliding window in minutes}';

    protected $description = 'Checks integration failures and emits alerts for webhook/sync issues';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $since = now()->subMinutes($minutes);

        $failedWebhooks = IntegrationWebhookEvent::query()
            ->where('status', 'failed')
            ->where('last_seen_at', '>=', $since)
            ->count();

        $failedSyncRuns = DealerSyncRun::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', $since)
            ->count();

        $syncRunsWithErrors = DealerSyncRun::query()
            ->where('error_count', '>', 0)
            ->where('updated_at', '>=', $since)
            ->count();

        if ($failedWebhooks === 0 && $failedSyncRuns === 0 && $syncRunsWithErrors === 0) {
            $this->info('Integration health check passed.');
            return self::SUCCESS;
        }

        $context = [
            'minutes' => $minutes,
            'failed_webhooks' => $failedWebhooks,
            'failed_sync_runs' => $failedSyncRuns,
            'sync_runs_with_errors' => $syncRunsWithErrors,
        ];

        Log::critical('Integration health check detected failures.', $context);

        if (config('logging.channels.slack.url')) {
            try {
                Log::channel('slack')->critical('Integration health check detected failures.', $context);
            } catch (\Throwable $e) {
                Log::error('Failed to emit integration health alert to Slack channel.', ['error' => $e->getMessage()]);
            }
        }

        $this->warn('Integration health check detected failures.');
        $this->line(json_encode($context, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
