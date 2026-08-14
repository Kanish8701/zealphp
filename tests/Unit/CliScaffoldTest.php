<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Functional tests for the `zealphp` CLI script.
 *
 * The script is a top-level executable (not a class), and it calls exit()
 * at several points. Rather than `require`-ing it in-process (which would
 * kill the PHPUnit process), each test runs it as a real subprocess via
 * proc_open() inside a throwaway temp directory, then asserts on stdout,
 * stderr, and the exit code.
 */
final class CliScaffoldTest extends TestCase
{
    private static string $scriptPath;
    private string $cwd;

    public static function setUpBeforeClass(): void
    {
        self::$scriptPath = dirname(__DIR__, 2) . '/bin/zealphp';

        if (!is_file(self::$scriptPath)) {
            self::fail('Could not find the zealphp script at ' . self::$scriptPath);
        }
    }

    protected function setUp(): void
    {
        // Fresh, empty working directory for every test so tests can't
        // see each other's composer.json / project folders.
        $this->cwd = sys_get_temp_dir() . '/zealphp-test-' . uniqid('', true);
        mkdir($this->cwd, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cwd);
    }

    /**
     * Run `php bin/zealphp <args>` inside $this->cwd.
     *
     * @param string[] $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runCli(array $args, ?string $stdin = null): array
    {
        $command = array_merge(['php', self::$scriptPath], $args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->cwd);

        if (!is_resource($process)) {
            self::fail('Failed to start zealphp process.');
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [
            'stdout' => $this->stripAnsi($stdout),
            'stderr' => $this->stripAnsi($stderr),
            'exit'   => $exit,
        ];
    }

    /**
     * The script colors its output with raw ANSI escape sequences, often
     * wrapping just the dynamic part of a message (e.g. the command name
     * in "Unknown command: <esc>[1mfoo<esc>[0m"). Stripping escape codes
     * before asserting keeps tests readable and immune to color changes.
     */
    private function stripAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $text) ?? $text;
    }

    private function writeComposerJson(array $data): void
    {
        file_put_contents($this->cwd . '/composer.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    // ── No command ──────────────────────────────────────────────────────

    public function testNoCommandPrintsWelcomeMessageAndExitsZero(): void
    {
        $result = $this->runCli([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Welcome to ZealPHP', $result['stdout']);
    }

    // ── Unknown command handling ────────────────────────────────────────

    public function testUnknownCommandPrintsErrorAndExitsOne(): void
    {
        $result = $this->runCli(['frobnicate']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Unknown command: frobnicate', $result['stdout']);
        self::assertStringContainsString('Available commands:', $result['stdout']);
    }

    // ── list ─────────────────────────────────────────────────────────────

    public function testListCommandPrintsUsageAndExitsZero(): void
    {
        $result = $this->runCli(['list']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Usage:', $result['stdout']);
        self::assertStringContainsString('zealphp new', $result['stdout']);
    }

    // ── --version / -V ──────────────────────────────────────────────────

    public function testVersionFlagFailsWithoutComposerJson(): void
    {
        $result = $this->runCli(['--version']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Composer not found', $result['stderr']);
    }

    public function testVersionFlagReadsVersionFromComposerJson(): void
    {
        $this->writeComposerJson(['require' => ['zealphp/zealphp' => '^0.4.12']]);

        $result = $this->runCli(['--version']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('ZealPHP Version :^0.4.12', $result['stdout']);
    }

    public function testVersionShortFlagBehavesLikeLongFlag(): void
    {
        $this->writeComposerJson(['require' => ['zealphp/zealphp' => '1.2.3']]);

        $result = $this->runCli(['-V']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('ZealPHP Version :1.2.3', $result['stdout']);
    }

    public function testVersionFlagFallsBackWhenRequireKeyMissing(): void
    {
        $this->writeComposerJson(['require' => []]);

        $result = $this->runCli(['--version']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Version is not maintained in Composer.json File', $result['stdout']);
    }

    /**
     * Documents a real bug: getComposerData() is typed `: array` but
     * returns null on malformed JSON, which triggers a TypeError instead
     * of the intended graceful failure.
     */
    public function testVersionFlagWithMalformedComposerJsonCurrentlyFatals(): void
    {
        file_put_contents($this->cwd . '/composer.json', '{ this is not valid json');

        $result = $this->runCli(['--version']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('TypeError', $result['stderr']);
    }

    // ── new <name> ───────────────────────────────────────────────────────

    public function testNewCommandRequiresAName(): void
    {
        $result = $this->runCli(['new']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Project name is required.', $result['stderr']);
    }

    public function testNewCommandRejectsInvalidCharactersInName(): void
    {
        $result = $this->runCli(['new', 'my app!']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Invalid project name', $result['stderr']);
    }

    public function testNewCommandRejectsWhenDirectoryAlreadyExists(): void
    {
        mkdir($this->cwd . '/existing-app');

        $result = $this->runCli(['new', 'existing-app']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Directory already exists: existing-app', $result['stderr']);
    }

    // ── upgrade [version] ───────────────────────────────────────────────

    public function testUpgradeRejectsAnInvalidVersionString(): void
    {
        // upgrade calls getInstalledVersion() before validating the version
        // string, so vendor/composer/installed.json must exist or it fails
        // earlier with an unrelated "project not found" error.
        $this->writeInstalledJson('0.4.10');

        $result = $this->runCli(['upgrade', 'not-a-version']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString("doesn't look like a valid version", $result['stderr']);
    }

    private function writeInstalledJson(string $version): void
    {
        mkdir($this->cwd . '/vendor/composer', 0777, true);
        file_put_contents(
            $this->cwd . '/vendor/composer/installed.json',
            json_encode([
                'packages' => [
                    ['name' => 'zealphp/zealphp', 'version' => $version],
                ],
            ], JSON_PRETTY_PRINT)
        );
    }

    public function testUpgradeAbortsWhenUserDeclinesConfirmation(): void
    {
        $this->writeInstalledJson('0.4.10');

        $result = $this->runCli(['upgrade', '0.5.0'], "no\n");

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Aborted. No changes were made.', $result['stdout']);
    }

    // ── start / stop / restart / status / logs (forwarded commands) ─────

    public function testStartFailsWithoutComposerJson(): void
    {
        $result = $this->runCli(['start']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Composer not found', $result['stderr']);
    }

    public function testStartFailsWhenEntryPointMissingFromComposerJson(): void
    {
        $this->writeComposerJson(['require' => ['zealphp/zealphp' => '^0.4.12']]);

        $result = $this->runCli(['start']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Entry point is missing in composer.json', $result['stderr']);
    }

    public function testStartFailsWhenEntryPointFileDoesNotExist(): void
    {
        $this->writeComposerJson([
            'require' => ['zealphp/zealphp' => '^0.4.12'],
            'serve'   => ['entry-point' => 'app.php'],
        ]);

        $result = $this->runCli(['start']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('app.php not found in:', $result['stderr']);
    }

    public function testStartForwardsToEntryPointWhenPresent(): void
    {
        $this->writeComposerJson([
            'require' => ['zealphp/zealphp' => '^0.4.12'],
            'serve'   => ['entry-point' => 'app.php'],
        ]);
        // A trivial stand-in entry point so we can prove forwarding happened,
        // without needing the real ZealPHP/OpenSwoole runtime installed.
        file_put_contents($this->cwd . '/app.php', "<?php echo 'ENTRY_POINT_REACHED ' . implode(',', array_slice(\$argv, 1));");

        $result = $this->runCli(['start', '--dev']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('ENTRY_POINT_REACHED start,--dev', $result['stdout']);
    }

    /**
     * Documents a real gap: --help / -h are listed in VALID_COMMANDS but
     * have no dedicated handler, so they fall through to the "forward to
     * entry point" logic instead of printing help text.
     */
    public function testHelpFlagFallsThroughToEntryPointForwardingInsteadOfPrintingHelp(): void
    {
        $result = $this->runCli(['--help']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Composer not found', $result['stderr']);
    }
}