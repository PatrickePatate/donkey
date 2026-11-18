<?php

use App\Services\Review\FilePatch;
use App\Services\Review\ReviewContext;

const SAMPLE_PATCH = <<<'PATCH'
@@ -10,4 +10,5 @@ class UserService
     public function find(int $id): User
     {
-        return User::find($id);
+        $user = User::find($id);
+        return $user;
     }
@@ -40,2 +41,3 @@ class UserService
     // end
+    // new trailing line
 }
PATCH;

it('maps new-file line numbers for added and context lines', function () {
    $patch = FilePatch::fromGitHub('app/UserService.php', 'modified', SAMPLE_PATCH);

    expect($patch->commentableLines)->toHaveKeys([10, 11, 12, 13, 14, 41, 42, 43])
        ->and($patch->commentableLines[12])->toBe('        $user = User::find($id);')
        ->and($patch->addedLines)->toBe([12, 13, 42]);
});

it('only allows ranges inside a single hunk', function () {
    $patch = FilePatch::fromGitHub('app/UserService.php', 'modified', SAMPLE_PATCH);

    expect($patch->canCommentRange(12, 13))->toBeTrue()
        ->and($patch->canCommentRange(13, 42))->toBeFalse()
        ->and($patch->canComment(20))->toBeFalse()
        ->and($patch->canCommentRange(13, 12))->toBeFalse();
});

it('annotates the diff with new-file line numbers', function () {
    $annotated = FilePatch::fromGitHub('app/UserService.php', 'modified', SAMPLE_PATCH)->annotated();

    expect($annotated)
        ->toContain('   12 +         $user = User::find($id);')
        ->toContain('      -         return User::find($id);')
        ->toContain('   42 +     // new trailing line');
});

it('counts added, removed and comment lines across the reviewed files', function () {
    $context = new ReviewContext('base', false, ['app/UserService.php' => FilePatch::fromGitHub('app/UserService.php', 'modified', SAMPLE_PATCH)], [], []);

    expect($context->diffMetrics())->toBe(['lines_added' => 3, 'lines_removed' => 1, 'comment_lines' => 1]);
});
