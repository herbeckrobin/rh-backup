<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Schlanke Sicht auf eine HTTP-Antwort der WordPress-HTTP-API.
 *
 * Kapselt die drei Dinge, die der Drive-Client braucht: Status, Header, JSON-Rumpf.
 * Und die Einordnung, ob ein Fehler vorübergehend ist. Diese Unterscheidung ist der
 * Kern der Fehlerbehandlung: bei einem vorübergehenden Fehler wird später erneut
 * versucht, bei einem endgültigen bricht der Lauf ab und meldet sich.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers Kleingeschriebene Header-Namen.
     */
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
        private readonly string $networkError = '',
    ) {
    }

    /**
     * @param array<string, mixed> $raw Rückgabe von wp_remote_request().
     */
    public static function fromWp(array $raw): self
    {
        $headers = [];
        $rawHeaders = $raw['headers'] ?? [];

        // wp_remote_retrieve_headers liefert je nach WP-Version ein Objekt oder ein Array.
        if (is_object($rawHeaders) && method_exists($rawHeaders, 'getAll')) {
            $rawHeaders = $rawHeaders->getAll();
        }

        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $name => $value) {
                $headers[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return new self(
            (int) wp_remote_retrieve_response_code($raw),
            $headers,
            (string) wp_remote_retrieve_body($raw)
        );
    }

    public static function fromNetworkError(string $message): self
    {
        return new self(0, [], '', $message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Lohnt sich ein erneuter Versuch?
     *
     * Netzwerkabbrüche, Zeitüberschreitungen, Überlastung und Rate-Limits ja,
     * alles andere im 4xx-Bereich nein: eine abgelehnte Berechtigung wird auch beim
     * zehnten Versuch abgelehnt.
     */
    public function isTransient(): bool
    {
        if ($this->networkError !== '') {
            return true;
        }

        if ($this->status === 408 || $this->status === 429) {
            return true;
        }

        if ($this->status >= 500) {
            return true;
        }

        // Google meldet Rate-Limits teils als 403 mit sprechendem Grund.
        if ($this->status === 403) {
            $reason = (string) ($this->json()['error']['errors'][0]['reason'] ?? '');

            return in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true);
        }

        return false;
    }

    /**
     * Wartezeit aus dem Retry-After-Header, falls Google eine vorgibt.
     */
    public function retryAfter(): int
    {
        $value = $this->header('retry-after');
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return max(0, (int) $value);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 0 : max(0, $timestamp - time());
    }

    /**
     * Fehlermeldung für Log und Oberfläche, ohne den kompletten Rumpf auszuschütten.
     */
    public function errorMessage(): string
    {
        if ($this->networkError !== '') {
            return $this->networkError;
        }

        $data = $this->json();
        $message = (string) ($data['error']['message'] ?? $data['error_description'] ?? '');
        if ($message !== '') {
            return sprintf('HTTP %d: %s', $this->status, $message);
        }

        return sprintf('HTTP %d', $this->status);
    }
}
