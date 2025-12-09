<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Scaleway\Llm;

use Symfony\AI\Platform\Bridge\Scaleway\Scaleway;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Marcus Stöhr <marcus@fischteich.net>
 */
final class ModelClient implements ModelClientInterface
{
    private const RESPONSES_MODEL = 'gpt-oss-120b';
    private const BASE_URL = 'https://api.scaleway.ai/v1';

    private readonly EventSourceHttpClient $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiKey,
    ) {
        $this->httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Scaleway;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        $body = \is_array($payload) ? $payload : ['input' => $payload];
        $body = array_merge($options, $body);
        $body['model'] = $model->getName();

        if (self::RESPONSES_MODEL === $model->getName()) {
            $body = $this->convertMessagesToResponsesInput($body);
            $body = $this->convertResponseFormat($body);
            $url = self::BASE_URL.'/responses';
        } else {
            $url = self::BASE_URL.'/chat/completions';
        }

        return new RawHttpResult($this->httpClient->request('POST', $url, [
            'auth_bearer' => $this->apiKey,
            'json' => $body,
        ]));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function convertMessagesToResponsesInput(array $body): array
    {
        if (!isset($body['messages'])) {
            return $body;
        }

        $body['input'] = array_map(function (array $message): array {
            $content = $message['content'] ?? '';

            if (\is_string($content)) {
                $content = [['type' => 'input_text', 'text' => $content]];
            }

            if (\is_array($content)) {
                if (!array_is_list($content)) {
                    $content = [$content];
                }

                $content = array_map($this->convertContentPart(...), $content);
            }

            return [
                'role' => $message['role'] ?? 'user',
                'content' => $content,
            ];
        }, $body['messages']);

        unset($body['messages']);

        return $body;
    }

    /**
     * @param array<string, mixed>|string $contentPart
     *
     * @return array<string, mixed>
     */
    private function convertContentPart(array|string $contentPart): array
    {
        if (\is_string($contentPart)) {
            return ['type' => 'input_text', 'text' => $contentPart];
        }

        return match ($contentPart['type'] ?? null) {
            'text' => ['type' => 'input_text', 'text' => $contentPart['text'] ?? ''],
            'input_text' => $contentPart,
            'input_image', 'image_url' => [
                'type' => 'input_image',
                'image_url' => \is_array($contentPart['image_url'] ?? null) ? ($contentPart['image_url']['url'] ?? '') : ($contentPart['image_url'] ?? ''),
                ...isset($contentPart['detail']) ? ['detail' => $contentPart['detail']] : [],
            ],
            default => ['type' => 'input_text', 'text' => $contentPart['text'] ?? ''],
        };
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function convertResponseFormat(array $body): array
    {
        if (!isset($body[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            return $body;
        }

        $schema = $body[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
        $body['text']['format'] = $schema;
        $body['text']['format']['name'] = $schema['name'];
        $body['text']['format']['type'] = $body[PlatformSubscriber::RESPONSE_FORMAT]['type'];

        unset($body[PlatformSubscriber::RESPONSE_FORMAT]);

        return $body;
    }
}
