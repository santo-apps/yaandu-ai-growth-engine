<?php

namespace App\AI\Providers;

use RuntimeException;

final class AIProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $stage,
        public readonly string $requestOutcome,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerErrorType = null,
        public readonly ?string $providerErrorCode = null,
        public readonly ?string $endpoint = null,
        public readonly ?string $model = null,
        public readonly ?int $elapsedMs = null,
        public readonly ?int $responseLength = null,
        public readonly ?string $responseFingerprint = null,
        public readonly ?string $jsonError = null,
        public readonly array $normalizations = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string, mixed> */
    public function safeDiagnostics(): array
    {
        return [
            'exception_class' => self::class,
            'stage' => $this->stage,
            'request_outcome' => $this->requestOutcome,
            'http_status' => $this->httpStatus,
            'provider_error_type' => $this->providerErrorType,
            'provider_error_code' => $this->providerErrorCode,
            'safe_message' => $this->getMessage(),
            'endpoint' => $this->endpoint,
            'model' => $this->model,
            'elapsed_ms' => $this->elapsedMs,
            'response_length' => $this->responseLength,
            'response_fingerprint' => $this->responseFingerprint,
            'json_error' => $this->jsonError,
            'normalizations' => $this->normalizations,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }

    public function isResponseParseFailure(): bool
    {
        return in_array($this->stage, ['response_empty', 'response_malformed', 'response_truncated'], true);
    }
}
