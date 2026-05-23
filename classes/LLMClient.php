<?php

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class LLMClient {
	private const SYSTEM_PROMPT = 'Summarize the provided article text concisely. Return plain text only.';
	private const MAX_TIMEOUT_SECONDS = 120;

	public function __construct(private ?ClientInterface $client = null) {
		$this->client ??= new Client();
	}

	public function summarize(string $endpoint, string $model, string $apiKey, string $prompt, int $timeoutSeconds = 30, ?string $systemPrompt = null): ?string {
		try {
			$response = $this->client->request('POST', $endpoint,
				$this->request_options($model, $apiKey, $prompt, $timeoutSeconds, $systemPrompt));
		} catch (GuzzleException) {
			return null;
		}

		return $this->parse_response($response);
	}

	public function summarize_async(string $endpoint, string $model, string $apiKey, string $prompt, int $timeoutSeconds = 30, ?string $systemPrompt = null): PromiseInterface {
		return $this->client->requestAsync('POST', $endpoint,
			$this->request_options($model, $apiKey, $prompt, $timeoutSeconds, $systemPrompt))->then(
				fn(ResponseInterface $response) => $this->parse_response($response),
				fn() => null
			);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function request_options(string $model, string $apiKey, string $prompt, int $timeoutSeconds, ?string $systemPrompt = null): array {
		$timeout = max(1, min($timeoutSeconds, self::MAX_TIMEOUT_SECONDS));

		return [
			RequestOptions::HEADERS => [
				'Authorization' => "Bearer $apiKey",
				'Content-Type' => 'application/json',
			],
			RequestOptions::HTTP_ERRORS => false,
			RequestOptions::CONNECT_TIMEOUT => $timeout,
			RequestOptions::TIMEOUT => $timeout,
			RequestOptions::JSON => [
				'model' => $model,
				'messages' => [
					[
						'role' => 'system',
						'content' => $systemPrompt ?? self::SYSTEM_PROMPT,
					],
					[
						'role' => 'user',
						'content' => $prompt,
					],
				],
				'temperature' => 0.2,
			],
		];
	}

	private function parse_response(ResponseInterface $response): ?string {
		$status = $response->getStatusCode();

		if ($status < 200 || $status >= 300) {
			return null;
		}

		try {
			$data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		$content = $data['choices'][0]['message']['content'] ?? null;

		if (!is_string($content)) {
			return null;
		}

		$content = trim($content);

		return $content === '' ? null : $content;
	}
}
