<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * A fallback model catalog that accepts any model name and creates CodexModel instances with all capabilities.
 */
class CodexModelCatalog extends AbstractModelCatalog
{
    public function __construct()
    {
        $this->models = [];
    }

    public function getModel(string $modelName): Model
    {
        $parsed = self::parseModelName($modelName);

        return new CodexModel($parsed['name'], Capability::cases(), $parsed['options']);
    }
}
