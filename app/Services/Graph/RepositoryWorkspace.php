<?php

namespace App\Services\Graph;

use App\Models\Repository;
use App\Services\GitHub\GitHubApp;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * A persistent, shallow git checkout per repository, used as kodus-graph's --repo-dir.
 *
 * Commits are fetched one at a time with the installation token, which is passed through
 * GIT_CONFIG_* environment variables so it never lands in argv or .git/config. Hooks are
 * disabled; repository code is parsed, never executed.
 */
class RepositoryWorkspace
{
    public function __construct(private GitHubApp $github) {}

    public function path(Repository $repository): string
    {
        return rtrim((string) config('graph.workspace_path'), '/').'/'.$repository->id;
    }

    /**
     * Run a callback while holding the repository's workspace lock.
     *
     * @template TReturn
     *
     * @param  Closure(self): TReturn  $callback
     * @return TReturn
     */
    public function locked(Repository $repository, Closure $callback): mixed
    {
        return Cache::lock("workspace:{$repository->id}", (int) config('graph.parse_timeout') + 300)
            ->block((int) config('graph.parse_timeout'), fn () => $callback($this));
    }

    /**
     * Check out a commit (detached) and return the working directory. Call inside locked().
     */
    public function checkout(Repository $repository, string $sha): string
    {
        $directory = $this->path($repository);

        if (! is_dir($directory.'/.git')) {
            File::ensureDirectoryExists($directory);
            $this->git($repository, $directory, ['init', '--quiet']);
            $this->git($repository, $directory, ['remote', 'add', 'origin', $this->remoteUrl($repository)]);
        }

        $this->git($repository, $directory, ['fetch', '--quiet', '--depth=1', '--no-tags', '--no-recurse-submodules', 'origin', $sha]);
        $this->git($repository, $directory, ['checkout', '--quiet', '--force', '--detach', 'FETCH_HEAD']);
        $this->git($repository, $directory, ['clean', '--quiet', '-ffdx']);

        return $directory;
    }

    public function forget(Repository $repository): void
    {
        File::deleteDirectory($this->path($repository));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(Repository $repository, string $directory, array $arguments): ProcessResult
    {
        $result = Process::path($directory)
            ->env($this->environment($repository))
            ->timeout((int) config('graph.git_timeout'))
            ->run([config('graph.git_binary'), ...$arguments]);

        if ($result->failed()) {
            throw new GraphException('git '.$arguments[0].' failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function environment(Repository $repository): array
    {
        $token = $this->github->installationToken($repository->installation->installation_id);
        $host = rtrim((string) config('services.github.web_url'), '/').'/';

        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_LFS_SKIP_SMUDGE' => '1',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_COUNT' => '3',
            'GIT_CONFIG_KEY_0' => "http.{$host}.extraheader",
            'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.base64_encode("x-access-token:{$token}"),
            'GIT_CONFIG_KEY_1' => 'core.hooksPath',
            'GIT_CONFIG_VALUE_1' => '/dev/null',
            'GIT_CONFIG_KEY_2' => 'advice.detachedHead',
            'GIT_CONFIG_VALUE_2' => 'false',
        ];
    }

    private function remoteUrl(Repository $repository): string
    {
        return rtrim((string) config('services.github.web_url'), '/').'/'.$repository->full_name.'.git';
    }
}
