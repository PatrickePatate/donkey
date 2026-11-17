<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Writes storage/backups/donkey-<date>.tar.gz holding a pg_dump of the database and the storage files that matter
 * (storage/app, which includes the GitHub App key and the generated APP_KEY, and the Passport OAuth keys).
 */
#[Signature('backup:run {--keep=7 : Number of backups to keep, older ones are deleted}')]
#[Description('Back up the database and the storage files into storage/backups')]
class BackupRun extends Command
{
    /**
     * Paths under storage/ that are saved. Everything else there (logs, cache, sessions) can be rebuilt.
     *
     * @var list<string>
     */
    public const STORAGE_PATHS = ['app', 'oauth-private.key', 'oauth-public.key'];

    public function handle(): int
    {
        $directory = storage_path('backups');
        $name = 'donkey-'.now()->format('Y-m-d-His');
        $workDirectory = "{$directory}/{$name}";
        $archive = "{$workDirectory}.tar.gz";
        $database = config('database.connections.'.config('database.default'));

        File::ensureDirectoryExists($workDirectory, 0700);

        try {
            Process::env(['PGPASSWORD' => $database['password']])->timeout(3600)->run([
                'pg_dump', '--format=custom', '--no-owner', '--no-privileges',
                '--host='.$database['host'], '--port='.$database['port'], '--username='.$database['username'],
                '--file='.$workDirectory.'/database.dump', $database['database'],
            ])->throw();

            $storagePaths = array_values(array_filter(self::STORAGE_PATHS, fn (string $path): bool => file_exists(storage_path($path))));

            Process::timeout(3600)->run([
                'tar', '-czf', $archive,
                '-C', $workDirectory, 'database.dump',
                '-C', storage_path(), ...$storagePaths,
            ])->throw();
        } finally {
            File::deleteDirectory($workDirectory);
        }

        @chmod($archive, 0600);

        $this->info("Backup written to {$archive}.");

        if (!file_exists(storage_path('app/private/generated.env'))) {
            $this->warn('APP_KEY comes from your .env and is not in the backup: keep it, the AI API keys are encrypted with it.');
        }

        $this->deleteOldBackups($directory, max(1, (int) $this->option('keep')));

        return self::SUCCESS;
    }

    private function deleteOldBackups(string $directory, int $keep): void
    {
        // Names sort by date, so the oldest come first.
        $backups = collect(File::glob("{$directory}/donkey-*.tar.gz"))->sort()->values();

        $backups->slice(0, max(0, $backups->count() - $keep))->each(function (string $path): void {
            File::delete($path);
            $this->line('Deleted old backup '.basename($path).'.');
        });
    }
}
