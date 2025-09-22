<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $this->normalizeOrigin($request->headers->get('Origin'));
        $allowedOrigins = $this->allowedOrigins();
        $isAllowedOrigin = $origin !== '' && $this->originIsAllowed($origin, $allowedOrigins);

        $response = $request->isMethod('OPTIONS')
            ? response()->noContent()
            : $next($request);

        if ($isAllowedOrigin) {
            $this->addCorsHeaders($request, $response, $origin);
        }

        return $response;
    }

    private function allowedOrigins(): array
    {
        $origins = config('cors.allowed_origins', []);

        if (! is_array($origins)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($origin) => $this->normalizeOrigin(is_string($origin) ? $origin : null),
            $origins,
        ))));
    }

    private function originIsAllowed(string $origin, array $allowedOrigins): bool
    {
        return in_array($origin, $allowedOrigins, true);
    }

    private function normalizeOrigin(?string $origin): string
    {
        $origin = is_string($origin) ? trim($origin) : '';

        if ($origin === '') {
            return '';
        }

        return rtrim($origin, '/');
    }

    private function addCorsHeaders(Request $request, Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', $this->allowedMethods());
        $response->headers->set('Access-Control-Allow-Headers', $this->allowedHeaders($request));
        $response->headers->set('Access-Control-Max-Age', (string) config('cors.max_age', 86400));

        $exposedHeaders = $this->exposedHeaders();

        if ($exposedHeaders !== '') {
            $response->headers->set('Access-Control-Expose-Headers', $exposedHeaders);
        }

        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary'))));
        $vary[] = 'Origin';
        $response->headers->set('Vary', implode(', ', array_values(array_unique($vary))));
    }

    private function allowedMethods(): string
    {
        $methods = config('cors.allowed_methods', []);

        if (! is_array($methods) || $methods === []) {
            $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        }

        return implode(', ', array_map('trim', $methods));
    }

    private function allowedHeaders(Request $request): string
    {
        $headers = config('cors.allowed_headers', []);

        if (! is_array($headers)) {
            $headers = [];
        }

        $headers = array_map('trim', $headers);

        $requestedHeaders = $request->headers->get('Access-Control-Request-Headers');

        if (is_string($requestedHeaders) && $requestedHeaders !== '') {
            $headers = array_merge($headers, array_map('trim', explode(',', $requestedHeaders)));
        }

        $headers = array_values(array_unique(array_filter($headers)));

        if ($headers === []) {
            $headers = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN', 'Accept', 'Origin'];
        }

        return implode(', ', $headers);
    }

    private function exposedHeaders(): string
    {
        $headers = config('cors.exposed_headers', []);

        if (! is_array($headers)) {
            return '';
        }

        $headers = array_values(array_unique(array_filter(array_map('trim', $headers))));

        if ($headers === []) {
            return '';
        }

        return implode(', ', $headers);
    }
}
