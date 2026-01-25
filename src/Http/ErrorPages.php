<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Http;

/**
 * Custom Error Pages Handler
 *
 * Renders beautiful, themed error pages for common HTTP errors.
 *
 * @version 1.0.0
 */
final class ErrorPages
{
    private const VIEWS_PATH = __DIR__ . '/../Views/errors/';

    /**
     * Render 404 Not Found page
     */
    public static function render404(string $homeUrl = '/', ?string $requestedPath = null): never
    {
        http_response_code(404);

        $home_url = $homeUrl;
        $requested_path = $requestedPath ?? $_SERVER['REQUEST_URI'] ?? '/unknown';

        include self::VIEWS_PATH . '404.php';
        exit;
    }

    /**
     * Render 403 Forbidden page
     */
    public static function render403(string $homeUrl = '/', string $reason = 'Access denied'): never
    {
        http_response_code(403);

        $home_url = $homeUrl;

        include self::VIEWS_PATH . '403.php';
        exit;
    }

    /**
     * Render 500 Internal Server Error page
     */
    public static function render500(
        string $homeUrl = '/',
        ?string $errorId = null,
        bool $showDetails = false,
        ?string $errorMessage = null,
        ?string $errorTrace = null
    ): never {
        http_response_code(500);

        $home_url = $homeUrl;
        $error_id = $errorId ?? bin2hex(random_bytes(8));
        $show_details = $showDetails;
        $error_message = $errorMessage;
        $error_trace = $errorTrace;

        include self::VIEWS_PATH . '500.php';
        exit;
    }

    /**
     * Get 404 response (non-exiting version for Response object)
     */
    public static function get404Response(string $homeUrl = '/', ?string $requestedPath = null): Response
    {
        ob_start();
        $home_url = $homeUrl;
        $requested_path = $requestedPath ?? $_SERVER['REQUEST_URI'] ?? '/unknown';
        include self::VIEWS_PATH . '404.php';
        $html = ob_get_clean();

        return Response::html($html, 404);
    }

    /**
     * Get 403 response (non-exiting version for Response object)
     */
    public static function get403Response(string $homeUrl = '/', string $reason = 'Access denied'): Response
    {
        ob_start();
        $home_url = $homeUrl;
        include self::VIEWS_PATH . '403.php';
        $html = ob_get_clean();

        return Response::html($html, 403);
    }

    /**
     * Get 500 response (non-exiting version for Response object)
     */
    public static function get500Response(
        string $homeUrl = '/',
        ?string $errorId = null,
        bool $showDetails = false,
        ?string $errorMessage = null,
        ?string $errorTrace = null
    ): Response {
        ob_start();
        $home_url = $homeUrl;
        $error_id = $errorId ?? bin2hex(random_bytes(8));
        $show_details = $showDetails;
        $error_message = $errorMessage;
        $error_trace = $errorTrace;
        include self::VIEWS_PATH . '500.php';
        $html = ob_get_clean();

        return Response::html($html, 500);
    }
}
