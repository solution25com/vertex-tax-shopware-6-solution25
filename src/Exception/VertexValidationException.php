<?php

declare(strict_types=1);

namespace VertexTax\Exception;

class VertexValidationException extends VertexApiException
{
    private array $errors;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $errors = [])
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
