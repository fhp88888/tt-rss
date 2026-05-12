<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class LLMClientTest extends TestCase {
	/**
	 * @param array<int, Response|\Throwable> $responses
	 * @param array<int, array<string, mixed>> $history
	 */
	private function build_client(array $responses, array &$history): Client {
		$mock = new MockHandler($responses);
		$stack = HandlerStack::create($mock);
		$stack->push(Middleware::history($history));

		return new Client(['handler' => $stack]);
	}

	public function test_summarize_returns_trimmed_choice_content(): void {
		$history = [];
		$client = $this->build_client([
			new Response(200, [], json_encode([
				'choices' => [
					[
						'message' => [
							'content' => "  concise summary\n",
						],
					],
				],
			], JSON_THROW_ON_ERROR)),
		], $history);

		$llm_client = new LLMClient($client);

		$this->assertSame(
			'concise summary',
			$llm_client->summarize('https://example.test/chat', 'gpt-test', 'secret-key', 'Summarize this')
		);
	}

	public function test_summarize_returns_null_for_non_success_response(): void {
		$history = [];
		$client = $this->build_client([
			new Response(500, [], '{"error":"unavailable"}'),
		], $history);

		$llm_client = new LLMClient($client);

		$this->assertNull(
			$llm_client->summarize('https://example.test/chat', 'gpt-test', 'secret-key', 'Summarize this')
		);
	}

	public function test_summarize_returns_null_for_invalid_json(): void {
		$history = [];
		$client = $this->build_client([
			new Response(200, [], '{invalid json'),
		], $history);

		$llm_client = new LLMClient($client);

		$this->assertNull(
			$llm_client->summarize('https://example.test/chat', 'gpt-test', 'secret-key', 'Summarize this')
		);
	}

	public function test_summarize_returns_null_for_empty_choices(): void {
		$history = [];
		$client = $this->build_client([
			new Response(200, [], '{"choices":[]}'),
		], $history);

		$llm_client = new LLMClient($client);

		$this->assertNull(
			$llm_client->summarize('https://example.test/chat', 'gpt-test', 'secret-key', 'Summarize this')
		);
	}

	public function test_summarize_returns_null_for_transport_exception(): void {
		$history = [];
		$client = $this->build_client([
			new ConnectException('connection failed', new Request('POST', 'https://example.test/chat')),
		], $history);

		$llm_client = new LLMClient($client);

		$this->assertNull(
			$llm_client->summarize('https://example.test/chat', 'gpt-test', 'secret-key', 'Summarize this')
		);
	}

	public function test_summarize_sends_openai_chat_completion_request_shape(): void {
		$history = [];
		$client = $this->build_client([
			new Response(200, [], '{"choices":[{"message":{"content":"summary"}}]}'),
		], $history);

		$llm_client = new LLMClient($client);

		$llm_client->summarize('https://example.test/chat', 'gpt-test', 'secret-key', 'Summarize this', 17);

		$this->assertCount(1, $history);

		$request = $history[0]['request'];
		$options = $history[0]['options'];

		$this->assertSame('POST', $request->getMethod());
		$this->assertSame('https://example.test/chat', (string) $request->getUri());
		$this->assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
		$this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

		$payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);

		$this->assertSame('gpt-test', $payload['model']);
		$this->assertSame(0.2, $payload['temperature']);
		$this->assertSame('system', $payload['messages'][0]['role']);
		$this->assertNotEmpty($payload['messages'][0]['content']);
		$this->assertSame('user', $payload['messages'][1]['role']);
		$this->assertSame('Summarize this', $payload['messages'][1]['content']);

		$this->assertFalse($options['http_errors']);
		$this->assertSame(17, $options['connect_timeout']);
		$this->assertSame(17, $options['timeout']);
	}
}
