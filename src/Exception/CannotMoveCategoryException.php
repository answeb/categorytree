<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Exception;

/**
 * Déplacement refusé par la garde (422). Le message est une clé du domaine Modules.Categorytree.Admin.
 */
class CannotMoveCategoryException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(
        string $message,
        private readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, string|int>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
