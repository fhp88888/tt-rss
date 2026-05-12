<?php

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

class LLMClient {
	private const SYSTEM_PROMPT = 'Summarize the provided article text concisely. Return plain text only.';
	private const MAX_TIMEOUT_SECONDS = 120;

	public function __construct(private ?ClientInterface $client = null) {
		$this->client ??= new Client();
	}

	public function summarize(string $endpoint, string $model, string $apiKey, string $prompt, int $timeoutSeconds = 30): ?string {
		$timeout = max(1, min($timeoutSeconds, self::MAX_TIMEOUT_SECONDS));

		try {
			$response = $this->client->request('POST', $endpoint, [
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
							'content' => self::SYSTEM_PROMPT,
						],
						[
							'role' => 'user',
							'content' => $prompt,
						],
					],
					'temperature' => 0.2,
				],
			]);
		} catch (GuzzleException) {
			return null;
		}

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
