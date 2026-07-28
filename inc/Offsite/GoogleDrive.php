<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Anbindung an Google Drive: OAuth-Device-Flow und die Datei-Operationen, die das
 * Offsite-Backup braucht.
 *
 * Bewusst ohne Google-SDK, alles über die WordPress-HTTP-API. Das SDK zieht dutzende
 * Composer-Pakete nach, von denen hier drei Endpunkte gebraucht werden.
 *
 * Warum Device-Flow statt klassischem Redirect: der Device-Flow erlaubt ausdrücklich
 * den Scope drive.file (Google-Doku, Abschnitt "Allowed scopes"). Damit entfällt jede
 * Redirect-URI und jeder Callback-Server. Die Kundensite redet ausschliesslich mit
 * Google, nicht mit einem zentralen Vermittler, der selbst ausfallen könnte. Das passt
 * zum Zweck des Features: eine Kopie, die auch dann noch läuft, wenn die Agentur weg ist.
 *
 * Der Scope ist ausschliesslich drive.file. Damit sieht die Anwendung nur Dateien, die
 * sie selbst angelegt hat, niemals den übrigen Drive-Inhalt des Kunden.
 *
 * Diese Klasse ist bewusst als einzelne, austauschbar geschnittene Einheit gebaut. Ein
 * zweiter Storage-Anbieter wäre eine weitere Klasse mit denselben Methoden, kein Umbau.
 * Ein Interface entsteht, wenn der zweite Anbieter real wird, nicht vorher.
 */
