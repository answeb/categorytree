<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Exception;

/**
 * L'état réel de la catégorie ne correspond plus à celui affiché (409).
 */
class StaleTreeException extends \RuntimeException
{
}
