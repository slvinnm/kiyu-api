<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use App\Enums\WhatsAppMessageType;
use App\Exceptions\InvalidStateException;
use Illuminate\Support\Facades\Http;

/**
 * A provider-neutral WhatsApp gateway driven entirely by configuration.
 *
 * The endpoint, HTTP method, headers and JSON body are taken from
 * `config('services.whatsapp')`, using `{placeholder}` substitution. Swapping
 * providers (Fonnte, Wablas, Twilio, a self-hosted bridge, ...) is a config
 * change, not a code change.
 *
 * Example config body for a typical Indonesian provider:
 *   "target": "{to}",
 *   "message": "{body}",
 *   "url": "{media_url}"
 */
class HttpWhatsAppGateway implements WhatsAppGateway
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * {@inheritDoc}
     */
    public function send(array $message): array
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');

        if ($endpoint === '') {
            throw new InvalidStateException('The WhatsApp gateway endpoint is not configured.');
        }

        $replacements = $this->replacements($message);

        $body = $this->substitute($this->config['body'] ?? [], $replacements);
        $headers = $this->substitute($this->config['headers'] ?? [], $replacements);

        $method = strtoupper((string) ($this->config['method'] ?? 'POST'));

        $response = Http::withHeaders($headers)
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->send($method, $this->substituteUrl($endpoint, $replacements), [
                'json' => $body,
            ]);

        if ($response->failed()) {
            throw new InvalidStateException(
                "WhatsApp gateway returned {$response->status()}: " . $response->body(),
            );
        }

        return $response->json() ?? ['raw' => $response->body()];
    }

    /**
     * {@inheritDoc}
     */
    public function isConfigured(): bool
    {
        return (string) ($this->config['endpoint'] ?? '') !== '';
    }

    /**
     * The values available for substitution in the config template.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, string>
     */
    private function replacements(array $message): array
    {
        $type = $message['type'] instanceof WhatsAppMessageType
            ? $message['type']
            : WhatsAppMessageType::from((string) $message['type']);

        return [
            'to' => (string) ($message['to'] ?? ''),
            'body' => (string) ($message['body'] ?? ''),
            'type' => $type->value,
            'media_url' => (string) ($message['media_url'] ?? ''),
            'filename' => (string) ($message['filename'] ?? ''),
        ];
    }

    /**
     * Recursively substitute `{placeholders}` in a config structure.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $replacements
     * @return array<string, mixed>
     */
    private function substitute(array $template, array $replacements): array
    {
        foreach ($template as $key => $value) {
            if (is_array($value)) {
                $template[$key] = $this->substitute($value, $replacements);

                continue;
            }

            if (is_string($value)) {
                $template[$key] = $this->replaceTokens($value, $replacements);
            }
        }

        return $template;
    }

    /**
     * Substitute placeholders in the endpoint URL itself.
     *
     * @param  array<string, string>  $replacements
     */
    private function substituteUrl(string $url, array $replacements): string
    {
        return $this->replaceTokens($url, $replacements);
    }

    /**
     * Replace every `{token}` in a string.
     *
     * @param  array<string, string>  $replacements
     */
    private function replaceTokens(string $value, array $replacements): string
    {
        foreach ($replacements as $token => $replacement) {
            $value = str_replace('{' . $token . '}', $replacement, $value);
        }

        return $value;
    }
}
