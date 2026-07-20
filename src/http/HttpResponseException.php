<?php

namespace Psf\Http;

/**
 * Lançada por Http::response() no lugar de echo+exit quando PSF_TESTING
 * está definida. Permite testar caminhos que respondem via Http::response()
 * (ex: CheckFields::check()) sem matar o processo do test runner.
 */
class HttpResponseException extends \Exception {
    private array $payload;
    private int $status;

    public function __construct(array $payload, int $status) {
        $this->payload = $payload;
        $this->status  = $status;
        parent::__construct($payload['message'] ?? 'HTTP response in test mode', $status);
    }

    public function getPayload(): array {
        return $this->payload;
    }

    public function getStatus(): int {
        return $this->status;
    }
}
