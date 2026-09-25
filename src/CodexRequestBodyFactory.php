<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

/**
 * Builds the Codex Responses API JSON body shared by SSE and WebSocket transports.
 */
final class CodexRequestBodyFactory
{
    /** Internal invocation option; never sent as a top-level API field. */
    public const string REASONING_UPDATE = 'codex_reasoning_update';
    public const string REASONING_RESET = 'codex_reasoning_reset';
    public const string CONTINUATION_RESET = 'codex_continuation_reset';
    public const string CONTINUATION_GENERATION = 'codex_continuation_generation';

    /** @param list<string> $internalOptions Host-only options to consume before transmission. */
    public function __construct(private readonly array $internalOptions = [])
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function build(Model $model, array $payload, array $options): array
    {
        // Structured output: map Symfony AI RESPONSE_FORMAT into Codex text.format
        // (format lives under options['text']).
        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            $schema = $options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
            $options['text']['format'] = $schema;
            $options['text']['format']['name'] = $schema['name'];
            $options['text']['format']['type'] = $options[PlatformSubscriber::RESPONSE_FORMAT]['type'];

            unset($options[PlatformSubscriber::RESPONSE_FORMAT]);
        }

        // Merge order: options, then model name, then contract payload last so
        // CodexContract keys (input, instructions, …) win over duplicate top-level options.
        // Payload also wins over the injected model key when both set a field.
        $jsonBody = array_merge($options, ['model' => $model->getName()], $payload);

        // History-bound updates are authored by CodexMessageBagNormalizer.
        // Keep internal control keys off the wire.
        unset(
            $jsonBody[self::REASONING_UPDATE],
            $jsonBody[self::REASONING_RESET],
            $jsonBody[self::CONTINUATION_RESET],
            $jsonBody[self::CONTINUATION_GENERATION],
        );
        foreach ($this->internalOptions as $option) {
            unset($jsonBody[$option]);
        }

        // Empty prompt_cache_key in the payload must not erase a resolved options value.
        if (\array_key_exists('prompt_cache_key', $jsonBody)
            && (!\is_string($jsonBody['prompt_cache_key']) || '' === $jsonBody['prompt_cache_key'])) {
            unset($jsonBody['prompt_cache_key']);
        }
        if (!isset($jsonBody['prompt_cache_key'])
            && isset($options['prompt_cache_key'])
            && \is_string($options['prompt_cache_key'])
            && '' !== $options['prompt_cache_key']) {
            $jsonBody['prompt_cache_key'] = $options['prompt_cache_key'];
        }

        // Codex Responses defaults — pi-mono openai-codex-responses.ts buildRequestBody parity.
        $jsonBody['store'] ??= false;
        $jsonBody['stream'] ??= true;

        if (!isset($jsonBody['text'])) {
            $jsonBody['text'] = ['verbosity' => 'low'];
        } elseif (!isset($jsonBody['text']['verbosity'])) {
            $jsonBody['text']['verbosity'] = 'low';
        }

        $jsonBody['include'] ??= ['reasoning.encrypted_content'];
        $jsonBody['tool_choice'] ??= 'auto';
        $jsonBody['parallel_tool_calls'] ??= true;

        return $jsonBody;
    }
}
