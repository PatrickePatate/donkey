<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Restores an archive written by backup:run: replaces the database contents and copies the saved storage files back.
 */
#[Signature('backup:restore
    {file : Path to a donkey-*.tar.gz backup, or its name in storage/backups or on the backup disk}
    {--force : Do not ask for confirmation}')]
#[Description('Restore the database and storage files from a backup made by backup:run')]
class BackupRestore extends Command
{
    public function handle(): int
    {
        $archive = $this->argument('file');

        if (!is_file($archive)) {
            $archive = storage_path('backups/'.basename($archive));
        }

        if (!is_file($archive)) {
            $this->downloadFromBackupDisk(basename($archive), $archive);
        }

        if (!is_file($archive)) {
            $this->error("No backup found at {$this->argument('file')}.");

            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm('This replaces the current database and storage files. Continue?')) {
            return self::FAILURE;
        }

        $workDirectory = storage_path('backups/restore-'.now()->format('YmdHis'));
        $database = config('database.connections.'.config('database.default'));

        File::ensureDirectoryExists($workDirectory, 0700);

        try {
            Process::timeout(3600)->run(['tar', '-xzf', $archive, '-C', $workDirectory])->throw();

            Process::env(['PGPASSWORD' => $database['password']])->timeout(3600)->run([
                'pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error',
                '--host='.$database['host'], '--port='.$database['port'], '--username='.$database['username'],
                '--dbname='.$database['database'], $workDirectory.'/database.dump',
            ])->throw();

            $this->restoreStorageFiles($workDirectory);
        } finally {
            File::deleteDirectory($workDirectory);
        }

        $this->info('Backup restored. Restart Donkey so it picks up the restored keys (docker compose restart).');

        return self::SUCCESS;
    }

    private function downloadFromBackupDisk(string $name, string $target): void
    {
        $diskName = config('filesystems.backup_disk');

        if (!$diskName || !Storage::disk($diskName)->exists("backups/{$name}")) {
            return;
        }

        $this->line("Downloading {$name} from the {$diskName} disk.");
        File::ensureDirectoryExists(dirname($target), 0700);
        $stream = Storage::disk($diskName)->readStream("backups/{$name}");
        file_put_contents($target, $stream);
        fclose($stream);
        chmod($target, 0600);
    }

    private function restoreStorageFiles(string $workDirectory): void
    {
        foreach (BackupRun::STORAGE_PATHS as $path) {
            $source = "{$workDirectory}/{$path}";

            if (is_file($source)) {
                $this->copyFile($source, storage_path($path));
            } elseif (is_dir($source)) {
                collect(File::allFiles($source, hidden: true))->each(
                    fn (SplFileInfo $file) => $this->copyFile($file->getPathname(), storage_path($path.'/'.$file->getRelativePathname())),
                );
            }
        }
    }

    private function copyFile(string $source, string $target): void
    {
        // e.g. the GitHub App key, mounted read-only from the host in the Docker stack.
        if (file_exists($target) && !is_writable($target)) {
            $this->warn("Skipped {$target}: not writable.");

            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::copy($source, $target);
        chmod($target, fileperms($source) & 0777);
    }
}
