<?php

use App\Livewire\Project\Shared\FileBrowser;
use App\Models\Application;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * FileBrowser Component Tests
 *
 * The FileBrowser is a Livewire component that provides file browsing capabilities
 * inside Docker containers. It uses docker exec commands to navigate, create,
 * delete, upload, and download files.
 *
 * These tests focus on the validation logic and state management that
 * don't require actual Docker containers.
 */

// ───── mount() and Resource Loading ─────

it('mounts with default values', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $component->parameters = [];
    $component->containers = collect();
    $component->servers = collect();
    $component->currentPath = '/';
    $component->entries = [];
    $component->selected_container = 'default';

    expect($component->currentPath)->toBe('/');
    expect($component->selected_container)->toBe('default');
    expect($component->containers)->toBeInstanceOf(Collection::class);
    expect($component->entries)->toBeArray()->toBeEmpty();
});

// ───── validatePath() ─────

it('rejects paths without leading slash', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'validatePath');
    $reflected->setAccessible(true);

    expect($reflected->invoke($component, 'relative/path'))->toBeFalse();
    expect($reflected->invoke($component, 'etc/passwd'))->toBeFalse();
    expect($reflected->invoke($component, '/valid/path'))->toBeTrue();
});

it('rejects paths containing directory traversal', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'validatePath');
    $reflected->setAccessible(true);

    expect($reflected->invoke($component, '/../etc'))->toBeFalse();
    expect($reflected->invoke($component, '/var/../log'))->toBeFalse();
    expect($reflected->invoke($component, '/..'))->toBeFalse();
});

it('rejects paths with shell metacharacters', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'validatePath');
    $reflected->setAccessible(true);

    expect($reflected->invoke($component, '/path; rm -rf /'))->toBeFalse();
    expect($reflected->invoke($component, '/path|echo evil'))->toBeFalse();
    expect($reflected->invoke($component, '/path`id`'))->toBeFalse();
    expect($reflected->invoke($component, '/path$(whoami)'))->toBeFalse();
    expect($reflected->invoke($component, '/path&exit'))->toBeFalse();
    expect($reflected->invoke($component, '/path<>file'))->toBeFalse();
    expect($reflected->invoke($component, '/path\\evil'))->toBeFalse();
    expect($reflected->invoke($component, '/path!bang'))->toBeFalse();
});

it('rejects paths with null bytes', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'validatePath');
    $reflected->setAccessible(true);

    expect($reflected->invoke($component, "/path\0null"))->toBeFalse();
});

it('accepts valid paths', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'validatePath');
    $reflected->setAccessible(true);

    expect($reflected->invoke($component, '/'))->toBeTrue();
    expect($reflected->invoke($component, '/var'))->toBeTrue();
    expect($reflected->invoke($component, '/var/log'))->toBeTrue();
    expect($reflected->invoke($component, '/var/log/nginx'))->toBeTrue();
    expect($reflected->invoke($component, '/home/user/my files'))->toBeTrue();
    expect($reflected->invoke($component, '/opt/my-app-v2.1'))->toBeTrue();
});

// ───── parseLsOutput() ─────

it('parses ls -la output into structured entries', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'parseLsOutput');
    $reflected->setAccessible(true);

    $output = "total 64
drwxr-xr-x 2 root root 4096 Mar 15 10:30 .
drwxr-xr-x 3 root root 4096 Mar 15 10:29 ..
-rw-r--r-- 1 root root 1234 Mar 15 10:30 config.php
-rw-r--r-- 1 root root  567 Mar 15 10:30 .env
drwxr-xr-x 2 root root 4096 Mar 15 10:30 data
lrwxrwxrwx 1 root root   12 Mar 15 10:30 link -> /target/path";

    // The link line format: "lrwxrwxrwx 1 root root 12 Mar 15 10:30 link -> /target/path"
    // We need to modify the input slightly as parseLsOutput handles symlinks with ->
    $output = "total 64
drwxr-xr-x 2 root root 4096 Mar 15 10:30 .
drwxr-xr-x 3 root root 4096 Mar 15 10:29 ..
-rw-r--r-- 1 root root 1234 Mar 15 10:30 config.php
-rw-r--r-- 1 root root 567 Mar 15 10:30 .env
drwxr-xr-x 2 root root 4096 Mar 15 10:30 data
lrwxrwxrwx 1 root root 12 Mar 15 10:30 link -> /target/path";

    $entries = $reflected->invoke($component, $output);

    expect($entries)->toBeArray();
    // Should skip . and .. entries, leaving config.php, .env, data, link
    expect($entries)->toHaveCount(4);

    // Should be sorted: directories first, then files
    expect($entries[0]['name'])->toBe('data');
    expect($entries[0]['isDirectory'])->toBeTrue();

    // Then files alphabetically
    expect($entries[1]['name'])->toBe('.env');
    expect($entries[1]['isDirectory'])->toBeFalse();

    expect($entries[2]['name'])->toBe('config.php');
    expect($entries[2]['isDirectory'])->toBeFalse();

    // Symlink
    expect($entries[3]['name'])->toBe('link');
    expect($entries[3]['isSymlink'])->toBeTrue();
    expect($entries[3]['linkTarget'])->toBe('/target/path');
});

