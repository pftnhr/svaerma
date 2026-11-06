<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Database\Backup;
use App\Database\Connection;
use App\Database\Migrator;
use App\Http\Session;
use App\Repository\SettingsRepository;
use App\Service\Osm\HttpClient;
use App\Service\Osm\OsmException;

/**
 * Initial installation, shared by the web setup (/setup) and bin/setup.php:
 * writes config/config.php, creates the database, migrates and stores the password.
 *
 * Re-running setup (after deleting config/config.php) keeps an existing database,
 * which doubles as password reset.
 */
final class Setup
{
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private string $configPath,
        private string $dataDir,
        private string $migrationsDir,
    ) {
    }

    /** @return list<string> Problems that block setup */
    public function checkRequirements(): array
    {
        $problems = [];
        if (!extension_loaded('pdo_sqlite')) {
            $problems[] = 'PHP-Erweiterung pdo_sqlite fehlt.';
        }
        if (!is_dir($this->dataDir) && !@mkdir($this->dataDir, 0750, true)) {
            $problems[] = 'Verzeichnis data/ fehlt und kann nicht angelegt werden.';
        } elseif (!is_writable($this->dataDir)) {
            $problems[] = 'Verzeichnis data/ ist nicht beschreibbar.';
        }
        if (!is_writable(dirname($this->configPath))) {
            $problems[] = 'Verzeichnis config/ ist nicht beschreibbar.';
        }
        return $problems;
    }

    /**
     * @param array{password: string, contact_email: string, base_url: string, timezone?: string} $input
     * @return list<string> Validation errors (empty on success)
     */
    public function validate(array $input): array
    {
        $errors = [];
        if (mb_strlen($input['password']) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Das Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.';
        }
        if (filter_var($input['contact_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Bitte eine gültige Kontakt-Mail angeben (wird für den OSM-User-Agent gebraucht).';
        }
        if (!preg_match('#^https?://[^/\s]+$#', $input['base_url'])) {
            $errors[] = 'Die Basis-URL muss wie https://checkin.example.org aussehen (ohne / am Ende).';
        }
        $tz = $input['timezone'] ?? 'Europe/Berlin';
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'Unbekannte Zeitzone.';
        }
        return $errors;
    }

    /**
     * @param array{password: string, contact_email: string, base_url: string, timezone?: string} $input
     * @return list<string> Applied migrations
     */
    public function run(array $input): array
    {
        $dbPath = $this->dataDir . '/app.sqlite';
        $pdo = Connection::open($dbPath);
        $migrator = new Migrator($pdo, $this->migrationsDir, new Backup($pdo, $this->dataDir . '/backups'));
        $result = $migrator->migrate();

        $settings = new SettingsRepository($pdo);
        $settings->set('password_hash', password_hash($input['password'], PASSWORD_DEFAULT));
        // A reset must also end every existing session: new epoch, stored sessions gone.
        Auth::rotateEpoch($settings);
        Session::purgeDirectory($this->dataDir . '/sessions');

        // Config is written last: its existence marks setup as complete and locks /setup.
        Config::write([
            'env' => 'prod',
            'base_url' => $input['base_url'],
            'contact_email' => $input['contact_email'],
            'timezone' => $input['timezone'] ?? 'Europe/Berlin',
        ], $this->configPath);

        $this->deleteToken();
        return $result['applied'];
    }

    /**
     * Whether files in data/ can be downloaded over HTTP – the case when the project
     * lies in the web root and the server ignores .htaccess (AllowOverride None, nginx).
     * Writes a random probe file and requests it via $baseUrl.
     *
     * @return bool|null true = exposed, false = protected, null = could not check
     */
    public function dataDirExposed(HttpClient $http, string $baseUrl): ?bool
    {
        $name = 'probe-' . bin2hex(random_bytes(8)) . '.txt';
        $content = bin2hex(random_bytes(16));
        $path = $this->dataDir . '/' . $name;
        if (@file_put_contents($path, $content) === false) {
            return null;
        }
        try {
            $response = $http->fetch(rtrim($baseUrl, '/') . '/data/' . $name);
            return $response['status'] === 200 && str_contains($response['body'], $content);
        } catch (OsmException) {
            return null;
        } finally {
            @unlink($path);
        }
    }

    public function tokenPath(): string
    {
        return $this->dataDir . '/setup-token.txt';
    }

    /**
     * Returns the one-time setup token, creating it on first call.
     * The owner reads it via FTP to prove access to the server.
     */
    public function ensureToken(): string
    {
        $path = $this->tokenPath();
        if (is_file($path)) {
            return trim((string) file_get_contents($path));
        }
        $token = bin2hex(random_bytes(8));
        file_put_contents($path, $token . "\n", LOCK_EX);
        @chmod($path, 0600);
        return $token;
    }

    public function validToken(string $given): bool
    {
        $path = $this->tokenPath();
        if (!is_file($path) || $given === '') {
            return false;
        }
        return hash_equals(trim((string) file_get_contents($path)), trim($given));
    }

    private function deleteToken(): void
    {
        @unlink($this->tokenPath());
    }
}
