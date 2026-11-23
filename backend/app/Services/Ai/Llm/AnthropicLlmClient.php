<?php

namespace App\Services\Ai\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Claude API mediante el SDK oficial de PHP. Una sola llamada por análisis con
 * salida estructurada (JSON Schema): sin herramientas, sin acceso a la base de
 * datos ni a internet. La clave se lee de config (backend/.env) y nunca se registra.
 */
final class AnthropicLlmClient implements LlmClient
{
    private ?Client $client = null;

    public function __construct(private readonly array $config) {}

    public function available(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && ! empty($this->config['anthropic']['api_key']);
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function structured(LlmRequest $request): LlmResult
    {
        if (! $this->available()) {
            throw new LlmException(LlmException::NOT_CONFIGURED, 'El modelo de IA no está configurado.');
        }

        $c = $this->config['anthropic'];
        $args = [
            'model' => $c['model'],
            'maxTokens' => $c['max_tokens'],
            'system' => $request->system,
            'messages' => [['role' => 'user', 'content' => $request->input]],
            // Pensamiento adaptativo por defecto en Claude Opus 5.5; la profundidad se controla con effort.
            'outputConfig' => [
                'effort' => $c['effort'],
                'format' => ['type' => 'json_schema', 'schema' => $request->schema],
            ],
        ];

        try {
            if ($c['server_side_fallback']) {
                $message = $this->client()->beta->messages->create(
                    ...$args,
                    fallbacks: 'default',
                    betas: ['server-side-fallback-2026-07-01'],
                );
            } else {
                $message = $this->client()->messages->create(...$args);
            }
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new LlmException(LlmException::AUTH, 'La clave de la API de Claude no es válida o no tiene acceso. Revisa ANTHROPIC_API_KEY en el servidor.');
        } catch (NotFoundException) {
            throw new LlmException(LlmException::BAD_REQUEST, 'El modelo configurado no existe o no está disponible para la cuenta. Revisa AI_MODEL.');
        } catch (RateLimitException) {
            throw new LlmException(LlmException::RATE_LIMITED, 'Se alcanzó el límite de uso de la API de Claude. Intenta en unos minutos.');
        } catch (BadRequestException) {
            throw new LlmException(LlmException::BAD_REQUEST, 'La API de Claude rechazó la solicitud. Revisa la configuración del modelo.');
        } catch (APIStatusException) {
            throw new LlmException(LlmException::UNAVAILABLE, 'La API de Claude no respondió correctamente. Intenta nuevamente.');
        } catch (APIConnectionException) {
            throw new LlmException(LlmException::UNAVAILABLE, 'No fue posible conectar con la API de Claude.');
        }

        if ($message->stopReason === 'refusal') {
            throw new LlmException(LlmException::REFUSED, 'El modelo declinó analizar este caso.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new LlmException(LlmException::INVALID_OUTPUT, 'La respuesta del modelo quedó incompleta.');
        }

        $text = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        $data = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($data)) {
            throw new LlmException(LlmException::INVALID_OUTPUT, 'La respuesta del modelo no tiene el formato esperado.');
        }

        return new LlmResult($data, $message->model, $message->usage->inputTokens, $message->usage->outputTokens);
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->config['anthropic']['api_key'],
            baseUrl: $this->config['anthropic']['base_url'] ?? null,
            requestOptions: ['timeout' => $this->config['anthropic']['timeout'], 'maxRetries' => $this->config['anthropic']['max_retries']],
        );
    }
}
