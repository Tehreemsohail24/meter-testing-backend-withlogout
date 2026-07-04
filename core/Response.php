<?php
// =============================================================================
// FILE: core/Response.php
// PURPOSE: Centralised HTTP response helpers.
//          Every endpoint returns JSON through these methods so the output
//          format and headers are consistent and never duplicated.
// =============================================================================

declare(strict_types=1);

class Response
{
    /**
     * Send a JSON success response and terminate.
     *
     * @param mixed $data    The payload to include under the "data" key.
     * @param int   $status  HTTP status code (default 200).
     */
    public static function success(mixed $data, int $status = 200): never
    {
        self::send([
            'status'  => 'success',
            'data'    => $data,
        ], $status);
    }

    /**
     * Send a JSON error response and terminate.
     *
     * @param string $message  Human-readable error description.
     * @param int    $status   HTTP status code (e.g. 400, 401, 409).
     * @param array  $extra    Optional extra fields merged into the payload.
     */
    public static function error(string $message, int $status, array $extra = []): never
    {
        self::send(array_merge([
            'status'  => 'error',
            'message' => $message,
        ], $extra), $status);
    }

    /**
     * Low-level: set headers, encode JSON, exit.
     * JSON_UNESCAPED_UNICODE: keeps Urdu/Arabic characters readable in
     * the payload rather than \uXXXX escape sequences.
     */
    private static function send(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // Prevent API responses from being cached by intermediary proxies
        header('Cache-Control: no-store, no-cache, must-revalidate');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
