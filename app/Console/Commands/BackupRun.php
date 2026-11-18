<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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

    /**
     * Rebuildable paths left out of the archive: repository clones (gigabytes) and upload temp files.
     *
     * @var list<string>
     */
    public const EXCLUDED_PATHS = ['app/private/workspaces', 'app/private/tmp', 'app/private/livewire-tmp'];

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
                ...array_map(fn (string $path): string => "--exclude={$path}", self::EXCLUDED_PATHS),
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

        $keep = max(1, (int) $this->option('keep'));
        $this->deleteOldBackups(Storage::build(['driver' => 'local', 'root' => $directory]), '', $keep);

        if ($diskName = config('filesystems.backup_disk')) {
            $disk = Storage::disk($diskName);
            $stream = fopen($archive, 'r');

            try {
                if (!$disk->writeStream('backups/'.basename($archive), $stream)) {
                    throw new RuntimeException("Could not copy the backup to the {$diskName} disk.");
                }
            } finally {
                fclose($stream);
            }

            $this->info("Backup copied to the {$diskName} disk.");
            $this->deleteOldBackups($disk, 'backups', $keep);
        }

        return self::SUCCESS;
    }

    private function deleteOldBackups(Filesystem $disk, string $directory, int $keep): void
    {
        // Names sort by date, so the oldest come first.
        $backups = collect($disk->files($directory))
            ->filter(fn (string $path): bool => (bool) preg_match('/^donkey-.*\.tar\.gz$/', basename($path)))
            ->sort()
            ->values();

        $backups->slice(0, max(0, $backups->count() - $keep))->each(function (string $path) use ($disk): void {
            $disk->delete($path);
            $this->line('Deleted old backup '.basename($path).'.');
        });
    }
}
