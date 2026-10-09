<?php

namespace App\WebsiteIntelligence;

use App\AI\Providers\AIProviderException;
use Illuminate\Database\QueryException;
use Throwable;

final class WebsiteIntelligenceFailureTaxonomy
{
    public const CATEGORIES = ['DNS', 'TLS', 'HTTP_4XX', 'HTTP_5XX', 'ROBOTS', 'REDIRECT_POLICY', 'SSRF_POLICY', 'TIMEOUT',
        'UNSUPPORTED_CONTENT', 'RENDER_FAILURE', 'PARSER_FAILURE', 'PROVIDER_AUTH', 'MODEL_UNAVAILABLE', 'PROVIDER_RATE_LIMIT',
        'PROVIDER_QUOTA', 'PROVIDER_TIMEOUT', 'PROVIDER_CONNECTION', 'PROVIDER_REQUEST_INVALID', 'PROVIDER_SCHEMA',
        'PROVIDER_RESPONSE_PARSE', 'PROVIDER_SERVER', 'PROMPT_MISSING', 'MODEL_ROUTE_MISSING', 'PERSISTENCE', 'UNKNOWN'];

    public function classify(Throwable $error): string
    {
        $message = strtolower($error->getMessage().' '.($error->getPrevious()?->getMessage() ?? ''));
        if (preg_match('/\bhttp\s+(\d{3})\b/', $message, $status)) {
            if ((int) $status[1] >= 400 && (int) $status[1] < 500) return 'HTTP_4XX';
            if ((int) $status[1] >= 500) return 'HTTP_5XX';
        }
        return match (true) {
            str_contains($message, 'could not be resolved') || str_contains($message, 'dns') => 'DNS',
            str_contains($message, 'tls') || str_contains($message, 'certificate') || str_contains($message, 'ssl') => 'TLS',
            str_contains($message, 'robots') => 'ROBOTS',
            str_contains($message, 'redirect') => 'REDIRECT_POLICY',
            str_contains($message, 'non-public address') || str_contains($message, 'public http') => 'SSRF_POLICY',
            str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'budget') => 'TIMEOUT',
            str_contains($message, 'content-type') || str_contains($message, 'content type') || str_contains($message, 'crawl limit') => 'UNSUPPORTED_CONTENT',
            str_contains($message, 'render') || str_contains($message, 'playwright') => 'RENDER_FAILURE',
            str_contains($message, 'parse') || str_contains($message, 'extract') => 'PARSER_FAILURE',
            str_contains($message, 'could not be retrieved') => 'UNKNOWN',
            str_contains($message, 'sql') || str_contains($message, 'database') || str_contains($message, 'storage') => 'PERSISTENCE',
            default => 'UNKNOWN',
        };
    }

    /** Retry advice is descriptive only; callers must still apply their own bounded retry policy. */
    public function retryability(string $category): string
    {
        return match ($category) {
            'DNS', 'HTTP_5XX', 'TIMEOUT' => 'YES',
            'HTTP_4XX', 'ROBOTS', 'REDIRECT_POLICY', 'SSRF_POLICY', 'TLS' => 'NO',
            default => 'UNKNOWN',
        };
    }

    public function safeSummary(string $category): string
    {
        return match ($category) {
            'DNS' => 'The website host could not be resolved.',
            'TLS' => 'A secure connection to the website could not be established.',
            'HTTP_4XX' => 'The website returned a client error response.',
            'HTTP_5XX' => 'The website returned a server error response.',
            'ROBOTS' => 'Crawl permission could not be verified from robots.txt.',
            'REDIRECT_POLICY' => 'The website redirect did not satisfy crawl safety policy.',
            'SSRF_POLICY' => 'The website destination did not satisfy public-network safety policy.',
            'TIMEOUT' => 'The website did not respond within the crawl time budget.',
            'RENDER_FAILURE' => 'The optional browser rendering step could not be completed.',
            'PARSER_FAILURE' => 'The page content could not be parsed safely.',
            default => 'The website scan could not be completed; available diagnostics are inconclusive.',
        };
    }

    public function classifyProvider(Throwable $error): string
    {
        if ($error instanceof QueryException) return 'PERSISTENCE';

        if ($error instanceof AIProviderException) {
            $status = $error->httpStatus;
            $code = strtolower($error->providerErrorCode ?? '');
            $type = strtolower($error->providerErrorType ?? '');
            $message = strtolower($error->getMessage());

            return match (true) {
                $status === 401 || $status === 403 => 'PROVIDER_AUTH',
                in_array($code, ['insufficient_quota', 'billing_hard_limit_reached', 'quota_exceeded'], true) => 'PROVIDER_QUOTA',
                $status === 429 => 'PROVIDER_RATE_LIMIT',
                $status === 404 || str_contains($code, 'model_not_found') || str_contains($message, 'model') && str_contains($message, 'not found') => 'MODEL_UNAVAILABLE',
                $error->stage === 'provider_timeout' || $status === 408 => 'PROVIDER_TIMEOUT',
                $error->stage === 'provider_connection' => 'PROVIDER_CONNECTION',
                $error->stage === 'response_schema' => 'PROVIDER_SCHEMA',
                $error->stage === 'usage_persistence' => 'PERSISTENCE',
                str_contains($code, 'schema') || str_contains($type, 'schema') || str_contains($message, 'schema') => 'PROVIDER_SCHEMA',
                $status !== null && $status >= 500 => 'PROVIDER_SERVER',
                $error->stage === 'response_parse' => 'PROVIDER_RESPONSE_PARSE',
                $status !== null && $status >= 400 => 'PROVIDER_REQUEST_INVALID',
                default => 'UNKNOWN',
            };
        }

        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, '401') || str_contains($message, '403') || str_contains($message, 'authentication') || str_contains($message, 'credentials are not configured') => 'PROVIDER_AUTH',
            str_contains($message, 'insufficient_quota') || str_contains($message, 'quota exceeded') => 'PROVIDER_QUOTA',
            str_contains($message, '429') || str_contains($message, 'rate limit') => 'PROVIDER_RATE_LIMIT',
            str_contains($message, '404') || str_contains($message, 'model not found') => 'MODEL_UNAVAILABLE',
            str_contains($message, 'timeout') || str_contains($message, 'timed out') => 'PROVIDER_TIMEOUT',
            str_contains($message, 'connection failed') => 'PROVIDER_CONNECTION',
            str_contains($message, 'schema') => 'PROVIDER_SCHEMA',
            str_contains($message, 'valid json') || str_contains($message, 'response was not valid json') => 'PROVIDER_RESPONSE_PARSE',
            str_contains($message, 'no ai model configured') || str_contains($message, 'route is missing') => 'MODEL_ROUTE_MISSING',
            str_contains($message, 'prompt') && (str_contains($message, 'missing') || str_contains($message, 'not approved') || str_contains($message, 'no active')) => 'PROMPT_MISSING',
            preg_match('/http\s+5\d\d/', $message) === 1 => 'PROVIDER_SERVER',
            preg_match('/http\s+4\d\d/', $message) === 1 => 'PROVIDER_REQUEST_INVALID',
            default => 'UNKNOWN',
        };
    }
}
