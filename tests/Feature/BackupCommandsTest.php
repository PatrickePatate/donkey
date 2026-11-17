<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/donkey-backup-test-'.uniqid();
    File::ensureDirectoryExists($this->storage.'/app/private');
    app()->useStoragePath($this->storage);
});

afterEach(fn () => File::deleteDirectory($this->storage));

test('backup dumps the database and archives the storage files that matter', function () {
    Process::fake();
    File::put($this->storage.'/oauth-private.key', 'key');

    $this->artisan('backup:run')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'pg_dump'
        && in_array('--format=custom', $process->command)
        && $process->environment['PGPASSWORD'] === config('database.connections.pgsql.password'));
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'tar'
        && array_slice($process->command, -2) === ['app', 'oauth-private.key']);
    expect(File::directories($this->storage.'/backups'))->toBeEmpty();
});

test('backup keeps only the most recent archives', function () {
    Process::fake();
    File::ensureDirectoryExists($this->storage.'/backups');
    foreach (['2026-01-01-010000', '2026-01-02-010000', '2026-01-03-010000'] as $date) {
        File::put($this->storage."/backups/donkey-{$date}.tar.gz", '');
    }

    $this->artisan('backup:run', ['--keep' => 2])->assertSuccessful();

    expect(File::exists($this->storage.'/backups/donkey-2026-01-01-010000.tar.gz'))->toBeFalse()
        ->and(File::exists($this->storage.'/backups/donkey-2026-01-02-010000.tar.gz'))->toBeTrue()
        ->and(File::exists($this->storage.'/backups/donkey-2026-01-03-010000.tar.gz'))->toBeTrue();
});

test('backup fails and leaves no archive when pg_dump fails', function () {
    Process::fake(fn (PendingProcess $process) => $process->command[0] === 'pg_dump'
        ? Process::result(errorOutput: 'connection refused', exitCode: 1)
        : Process::result());

    expect(fn () => $this->artisan('backup:run')->run())->toThrow(RuntimeException::class);

    Process::assertDidntRun(fn (PendingProcess $process) => $process->command[0] === 'tar');
    expect(File::glob($this->storage.'/backups/*'))->toBeEmpty();
});

test('restore loads the dump and copies the storage files back', function () {
    File::ensureDirectoryExists($this->storage.'/backups');
    File::put($this->storage.'/backups/donkey-2026-01-01-010000.tar.gz', '');
    File::put($this->storage.'/app/private/generated.env', 'APP_KEY=new');

    Process::fake(function (PendingProcess $process) {
        if ($process->command[0] === 'tar') {
            $target = $process->command[4];
            File::ensureDirectoryExists($target.'/app/private');
            File::put($target.'/app/private/generated.env', 'APP_KEY=old');
            File::put($target.'/oauth-private.key', 'key');
            File::put($target.'/database.dump', '');
        }

        return Process::result();
    });

    $this->artisan('backup:restore', ['file' => 'donkey-2026-01-01-010000.tar.gz', '--force' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'pg_restore' && in_array('--clean', $process->command));
    expect(File::get($this->storage.'/app/private/generated.env'))->toBe('APP_KEY=old')
        ->and(File::get($this->storage.'/oauth-private.key'))->toBe('key')
        ->and(File::directories($this->storage.'/backups'))->toBeEmpty();
});

test('restore refuses a missing backup', function () {
    Process::fake();

    $this->artisan('backup:restore', ['file' => 'nope.tar.gz', '--force' => true])->assertFailed();

    Process::assertNothingRan();
});
