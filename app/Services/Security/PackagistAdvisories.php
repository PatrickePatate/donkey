<?php

namespace App\Services\Security;

use Composer\Semver\Semver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Finds the Packagist security advisories that affect the packages locked in a composer.lock.
 */
class PackagistAdvisories
{
    private const ENDPOINT = 'https://packagist.org/api/security-advisories/';

    /**
     * @return list<array{name: string, version: string, dev: bool}>
     */
    public function lockedPackages(string $composerLock): array
    {
        $lock = json_decode($composerLock, true, flags: JSON_THROW_ON_ERROR);
        $packages = [];

        foreach (['packages' => false, 'packages-dev' => true] as $key => $dev) {
            foreach ($lock[$key] ?? [] as $package) {
                if (is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                    $packages[] = ['name' => strtolower($package['name']), 'version' => $package['version'], 'dev' => $dev];
                }
            }
        }

        return $packages;
    }

    /**
     * @param  list<array{name: string, version: string, dev: bool}>  $packages
     * @return list<array{package: string, installed_version: string, is_dev: bool, advisory_id: string, title: string, cve: ?string, link: ?string, severity: ?string, affected_versions: string, reported_at: ?string}>
     */
    public function affecting(array $packages): array
    {
        $matches = [];
        $byName = collect($packages)->keyBy('name');

        foreach ($byName->keys()->chunk(200) as $names) {
            $advisories = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->retry(2, 1000)
                ->post(self::ENDPOINT, ['packages' => $names->values()->all()])
                ->throw()
                ->json('advisories', []);

            foreach ($advisories as $name => $list) {
                $package = $byName->get(strtolower((string) $name));

                foreach ($package === null ? [] : $list as $advisory) {
                    if (!$this->isAffected($package['version'], (string) ($advisory['affectedVersions'] ?? ''))) {
                        continue;
                    }

                    $matches[] = [
                        'package' => $package['name'],
                        'installed_version' => $package['version'],
                        'is_dev' => $package['dev'],
                        'advisory_id' => (string) $advisory['advisoryId'],
                        'title' => mb_substr((string) ($advisory['title'] ?? 'Untitled advisory'), 0, 255),
                        'cve' => $advisory['cve'] ?? null,
                        'link' => isset($advisory['link']) ? mb_substr((string) $advisory['link'], 0, 500) : null,
                        'severity' => in_array($advisory['severity'] ?? null, ['critical', 'high', 'medium', 'low'], true) ? $advisory['severity'] : null,
                        'affected_versions' => (string) $advisory['affectedVersions'],
                        'reported_at' => $advisory['reportedAt'] ?? null,
                    ];
                }
            }
        }

        return $matches;
    }

    private function isAffected(string $version, string $constraint): bool
    {
        if ($constraint === '') {
            return false;
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            // shortcut: branch versions (dev-main) can't be compared, so they are never reported.
            return false;
        }
    }
}
