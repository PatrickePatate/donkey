<?php

use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubUserAuthorization;

/**
 * Point the GitHub App config at a freshly generated RSA key so JWT signing works in tests.
 */
function configureGitHubApp(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    $path = tempnam(sys_get_temp_dir(), 'github-app-key');
    file_put_contents($path, $pem);

    config([
        'services.github.app_id' => '12345',
        'services.github.app_slug' => 'donkey-test',
        'services.github.client_id' => 'client-id',
        'services.github.client_secret' => 'client-secret',
        'services.github.private_key_path' => $path,
    ]);

    app()->forgetInstance(GitHubApp::class);
    app()->forgetInstance(GitHubUserAuthorization::class);
}