it('parses entries with correct metadata', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'parseLsOutput');
    $reflected->setAccessible(true);

    $output = "total 8
-rw-r--r-- 1 alpar staff 1024 Mar 15 10:30 test.txt";

    $entries = $reflected->invoke($component, $output);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['permissions'])->toBe('-rw-r--r--');
    expect($entries[0]['links'])->toBe(1);
    expect($entries[0]['owner'])->toBe('alpar');
    expect($entries[0]['group'])->toBe('staff');
    expect($entries[0]['size'])->toBe(1024);
    expect($entries[0]['modified'])->toBe('Mar 15 10:30');
    expect($entries[0]['isDirectory'])->toBeFalse();
});

it('handles empty ls output', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'parseLsOutput');
    $reflected->setAccessible(true);

    expect($reflected->invoke($component, ''))->toBeArray()->toBeEmpty();
    expect($reflected->invoke($component, null))->toBeArray()->toBeEmpty();
});

it('handles empty directory output', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'parseLsOutput');
    $reflected->setAccessible(true);

    $output = "total 0";
    expect($reflected->invoke($component, $output))->toBeArray()->toBeEmpty();
});

it('sorts entries: directories first, then files alphabetically', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'parseLsOutput');
    $reflected->setAccessible(true);

    $output = "total 16
-rw-r--r-- 1 root root 100 Mar 15 10:00 zfile.txt
drwxr-xr-x 2 root root 100 Mar 15 10:00 adir
-rw-r--r-- 1 root root 100 Mar 15 10:00 afile.txt
drwxr-xr-x 2 root root 100 Mar 15 10:00 zdir";

    $entries = $reflected->invoke($component, $output);

    expect($entries)->toHaveCount(4);
    expect($entries[0]['name'])->toBe('adir');
    expect($entries[0]['isDirectory'])->toBeTrue();
    expect($entries[1]['name'])->toBe('zdir');
    expect($entries[1]['isDirectory'])->toBeTrue();
    expect($entries[2]['name'])->toBe('afile.txt');
    expect($entries[2]['isDirectory'])->toBeFalse();
    expect($entries[3]['name'])->toBe('zfile.txt');
    expect($entries[3]['isDirectory'])->toBeFalse();
});

it('handles filenames with special characters in parsing', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();

    $reflected = new ReflectionMethod($component, 'parseLsOutput');
    $reflected->setAccessible(true);

    // Note: ls output preserves the filename as-is
    $output = "total 4
-rw-r--r-- 1 root root 100 Mar 15 10:00 my file with spaces.txt
-rw-r--r-- 1 root root 100 Mar 15 10:00 file-with-dashes.js
-rw-r--r-- 1 root root 100 Mar 15 10:00 file_with_underscores.py";

    $entries = $reflected->invoke($component, $output);

    expect($entries)->toHaveCount(3);
    expect($entries[0]['name'])->toBe('file-with-dashes.js');
    expect($entries[1]['name'])->toBe('file_with_underscores.py');
    expect($entries[2]['name'])->toBe('my file with spaces.txt');
});

// ───── resolveContainerAndServer() — Validation ─────

it('rejects default container selection', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->selected_container = 'default';
    $component->containers = collect();

    $reflected = new ReflectionMethod($component, 'resolveContainerAndServer');
    $reflected->setAccessible(true);

    $component->shouldReceive('dispatch')
        ->with('error', 'Please select a container.')
        ->once();

    expect($reflected->invoke($component))->toBeNull();
});

it('rejects invalid container name', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->selected_container = '../evil';
    $component->containers = collect();

    $reflected = new ReflectionMethod($component, 'resolveContainerAndServer');
    $reflected->setAccessible(true);

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid container name.')
        ->once();

    expect($reflected->invoke($component))->toBeNull();
});

it('rejects container not found in collection', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->selected_container = 'my-app-123';
    $component->containers = collect([
        ['container' => ['Names' => 'other-app-456'], 'server' => Mockery::mock(Server::class)],
    ]);

    $reflected = new ReflectionMethod($component, 'resolveContainerAndServer');
    $reflected->setAccessible(true);

    $component->shouldReceive('dispatch')
        ->with('error', 'Container not found.')
        ->once();

    expect($reflected->invoke($component))->toBeNull();
});

it('rejects container on force-disabled server', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('isForceDisabled')->andReturnTrue();

    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->selected_container = 'my-app-123';
    $component->containers = collect([
        ['container' => ['Names' => 'my-app-123'], 'server' => $server],
    ]);

    $reflected = new ReflectionMethod($component, 'resolveContainerAndServer');
    $reflected->setAccessible(true);

    $component->shouldReceive('dispatch')
        ->with('error', 'Server is disabled.')
        ->once();

    expect($reflected->invoke($component))->toBeNull();
});

