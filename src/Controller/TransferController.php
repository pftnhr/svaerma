<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;

/**
 * Swarm import (upload) and JSON/GeoJSON export.
 */
final class TransferController extends Controller
{
    private const MAX_FILES = 20;

    /** @param array<string, string> $params */
    public function import(array $params): Response
    {
        $uploads = $this->uploads();
        if (is_string($uploads)) {
            $this->flash('danger', $uploads);
            return $this->redirect(SettingsController::sectionUrl('import'));
        }

        $report = $this->app->swarmImporter()->import($uploads, $this->request->input('dry_run') === '1');
        return $this->render('settings/import-report', [
            'report' => $report,
            'files' => array_keys($uploads),
        ]);
    }

    /** @param array<string, string> $params */
    public function exportJson(array $params): Response
    {
        return $this->download($this->app->exporter()->json(), 'json', 'application/json');
    }

    /** @param array<string, string> $params */
    public function exportGeoJson(array $params): Response
    {
        return $this->download($this->app->exporter()->geoJson(), 'geojson', 'application/geo+json');
    }

    /** @param array<string, mixed> $data */
    private function download(array $data, string $extension, string $type): Response
    {
        $name = sprintf('svaerma-%s.%s', gmdate('Y-m-d'), $extension);
        return Response::json($data)
            ->withHeader('Content-Type', $type . '; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Uploaded files as name => content (read from PHP's temp files, never stored).
     *
     * @return array<string, string>|string error message
     */
    private function uploads(): array|string
    {
        $files = $this->request->files['files'] ?? null;
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            return 'Bitte mindestens eine checkins.json auswählen.';
        }
        $result = [];
        foreach ($files['name'] as $i => $name) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                return sprintf('„%s“ ist größer als das Upload-Limit des Servers (%s).', $name, (string) ini_get('upload_max_filesize'));
            }
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                return sprintf('„%s“ konnte nicht hochgeladen werden (Fehler %d).', $name, $error);
            }
            $result[(string) $name] = (string) file_get_contents($tmp);
        }
        if ($result === []) {
            return 'Bitte mindestens eine checkins.json auswählen.';
        }
        if (count($result) > self::MAX_FILES) {
            return 'Bitte höchstens ' . self::MAX_FILES . ' Dateien auf einmal.';
        }
        return $result;
    }
}
