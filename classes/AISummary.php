<?php
use GuzzleHttp\Promise\Each;

class AISummary {
	private const ARTICLE_PROMPT_LIMIT = 6000;

	private readonly PDO $pdo;
	private readonly LLMClient $client;

	function __construct(?PDO $pdo = null, ?LLMClient $client = null) {
		$this->pdo = $pdo ?? Db::pdo();
		$this->client = $client ?? new LLMClient();
	}

	static function is_configured(int $owner_uid): bool {
		return (bool) Prefs::get(Prefs::AI_SUMMARIES_ENABLED, $owner_uid) &&
			trim((string) Prefs::get(Prefs::AI_ENDPOINT, $owner_uid)) !== "" &&
			trim((string) Prefs::get(Prefs::AI_MODEL, $owner_uid)) !== "" &&
			trim((string) Prefs::get(Prefs::AI_API_KEY, $owner_uid)) !== "";
	}

	static function build_prompt(string $title, string $content, int $max_chars): string {
		$text = \Soundasleep\Html2Text::convert($content);
		$text = preg_replace('/\s+/u', ' ', strip_tags($text)) ?? "";
		$text = trim($text);

		if (mb_strlen($text, "utf-8") > self::ARTICLE_PROMPT_LIMIT) {
			$text = mb_substr($text, 0, self::ARTICLE_PROMPT_LIMIT, "utf-8");
		}

		$title = trim(strip_tags($title));
		$max_chars = max(40, min(500, $max_chars));

		return "Summarize this RSS article in the same language as the article. " .
			"Return plain text only, no Markdown, no bullet list, no preamble. " .
			"Keep it under {$max_chars} characters.\n\n" .
			"Title: {$title}\n\nArticle:\n{$text}";
	}

	function generate_for_entry(int $entry_id, int $owner_uid, string $title, string $content, string $content_hash): bool {
		try {
			if (!self::is_configured($owner_uid)) return false;

			$sth = $this->pdo->prepare("SELECT ai_summary, ai_summary_content_hash FROM ttrss_entries WHERE id = ?");
			$sth->execute([$entry_id]);

			if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
				if (!empty($row["ai_summary"]) && $row["ai_summary_content_hash"] === $content_hash) {
					return false;
				}
			}

			$endpoint = trim((string) Prefs::get(Prefs::AI_ENDPOINT, $owner_uid));
			$model = trim((string) Prefs::get(Prefs::AI_MODEL, $owner_uid));
			$api_key = trim((string) Prefs::get(Prefs::AI_API_KEY, $owner_uid));
			$max_chars = (int) Prefs::get(Prefs::AI_SUMMARY_MAX_CHARS, $owner_uid);
			$prompt = self::build_prompt($title, $content, $max_chars);

			$summary = $this->client->summarize($endpoint, $model, $api_key, $prompt);
			if ($summary === null) return false;

			$summary = trim(strip_tags($summary));
			if ($summary === "") return false;

			$summary = truncate_string($summary, max(40, min(500, $max_chars)), "");

			$sth = $this->pdo->prepare("UPDATE ttrss_entries
				SET ai_summary = :summary,
					ai_summary_content_hash = :content_hash,
					ai_summary_generated_at = NOW()
				WHERE id = :entry_id");

			return $sth->execute([
				":summary" => $summary,
				":content_hash" => $content_hash,
				":entry_id" => $entry_id,
			]);
		} catch (Throwable $e) {
			Debug::log("AI summary generation failed for entry $entry_id: " . $e->getMessage(), Debug::LOG_VERBOSE);
		}

		return false;
	}

	/**
	 * @param array<int, array{entry_id:int,title:string,content:string,content_hash:string}> $entries
	 */
	function generate_for_entries(array $entries, int $owner_uid, int $concurrency): int {
		try {
			if ($concurrency <= 0 || count($entries) === 0 || !self::is_configured($owner_uid)) return 0;

			$endpoint = trim((string) Prefs::get(Prefs::AI_ENDPOINT, $owner_uid));
			$model = trim((string) Prefs::get(Prefs::AI_MODEL, $owner_uid));
			$api_key = trim((string) Prefs::get(Prefs::AI_API_KEY, $owner_uid));
			$max_chars = (int) Prefs::get(Prefs::AI_SUMMARY_MAX_CHARS, $owner_uid);
			$tasks = [];

			foreach ($entries as $entry) {
				$tasks[(int) $entry["entry_id"]] = $entry;
			}

			$generated = 0;

			$requests = function () use ($tasks, $endpoint, $model, $api_key, $max_chars) {
				foreach ($tasks as $entry_id => $entry) {
					if (!$this->needs_generation($entry_id, $entry["content_hash"])) continue;

					$prompt = self::build_prompt($entry["title"], $entry["content"], $max_chars);

					yield $entry_id => $this->client->summarize_async($endpoint, $model, $api_key, $prompt);
				}
			};

			Each::ofLimit(
				$requests(),
				$concurrency,
				function (?string $summary, int $entry_id) use ($tasks, $max_chars, &$generated): void {
					if ($summary === null || !isset($tasks[$entry_id])) return;

					$summary = trim(strip_tags($summary));
					if ($summary === "") return;

					$summary = truncate_string($summary, max(40, min(500, $max_chars)), "");

					if ($this->store_summary($entry_id, $summary, $tasks[$entry_id]["content_hash"])) {
						++$generated;
					}
				},
				function ($reason, int $entry_id): void {
					$message = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
					Debug::log("AI summary generation failed for entry $entry_id: $message", Debug::LOG_VERBOSE);
				}
			)->wait();

			return $generated;
		} catch (Throwable $e) {
			Debug::log("AI summary queue failed: " . $e->getMessage(), Debug::LOG_VERBOSE);
		}

		return 0;
	}

	private function needs_generation(int $entry_id, string $content_hash): bool {
		$sth = $this->pdo->prepare("SELECT ai_summary, ai_summary_content_hash FROM ttrss_entries WHERE id = ?");
		$sth->execute([$entry_id]);

		if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			return empty($row["ai_summary"]) || $row["ai_summary_content_hash"] !== $content_hash;
		}

		return false;
	}

	private function store_summary(int $entry_id, string $summary, string $content_hash): bool {
		$sth = $this->pdo->prepare("UPDATE ttrss_entries
			SET ai_summary = :summary,
				ai_summary_content_hash = :content_hash,
				ai_summary_generated_at = NOW()
			WHERE id = :entry_id");

		return $sth->execute([
			":summary" => $summary,
			":content_hash" => $content_hash,
			":entry_id" => $entry_id,
		]);
	}
}