final class GoogleDrive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const DEVICE_CODE_URL = 'https://oauth2.googleapis.com/device/code';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';
    private const ABOUT_URL = 'https://www.googleapis.com/drive/v3/about?fields=user(emailAddress)';

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ============================================================
    // OAuth: Device-Flow
    // ============================================================

    /**
     * Schritt 1: Geräte-Code anfordern. Der Nutzer bekommt eine kurze URL und einen Code.
     *
     * @return array{device_code: string, user_code: string, verification_url: string, expires_in: int, interval: int}
     * @throws \RuntimeException
     */
    public function requestDeviceCode(): array
    {
        $response = $this->post(self::DEVICE_CODE_URL, [
            'client_id' => Settings::clientId(),
            'scope' => self::SCOPE,
        ]);

        if (! $response->ok()) {
            throw new \RuntimeException($this->oauthError($response, __('Der Verbindungs-Code konnte nicht angefordert werden.', 'rh-backup')));
        }

        $data = $response->json();
        $deviceCode = (string) ($data['device_code'] ?? '');
        $userCode = (string) ($data['user_code'] ?? '');
        if ($deviceCode === '' || $userCode === '') {
            throw new \RuntimeException(__('Google hat keinen gültigen Verbindungs-Code geliefert.', 'rh-backup'));
        }

        return [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            // Google liefert je nach API-Version verification_url oder verification_uri.
            'verification_url' => (string) ($data['verification_url'] ?? $data['verification_uri'] ?? 'https://www.google.com/device'),
            'expires_in' => (int) ($data['expires_in'] ?? 1800),
            'interval' => max(5, (int) ($data['interval'] ?? 5)),
        ];
    }

    /**
     * Schritt 2: einmal abfragen, ob der Nutzer die Freigabe erteilt hat.
     *
     * @return array{status: string, refresh_token?: string, access_token?: string, expires_in?: int, message?: string}
     *         status: connected | pending | slow_down | denied | expired | error
     */
    public function pollDeviceCode(string $deviceCode): array
    {
        $response = $this->post(self::TOKEN_URL, [
            'client_id' => Settings::clientId(),
            'client_secret' => Settings::clientSecret(),
            'device_code' => $deviceCode,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ]);

        $data = $response->json();

        if ($response->ok()) {
            $refresh = (string) ($data['refresh_token'] ?? '');
            if ($refresh === '') {
                return ['status' => 'error', 'message' => __('Google hat keinen dauerhaften Zugang geliefert.', 'rh-backup')];
            }

            return [
                'status' => 'connected',
                'refresh_token' => $refresh,
                'access_token' => (string) ($data['access_token'] ?? ''),
                'expires_in' => (int) ($data['expires_in'] ?? 3600),
            ];
        }

        // Während der Nutzer noch bestätigt, antwortet Google mit einem Fehlercode.
        return match ((string) ($data['error'] ?? '')) {
            'authorization_pending' => ['status' => 'pending'],
            'slow_down' => ['status' => 'slow_down'],
            'access_denied' => ['status' => 'denied'],
            'expired_token', 'invalid_grant' => ['status' => 'expired'],
            default => ['status' => 'error', 'message' => $this->oauthError($response, __('Die Verbindung ist fehlgeschlagen.', 'rh-backup'))],
        };
    }

    /**
     * Holt einen gültigen Access-Token, aus dem Cache oder frisch per Refresh-Token.
     *
     * @throws \RuntimeException wenn der Refresh-Token nicht mehr gilt.
     */
    public function accessToken(bool $forceRefresh = false): string
    {
        if (! $forceRefresh) {
            $cached = $this->connection->cachedAccessToken();
            if ($cached !== '') {
                return $cached;
            }
        }

        $refresh = $this->connection->refreshToken();
        if ($refresh === '') {
            throw new \RuntimeException(__('Es besteht keine Verbindung zu Google Drive.', 'rh-backup'));
        }

        $response = $this->post(self::TOKEN_URL, [
            'client_id' => Settings::clientId(),
            'client_secret' => Settings::clientSecret(),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->ok()) {
            throw new \RuntimeException($this->oauthError(
                $response,
                __('Der Zugang zu Google Drive gilt nicht mehr. Bitte die Verbindung neu herstellen.', 'rh-backup')
            ));
        }

        $data = $response->json();
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException(__('Google hat keinen Zugangs-Token geliefert.', 'rh-backup'));
        }

        $this->connection->storeAccessToken($token, (int) ($data['expires_in'] ?? 3600));

        return $token;
    }

    /**
     * E-Mail-Adresse des verbundenen Kontos, für die Anzeige.
     *
     * Ohne die Scopes email/profile ist nicht zugesichert, dass Drive die Adresse
     * herausgibt. Schlägt es fehl, bleibt die Anzeige eben ohne Namen, statt die
     * Verbindung an einer Nebensache scheitern zu lassen.
     */
    public function accountEmail(): string
    {
        try {
            $response = $this->get(self::ABOUT_URL);
        } catch (\Throwable) {
            return '';
        }

        if (! $response->ok()) {
            return '';
        }

        $data = $response->json();

        return (string) ($data['user']['emailAddress'] ?? '');
    }

    // ============================================================
    // Ordner
    // ============================================================

    /**
     * Liefert die Ordner-ID für diese Website und legt die Ordner an, falls nötig.
     *
     * Aufbau: "<Ordnername>/<domain.de>". Damit liegen mehrere Websites im selben
     * Drive-Konto getrennt, und die Rotation der einen fasst die andere nicht an.
     *
     * @throws \RuntimeException
     */
    public function ensureFolder(): string
    {
        $known = $this->connection->folderId();
        if ($known !== '' && $this->folderExists($known)) {
            return $known;
        }

        $parent = $this->findOrCreateFolder(Settings::folderName(), null);
        $siteFolder = $this->findOrCreateFolder(Settings::siteFolderName(), $parent);

        $this->connection->storeFolderId($siteFolder);

        return $siteFolder;
    }

    private function folderExists(string $id): bool
    {
        $response = $this->get(self::FILES_URL . '/' . rawurlencode($id) . '?fields=id,trashed');
        if (! $response->ok()) {
            return false;
        }

        $data = $response->json();

        return empty($data['trashed']);
    }

    /**
     * @throws \RuntimeException
     */
    private function findOrCreateFolder(string $name, ?string $parentId): string
    {
        $query = sprintf(
            "mimeType='%s' and name='%s' and trashed=false and '%s' in parents",
            self::FOLDER_MIME,
            $this->escapeQueryValue($name),
            $parentId !== null ? $this->escapeQueryValue($parentId) : 'root'
        );

        $response = $this->get(self::FILES_URL . '?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id,name)',
            'pageSize' => 10,
        ]));

        if ($response->ok()) {
            $files = $response->json()['files'] ?? [];
            if (is_array($files) && isset($files[0]['id'])) {
                return (string) $files[0]['id'];
            }
        }

        $metadata = [
            'name' => $name,
            'mimeType' => self::FOLDER_MIME,
        ];
        if ($parentId !== null) {
            $metadata['parents'] = [$parentId];
        }

        $created = $this->postJson(self::FILES_URL . '?fields=id', $metadata);
        if (! $created->ok()) {
            throw new \RuntimeException(sprintf(
                /* translators: %1$s: folder name, %2$s: error message */
                __('Der Ordner "%1$s" konnte in Google Drive nicht angelegt werden: %2$s', 'rh-backup'),
                $name,
                $created->errorMessage()
            ));
        }

        $id = (string) ($created->json()['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException(__('Google Drive hat keine Ordner-Kennung geliefert.', 'rh-backup'));
        }

        return $id;
    }

    // ============================================================
    // Resumable Upload
    // ============================================================

    /**
     * Startet eine fortsetzbare Upload-Sitzung und liefert deren URI.
     *
     * Die URI ist laut Google eine Woche gültig. Sie wird im Job gespeichert, damit ein
     * abgebrochener Upload beim nächsten Durchlauf weiterläuft statt von vorne zu beginnen.
     *
     * @throws \RuntimeException
     */
    public function startUploadSession(string $fileName, int $fileSize, string $folderId): string
    {
        $response = $this->request('POST', self::UPLOAD_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/zip',
                'X-Upload-Content-Length' => (string) $fileSize,
            ],
            'body' => (string) wp_json_encode([
                'name' => $fileName,
                'parents' => [$folderId],
            ]),
        ]);

        if (! $response->ok()) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: error message */
                __('Der Upload konnte nicht gestartet werden: %s', 'rh-backup'),
                $response->errorMessage()
            ));
        }

        $location = $response->header('location');
        if ($location === '') {
            throw new \RuntimeException(__('Google Drive hat keine Upload-Adresse geliefert.', 'rh-backup'));
        }

        // An diese Adresse geht anschliessend das komplette Backup. Sie stammt zwar aus
        // einer geprüften Verbindung zu Google, aber sie wandert in den Job-Zustand und
        // wird von dort über viele Requests hinweg weiterverwendet. Ein Blick auf den
        // Rechnernamen kostet nichts und schliesst aus, dass die Daten je woanders landen.
        if (! self::isGoogleUploadUrl($location)) {
            throw new \RuntimeException(__('Google Drive hat eine Upload-Adresse ausserhalb der eigenen Server geliefert. Der Lauf wurde abgebrochen.', 'rh-backup'));
        }

        return $location;
    }

    /**
     * Wie isGoogleUploadUrl, aber wirft.
     *
     * Steht vor jedem Zugriff auf eine gespeicherte Upload-Adresse: der Job-Zustand
     * überdauert viele Requests, und geprüft gehört die Adresse dort, wo die Daten
     * tatsächlich hinausgehen, nicht nur dort, wo sie entstanden ist.
     *
     * @throws \RuntimeException
     */
    private static function assertUploadUrl(string $url): void
    {
        if (! self::isGoogleUploadUrl($url)) {
            throw new \RuntimeException(__('Die gespeicherte Upload-Adresse zeigt nicht auf Google. Der Lauf wurde abgebrochen.', 'rh-backup'));
        }
    }

    /**
     * Zeigt die Adresse wirklich auf einen Upload-Server von Google?
     */
    private static function isGoogleUploadUrl(string $url): bool
    {
        $teile = wp_parse_url($url);

        if (! is_array($teile) || ($teile['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($teile['host'] ?? ''));

        return $host === 'googleapis.com' || str_ends_with($host, '.googleapis.com');
    }

    /**
     * Überträgt einen Abschnitt der Datei.
     *
     * @param string $chunk Rohdaten dieses Abschnitts.
     * @param int $offset Byte-Position, an der dieser Abschnitt beginnt.
     * @param int $totalSize Gesamtgrösse der Datei.
     * @return array{done: bool, next_offset: int, file_id: string, size: int}
     * @throws TransientUploadError bei Fehlern, die ein erneuter Versuch beheben kann.
     * @throws \RuntimeException bei endgültigen Fehlern.
     */
    public function uploadChunk(string $sessionUri, string $chunk, int $offset, int $totalSize): array
    {
        self::assertUploadUrl($sessionUri);

        $length = strlen($chunk);
        $end = $offset + $length - 1;

        $response = $this->request('PUT', $sessionUri, [
            'headers' => [
                'Content-Length' => (string) $length,
                'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $end, $totalSize),
            ],
            'body' => $chunk,
            'timeout' => 120,
        ], false);

        return $this->interpretUploadResponse($response, $totalSize);
    }

    /**
     * Fragt bei Google nach, wie viele Bytes tatsächlich angekommen sind.
     *
     * Nach einem Abbruch ist das der verlässliche Weg, den Cursor wieder auf die
     * Wahrheit zu setzen, statt den Offset zu raten.
     *
     * @return array{done: bool, next_offset: int, file_id: string, size: int}
     * @throws TransientUploadError|\RuntimeException
     */
    public function queryUploadStatus(string $sessionUri, int $totalSize): array
    {
        self::assertUploadUrl($sessionUri);

        $response = $this->request('PUT', $sessionUri, [
            'headers' => [
                'Content-Length' => '0',
                'Content-Range' => sprintf('bytes */%d', $totalSize),
            ],
            'body' => '',
            'timeout' => 30,
        ], false);

        return $this->interpretUploadResponse($response, $totalSize);
    }

    /**
     * @return array{done: bool, next_offset: int, file_id: string, size: int}
     * @throws TransientUploadError|\RuntimeException
     */
    private function interpretUploadResponse(HttpResponse $response, int $totalSize): array
    {
        // 308: Google hat den Abschnitt angenommen, es fehlt noch etwas.
        if ($response->status() === 308) {
            return [
                'done' => false,
                'next_offset' => $this->nextOffsetFromRange($response->header('range')),
                'file_id' => '',
                'size' => 0,
            ];
        }

        if ($response->ok()) {
            $data = $response->json();

            return [
                'done' => true,
                'next_offset' => $totalSize,
                'file_id' => (string) ($data['id'] ?? ''),
                'size' => (int) ($data['size'] ?? 0),
            ];
        }

        // 404: die Sitzung ist abgelaufen, der Upload muss neu begonnen werden.
        if ($response->status() === 404) {
            throw new ExpiredSessionError(__('Die Upload-Sitzung ist abgelaufen, der Upload beginnt neu.', 'rh-backup'));
        }

        if ($response->isTransient()) {
            throw new TransientUploadError($response->errorMessage(), $response->retryAfter());
        }

        throw new \RuntimeException(sprintf(
            /* translators: %s: error message */
            __('Der Upload wurde von Google abgelehnt: %s', 'rh-backup'),
            $response->errorMessage()
        ));
    }

    /**
     * Liest aus dem Range-Header, ab welchem Byte weitergemacht wird.
     * Format: "bytes=0-262143". Fehlt der Header, ist noch nichts angekommen.
     */
    private function nextOffsetFromRange(string $range): int
    {
        if ($range === '' || ! preg_match('/bytes=\d+-(\d+)/', $range, $matches)) {
            return 0;
        }

        return (int) $matches[1] + 1;
    }

    // ============================================================
    // Rotation
    // ============================================================

    /**
     * Listet die Backups dieser Website im Zielordner, neueste zuerst.
     *
     * Gefiltert wird über den eigenen Ordner, nicht über die Scope-Semantik: so ist
     * ausgeschlossen, dass die Rotation je eine fremde Datei anfasst.
     *
     * @return array<int, array{id: string, name: string, size: int, created: string}>
     * @throws \RuntimeException
     */
    public function listBackups(string $folderId): array
    {
        $response = $this->get(self::FILES_URL . '?' . http_build_query([
            'q' => sprintf("'%s' in parents and trashed=false", $this->escapeQueryValue($folderId)),
            'fields' => 'files(id,name,size,createdTime)',
            'orderBy' => 'createdTime desc',
            'pageSize' => 200,
        ]));

        if (! $response->ok()) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: error message */
                __('Die vorhandenen Backups konnten nicht gelesen werden: %s', 'rh-backup'),
                $response->errorMessage()
            ));
        }

        $files = $response->json()['files'] ?? [];
        if (! is_array($files)) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['id'])) {
                continue;
            }
            $result[] = [
                'id' => (string) $file['id'],
                'name' => (string) ($file['name'] ?? ''),
                'size' => (int) ($file['size'] ?? 0),
                'created' => (string) ($file['createdTime'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Räumt eine Sicherung weg, aber nicht unwiederbringlich.
     *
     * Bewusst in den Papierkorb statt endgültig: ein DELETE auf eine eigene Datei löscht
     * bei Google sofort und für immer, am Papierkorb vorbei. Das Wegräumen passiert hier
     * aber unbeaufsichtigt im Hintergrund, jedes Mal wenn eine neue Sicherung ankommt.
     * Wenn dabei je etwas schiefgeht, sollen dreissig Tage Zeit bleiben, es zu merken.
     */
    public function deleteFile(string $fileId): bool
    {
        $response = $this->request('PATCH', self::FILES_URL . '/' . rawurlencode($fileId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json; charset=UTF-8',
            ],
            'body' => (string) wp_json_encode(['trashed' => true]),
        ]);

        // 200 mit der geänderten Datei ist der Erfolgsfall, 404 heisst schon weg.
        return $response->ok() || $response->status() === 404;
    }

    /**
     * Grösse einer Datei in Drive, für die Verifikation nach dem Upload.
     */
    /**
     * Liest einen Abschnitt einer Datei aus Drive.
     *
     * Über eine Bereichsanfrage, nicht am Stück: ein Archiv von hundert Megabyte passt
     * weder in den Speicher noch in die Laufzeit eines Requests. Google beantwortet
     * Bereichsanfragen auf `alt=media` mit 206 und genau dem angeforderten Stück.
     *
     * @throws \RuntimeException bei einem endgültigen Fehler.
     */
    public function readRange(string $fileId, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $bis = $offset + $length - 1;

        $response = $this->request(
            'GET',
            self::FILES_URL . '/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken(),
                    'Range' => sprintf('bytes=%d-%d', $offset, $bis),
                ],
                'timeout' => 120,
            ]
        );

        // 206 ist der Normalfall, 200 kommt bei sehr kleinen Dateien, die ganz passen.
        if ($response->status() !== 206 && $response->status() !== 200) {
            throw new \RuntimeException(sprintf(
                /* translators: %d: HTTP status code */
                __('Die Sicherung konnte nicht aus Google Drive gelesen werden (Status %d).', 'rh-backup'),
                $response->status()
            ));
        }

        return $response->body();
    }

    public function fileSize(string $fileId): int
    {
        $response = $this->get(self::FILES_URL . '/' . rawurlencode($fileId) . '?fields=size');
        if (! $response->ok()) {
            return -1;
        }

        return (int) ($response->json()['size'] ?? -1);
    }

    // ============================================================
    // HTTP
    // ============================================================

    private function get(string $url): HttpResponse
    {
        return $this->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $url, array $body): HttpResponse
    {
        return $this->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json; charset=UTF-8',
            ],
            'body' => (string) wp_json_encode($body),
        ]);
    }

    /**
     * Formular-POST ohne Authorization, für die OAuth-Endpunkte selbst.
     *
     * @param array<string, string> $fields
     */
    private function post(string $url, array $fields): HttpResponse
    {
        return $this->request('POST', $url, ['body' => $fields], false);
    }

    /**
     * @param array<string, mixed> $args
     * @param bool $retryOnAuthError Bei 401 einmal den Access-Token erneuern und wiederholen.
     */
    private function request(string $method, string $url, array $args, bool $retryOnAuthError = true): HttpResponse
    {
        $args['method'] = $method;
        $args['timeout'] = $args['timeout'] ?? 30;
        // Google-Endpunkte sind immer HTTPS, die Prüfung bleibt an.
        $args['sslverify'] = true;

        $raw = wp_remote_request($url, $args);

        if (is_wp_error($raw)) {
            // Netzwerkfehler sind fast immer vorübergehend.
            return HttpResponse::fromNetworkError($raw->get_error_message());
        }

        $response = HttpResponse::fromWp($raw);

        if ($response->status() === 401 && $retryOnAuthError) {
            $this->connection->forgetAccessToken();
            $args['headers']['Authorization'] = 'Bearer ' . $this->accessToken(true);

            $retry = wp_remote_request($url, $args);

            return is_wp_error($retry)
                ? HttpResponse::fromNetworkError($retry->get_error_message())
                : HttpResponse::fromWp($retry);
        }

        return $response;
    }

    /**
     * Baut aus einer OAuth-Fehlerantwort eine Meldung, die weiterhilft.
     */
    private function oauthError(HttpResponse $response, string $fallback): string
    {
        $data = $response->json();
        $code = (string) ($data['error'] ?? '');

        $known = [
            'invalid_client' => __('Die Zugangsdaten der Anwendung stimmen nicht. Bitte prüfen, ob der OAuth-Client vom Typ "TVs and Limited Input devices" ist und ID und Secret korrekt eingetragen sind.', 'rh-backup'),
            'invalid_grant' => __('Der gespeicherte Zugang gilt nicht mehr. Bitte die Verbindung zu Google Drive neu herstellen.', 'rh-backup'),
            'access_denied' => __('Die Freigabe wurde im Google-Konto abgelehnt.', 'rh-backup'),
            'admin_policy_enforced' => __('Der Google-Workspace-Administrator hat den Zugriff für diese Anwendung gesperrt.', 'rh-backup'),
        ];

        if (isset($known[$code])) {
            return $known[$code];
        }

        $description = (string) ($data['error_description'] ?? '');
        if ($code !== '' || $description !== '') {
            return trim($fallback . ' (' . trim($code . ' ' . $description) . ')');
        }

        return $fallback;
    }

    /**
     * Escaped einen Wert für die Drive-Suchsyntax (einfache Anführungszeichen).
     */
    private function escapeQueryValue(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
