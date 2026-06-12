<?php

namespace App\Exceptions;

use Exception;

class ConversionServiceException extends Exception
{
    private string $reference;

    private string $title;

    public function __construct(string $message, string $reference, string $title = 'Conversion Error', ?\Throwable $previous = null)
    {
        $this->reference = $reference;
        $this->title = $title;
        parent::__construct($message, 0, $previous);
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function render()
    {
        return response()->json([
            'error' => $this->getMessage(),
            'title' => $this->title,
            'reference' => $this->reference,
            'type' => 'conversion_service_error',
        ], 503); // Service Unavailable
    }
}
