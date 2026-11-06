<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Http\Response;
use App\Service\Setup;

final class SetupController extends Controller
{
    /** @param array<string, string> $params */
    public function form(array $params): Response
    {
        $setup = $this->setup();
        $problems = $setup->checkRequirements();
        if ($problems === []) {
            $problems = $this->exposure($setup, $this->requestOrigin());
        }
        // The token must never be created where it could be downloaded.
        if ($problems === []) {
            $setup->ensureToken();
        }
        return $this->show($problems);
    }

    /** @param array<string, string> $params */
    public function submit(array $params): Response
    {
        $setup = $this->setup();
        $problems = $setup->checkRequirements();
        if ($problems !== []) {
            return $this->show($problems);
        }

        $input = [
            'password' => (string) ($this->request->post['password'] ?? ''),
            'contact_email' => $this->request->input('contact_email'),
            'base_url' => rtrim($this->request->input('base_url'), '/'),
            'timezone' => $this->request->input('timezone', 'Europe/Berlin'),
        ];
        $errors = [];
        if (!$setup->validToken($this->request->input('token'))) {
            $errors[] = 'Der Setup-Code stimmt nicht. Du findest ihn per FTP in data/setup-token.txt.';
        }
        if ($input['password'] !== ($this->request->post['password_confirm'] ?? null)) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        $errors = [...$errors, ...$setup->validate($input)];
        if ($errors !== []) {
            return $this->show([], $errors, $input, 422);
        }
        $exposure = $this->exposure($setup, $input['base_url']);
        if ($exposure !== []) {
            return $this->show($exposure, [], $input, 422);
        }

        $setup->run($input);
        $this->app->session()->regenerate();
        $this->flash('success', 'Setup abgeschlossen. Du kannst dich jetzt anmelden.');
        return $this->redirect('/login');
    }

    /**
     * @param list<string> $problems
     * @param list<string> $errors
     * @param array<string, string> $input
     */
    private function show(array $problems, array $errors = [], array $input = [], int $status = 200): Response
    {
        $host = $this->request->header('host') ?? 'checkin.gigold.me';
        return $this->render('setup/form', [
            'problems' => $problems,
            'errors' => $errors,
            'input' => $input + [
                'contact_email' => '',
                'base_url' => ($this->request->https ? 'https://' : 'http://') . $host,
                'timezone' => 'Europe/Berlin',
            ],
            'minLength' => Setup::MIN_PASSWORD_LENGTH,
        ], $status, 'layout_minimal');
    }

    /** @return list<string> a blocking problem if data/ is publicly readable */
    private function exposure(Setup $setup, ?string $origin): array
    {
        if ($origin === null || $setup->dataDirExposed($this->app->http(5), $origin) !== true) {
            return [];
        }
        return ['Das Verzeichnis data/ ist öffentlich abrufbar (Datenbank, Sitzungen und Setup-Code wären downloadbar). '
            . 'Stelle das Document-Root auf public/ oder sorge dafür, dass der Server die .htaccess-Dateien beachtet '
            . '(AllowOverride All). Bei nginx: Zugriff auf data/, config/ usw. sperren. Danach diese Seite neu laden.'];
    }

    /** Origin of the current request, if the Host header looks like a plain host name. */
    private function requestOrigin(): ?string
    {
        $host = (string) $this->request->header('host');
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) {
            return null;
        }
        return ($this->request->https ? 'https://' : 'http://') . $host;
    }

    private function setup(): Setup
    {
        return new Setup(Config::path(), $this->app->dataDir(), APP_ROOT . '/migrations');
    }
}
