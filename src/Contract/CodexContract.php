<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract;

use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexAssistantMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexMessageBagNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexToolCallMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexUserMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\Content\ImageNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\Content\TextNormalizer;
use Symfony\AI\Platform\Contract;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Codex-specific Contract that produces Responses API request shape.
 *
 * Registers Codex-specific normalizers FIRST so that
 * ModelContractNormalizer instances (which check for CodexModel in context)
 * take priority over the unconditional default normalizers appended by
 * {@see Contract::create()}.
 *
 * Transforms:
 *  - MessageBag → {input: [...], instructions: "..."}
 *  - AssistantMessage → {role, type: 'message', content} or function_call items
 *  - ToolCallMessage → {type: 'function_call_output', call_id, output}
 *  - Tool → flat {type: 'function', name, description, parameters}
 *  - ToolCall (in input) → flat {call_id, name, arguments, type: 'function_call'}
 *
 * Following the OpenResponses bridge pattern.
 */
final class CodexContract extends Contract
{
    /**
     * @param NormalizerInterface[] $normalizers
     */
    public static function create(array $normalizers = []): Contract
    {
        // Use upstream OpenResponses normalizers for standard Responses API
        // shape, with Codex-specific normalizers taking priority where their
        // output differs:
        //   - CodexAssistantMessageNormalizer: typed {type:'output_text', text}
        //   - CodexUserMessageNormalizer: typed {type:'input_text', text} content
        //   - CodexToolNormalizer: adds strict:null to parameters
        //   - CodexToolCallNormalizer: adds id field alongside call_id
        // Upstream normalizers handle Image and Text content types identically
        // to what Codex needs. MessageBag is handled by CodexMessageBagNormalizer
        // (which flattens multi-item outputs unlike the upstream normalizer).
        //
        // The OpenResponses ToolCallMessageNormalizer is intentionally
        // registered explicitly and must NOT be removed as a "duplicate"
        // of the core Platform\Contract\Normalizer\Message\ToolCallMessageNormalizer
        // appended by Contract::create().  The two share a short class name
        // but produce different output:
        //   - OpenResponses: {type:'function_call_output', call_id, output}
        //   - Core:          {role:'tool', content, tool_call_id}
        // Only the OpenResponses shape is acceptable to the Codex Responses
        // API; the core Chat Completions shape causes HTTP 400.
        $codexNormalizers = [
            new CodexMessageBagNormalizer(),
            new CodexAssistantMessageNormalizer(),
            new CodexToolCallMessageNormalizer(),
            new CodexUserMessageNormalizer(),
            new ImageNormalizer(),
            new TextNormalizer(),
            new CodexToolNormalizer(),
            new CodexToolCallNormalizer(),
            ...$normalizers,
        ];

        return parent::create($codexNormalizers);
    }
}
