<?php

use App\Services\Review\FilePatch;

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