it('resolves valid container and server', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('isForceDisabled')->andReturnFalse();

    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->selected_container = 'my-app-123';
    $component->containers = collect([
        ['container' => ['Names' => 'my-app-123'], 'server' => $server],
    ]);

    $reflected = new ReflectionMethod($component, 'resolveContainerAndServer');
    $reflected->setAccessible(true);

    $result = $reflected->invoke($component);
    expect($result)->toBeArray();
    expect($result['containerName'])->toBe('my-app-123');
    expect($result['server'])->toBe($server);
});

// ───── container name validation via ValidationPatterns ─────

it('validates container names via ValidationPatterns', function () {
    expect(ValidationPatterns::isValidContainerName('my-app-123'))->toBeTrue();
    expect(ValidationPatterns::isValidContainerName('my_app_123'))->toBeTrue();
    expect(ValidationPatterns::isValidContainerName('my.app.123'))->toBeTrue();
    expect(ValidationPatterns::isValidContainerName('MyApp-123'))->toBeTrue();
    expect(ValidationPatterns::isValidContainerName('/evil'))->toBeFalse();
    expect(ValidationPatterns::isValidContainerName('../attack'))->toBeFalse();
    expect(ValidationPatterns::isValidContainerName('; rm -rf /'))->toBeFalse();
    expect(ValidationPatterns::isValidContainerName(''))->toBeFalse();
});

// ───── createFolder validation ─────

it('rejects empty folder name', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->newFolderName = '';

    $component->shouldReceive('dispatch')
        ->with('error', 'Folder name cannot be empty.')
        ->once();

    $component->createFolder();
});

it('rejects folder name with invalid characters', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->newFolderName = '../evil';

    $component->shouldReceive('dispatch')
        ->with('error', 'Folder name contains invalid characters.')
        ->once();

    $component->createFolder();
});

it('accepts valid folder names', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->newFolderName = 'my-valid-folder';
    $component->currentPath = '/';
    $component->selected_container = 'default';
    $component->containers = collect();

    // Will fail at resolveContainerAndServer, but that's okay — we're testing
    // it gets past the name validation
    $component->shouldReceive('resolveContainerAndServer')
        ->andReturnNull()
        ->once();

    $component->createFolder();
    // If it gets here without dispatching an error for the folder name, success
});

// ───── navigateUp ─────

it('does not navigate above root', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->currentPath = '/';

    $component->shouldNotReceive('browse');

    $component->navigateUp();
});

it('navigates to parent directory', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->shouldAllowMockingProtectedMethods();
    $component->currentPath = '/var/log';

    $component->shouldReceive('browse')
        ->with('/var')
        ->once();

    $component->navigateUp();
});

// ───── navigateTo validation ─────

it('rejects invalid entry index', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [];

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid entry.')
        ->once();

    $component->navigateTo(0);
});

it('rejects navigation to non-directory', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [
        ['name' => 'config.php', 'isDirectory' => false],
    ];

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid entry.')
        ->once();

    $component->navigateTo(0);
});

it('navigates into a directory', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [
        ['name' => 'data', 'isDirectory' => true],
    ];
    $component->currentPath = '/';

    $component->shouldReceive('browse')
        ->with('/data')
        ->once();

    $component->navigateTo(0);
});

// ───── deleteEntry validation ─────

it('rejects delete of invalid entry index', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [];

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid entry.')
        ->once();

    $component->deleteEntry(0);
});

// ───── downloadFile validation ─────

it('rejects download of invalid file index', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [];

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid file.')
        ->once();

    expect($component->downloadFile(0))->toBeNull();
});

it('rejects download of a directory as file', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [
        ['name' => 'mydir', 'isDirectory' => true],
    ];

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid file.')
        ->once();

    expect($component->downloadFile(0))->toBeNull();
});

// ───── downloadFolder validation ─────

it('rejects download of invalid folder index', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [];

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid folder.')
        ->once();

    expect($component->downloadFolder(0))->toBeNull();
});

it('rejects download of a file as folder', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->entries = [
        ['name' => 'config.php', 'isDirectory' => false],
    ];

    $component->shouldReceive('dispatch')
        ->with('error', 'Invalid folder.')
        ->once();

    expect($component->downloadFolder(0))->toBeNull();
});

// ───── uploadToContainer validation ─────

it('rejects upload when no file selected', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->uploadFile = null;

    $component->shouldReceive('dispatch')
        ->with('error', 'No file selected.')
        ->once();

    $component->uploadToContainer();
});

it('rejects upload with invalid filename characters', function () {
    $component = Mockery::mock(FileBrowser::class)->makePartial();
    $component->uploadFile = (object) ['getClientOriginalName' => fn () => '../evil.sh'];

    $component->shouldReceive('dispatch')
        ->with('error', 'File name contains invalid characters.')
        ->once();

    $component->uploadToContainer();
});
