<?php

namespace App\Actions;

use App\Jobs\GenerateRepositoryDocumentation;
use App\Models\Repository;
use App\Services\Audit\AuditLog;

/**
 * Turns automatic documentation on or off, and generates it right away when there is none yet.
 */
class SetAutoDocs
{
    public function __construct(private AuditLog $auditLog)
    {
    }

    /**
     * @return bool Whether anything changed.
     */
    public function handle(Repository $repository, bool $enabled): bool
    {
        if ($repository->auto_docs_enabled === $enabled) {
            return false;
        }

        $repository->update(['auto_docs_enabled' => $enabled]);
        $this->auditLog->record($repository->organization, 'repository.auto_docs_changed', $repository, AuditLog::changes($repository, ['auto_docs_enabled']));

        if ($enabled && !$repository->document()->whereNotNull('content')->exists()) {
            GenerateRepositoryDocumentation::dispatchFor($repository, immediate: true);
        }

        return true;
    }
}
