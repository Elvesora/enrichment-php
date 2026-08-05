<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Exception;

final class ValidationException extends EnrichmentException
{
    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $value = $this->responseBody['errors'] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }

        $errors = [];
        foreach ($value as $field => $messages) {
            if (!is_string($field) || !is_array($messages) || !array_is_list($messages)) {
                continue;
            }

            $validMessages = array_values(array_filter($messages, static fn (mixed $message): bool => is_string($message)));
            if (count($validMessages) === count($messages)) {
                /** @var list<string> $validMessages */
                $errors[$field] = $validMessages;
            }
        }

        return $errors;
    }
}
