<?php

class AI_FeedDigest {
	// Configurable per-user: see Prefs::AI_DIGEST_* constants

	private const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert news curator. Your task is to select the most important, insightful, and meaningful articles from a list of RSS feed items.

Selection criteria:
- Prioritize articles with depth, analysis, or unique perspectives over breaking news or clickbait
- Prefer original reporting and investigative pieces
- Avoid redundancy: if multiple articles cover the same story, pick only the best one
- Include a diversity of topics where possible

Return ONLY valid JSON in this exact format, with no other text:
{"articles":[{"index":<number>,"reason":"<one-line explanation in Chinese>"}]}
PROMPT;

	private readonly PDO $pdo;
	private readonly LLMClient $client;

	function __construct(?PDO $pdo = null, ?LLMClient $client = null) {
		$this->pdo = $pdo ?? Db::pdo();
		$this->client = $client ?? new LLMClient();
	}

	static function is_configured(int $owner_uid): bool {
		return AISummary::is_configured($owner_uid);
	}

	static function is_enabled_for_feed(int $feed_id): bool {
		$row = ORM::for_table('ttrss_feeds')
			->select('ai_digest_enabled')
			->find_one($feed_id);

		return $row ? (bool) $row->ai_digest_enabled : false;
	}

	static function toggle_feed(int $feed_id, bool $enabled): void {
		$feed = ORM::for_table('ttrss_feeds')->find_one($feed_id);

		if ($feed) {
			$feed->ai_digest_enabled = $enabled;
			$feed->save();

			if (!$enabled) {
				Db::pdo()->prepare("DELETE FROM ttrss_ai_digests WHERE feed_id = ?")->execute([$feed_id]);
			}
		}
	}

	/** @return ?array{articles: list<array{id:int, title:string, reason:string, link:string}>, generated_at: string, total_unread: int} */
	static function get_digest(int $feed_id, int $owner_uid, bool $force = false): ?array {
		$instance = new self();

		if (!self::is_configured($owner_uid)) return null;

		if (!$force) {
			$cached = $instance->get_cached($feed_id, $owner_uid);
			if ($cached !== null) return $cached;
		}

		return $instance->generate($feed_id, $owner_uid);
	}

	static function regenerate_stale(): int {
		$instance = new self();
		$regenerated = 0;

		$sth = $instance->pdo->prepare(
			"SELECT f.id, f.owner_uid FROM ttrss_feeds f
			WHERE f.ai_digest_enabled = true
			AND (
				NOT EXISTS (SELECT 1 FROM ttrss_ai_digests d WHERE d.feed_id = f.id AND d.owner_uid = f.owner_uid)
				OR EXISTS (SELECT 1 FROM ttrss_ai_digests d WHERE d.feed_id = f.id AND d.owner_uid = f.owner_uid
					AND d.generated_at < NOW() - CAST(:ttl AS integer) * INTERVAL '1 minute')
			)");

		$sth->execute([":ttl" => 60]);

		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$owner = (int) $row["owner_uid"];
			if (self::is_configured($owner)) {
				$ttl_minutes = (int) Prefs::get(Prefs::AI_DIGEST_CACHE_TTL_MINUTES, $owner);
				if ($instance->is_stale((int) $row["id"], $owner, $ttl_minutes)) {
					$result = $instance->generate((int) $row["id"], $owner);
					if ($result !== null) $regenerated++;
				}
			}
		}

		return $regenerated;
	}

	/** @return ?array{articles: list<array{id:int, title:string, reason:string, link:string}>, generated_at: string, total_unread: int} */
	private function generate(int $feed_id, int $owner_uid): ?array {
		set_time_limit(60);

		$articles = $this->fetch_unread_articles($feed_id, $owner_uid);

		if (count($articles) === 0) {
			$digest = [
				"articles" => [],
				"generated_at" => date("Y-m-d H:i:s"),
				"total_unread" => 0,
			];
			$this->cache_digest($feed_id, $owner_uid, $digest);
			return $digest;
		}

		$feed_title = $this->get_feed_title($feed_id);

		$select_count = (int) Prefs::get(Prefs::AI_DIGEST_ARTICLE_COUNT, $owner_uid);
		$max_prompt_chars = (int) Prefs::get(Prefs::AI_DIGEST_MAX_PROMPT_CHARS, $owner_uid);
		$prompt = $this->build_prompt($articles, $feed_title, $select_count, $max_prompt_chars);

		$endpoint = trim((string) Prefs::get(Prefs::AI_ENDPOINT, $owner_uid));
		$model = trim((string) Prefs::get(Prefs::AI_MODEL, $owner_uid));
		$api_key = trim((string) Prefs::get(Prefs::AI_API_KEY, $owner_uid));

		try {
			$response = $this->client->summarize($endpoint, $model, $api_key, $prompt, (int) Prefs::get(Prefs::AI_DIGEST_LLM_TIMEOUT, $owner_uid), self::SYSTEM_PROMPT);
		} catch (Throwable $e) {
			Debug::log("AI digest: LLM exception for feed $feed_id: " . $e->getMessage(), Debug::LOG_NORMAL);
			return null;
		}

		if ($response === null) {
			Debug::log("AI digest: LLM returned null for feed $feed_id (endpoint: $endpoint, model: $model)", Debug::LOG_NORMAL);
			return null;
		}

		$parsed = $this->parse_response($response, $articles);

		if ($parsed === null) {
			Debug::log("AI digest: parse failed for feed $feed_id, raw response: " . mb_substr($response, 0, 500), Debug::LOG_NORMAL);
			return null;
		}

		$digest = [
			"articles" => $parsed,
			"generated_at" => date("Y-m-d H:i:s"),
			"total_unread" => count($articles),
		];

		$this->cache_digest($feed_id, $owner_uid, $digest);

		return $digest;
	}

	/** @return list<array{id:int, title:string, excerpt:string, link:string}> */
	private function fetch_unread_articles(int $feed_id, int $owner_uid): array {
		$sth = $this->pdo->prepare(
			"SELECT e.id, e.title, e.content, e.link
			FROM ttrss_entries e
			JOIN ttrss_user_entries ue ON ue.ref_id = e.id
			WHERE ue.feed_id = :feed_id
			AND ue.owner_uid = :owner_uid
			AND ue.unread = true
			AND e.date_entered > NOW() - CAST(:hours AS integer) * INTERVAL '1 hour'
			ORDER BY e.date_entered DESC
			LIMIT :limit"
		);

		$sth->execute([
			":feed_id" => $feed_id,
			":owner_uid" => $owner_uid,
			":hours" => (int) Prefs::get(Prefs::AI_DIGEST_LOOKBACK_HOURS, $owner_uid),
			":limit" => (int) Prefs::get(Prefs::AI_DIGEST_MAX_ARTICLES_SENT, $owner_uid),
		]);

		$articles = [];

		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$text = \Soundasleep\Html2Text::convert((string) $row["content"]);
			$text = preg_replace('/\s+/u', ' ', strip_tags($text)) ?? "";
			$text = trim($text);

			$articles[] = [
				"id" => (int) $row["id"],
				"title" => mb_substr(trim(strip_tags((string) $row["title"])), 0, 300, "utf-8"),
				"excerpt" => mb_substr($text, 0, 500, "utf-8"),
				"link" => (string) $row["link"],
			];
		}

		return $articles;
	}

	private function get_feed_title(int $feed_id): string {
		$row = ORM::for_table('ttrss_feeds')
			->select('title')
			->find_one($feed_id);

		return $row ? (string) $row->title : "";
	}

	/** @param list<array{id:int, title:string, excerpt:string, link:string}> $articles */
	private function build_prompt(array $articles, string $feed_title, int $select_count, int $max_prompt_chars): string {
			$count = count($articles);
			$select_count = min($select_count, $count);

			$header = "Below is a list of unread articles from the RSS feed \"$feed_title\" from the last 24 hours.\n\n";
			$header .= "Select the $select_count most important, insightful, or meaningful articles. ";
			$header .= "For each selected article, provide the article number (1-based index) and a one-line reason in Chinese explaining why it matters.\n\n";

			$prompt = $header;
			$included = 0;

			foreach ($articles as $i => $article) {
				$num = $i + 1;
				$line = "{$num}. Title: {$article["title"]}\n";
				if ($article["excerpt"] !== "") {
					$line .= "   Excerpt: {$article["excerpt"]}\n";
				}

				if (mb_strlen($prompt . $line, "utf-8") > $max_prompt_chars) break;

				$prompt .= $line;
				$included++;
			}

			// Rebuild header with actual count
			$final_header = "Below is a list of $included unread articles from the RSS feed \"$feed_title\" from the last 24 hours.\n\n";
			$final_header .= "Select the " . min($select_count, $included) . " most important, insightful, or meaningful articles. ";
			$final_header .= "For each selected article, provide the article number (1-based index) and a one-line reason in English explaining why it matters.\n\n";

			$prompt = $final_header . mb_substr($prompt, mb_strlen($header, "utf-8"), null, "utf-8");

			return $prompt;
		}

	private function parse_response(string $response, array $articles): ?array {
		$response = trim($response);

		$json_start = strpos($response, '{');
		$json_end = strrpos($response, '}');

		if ($json_start === false || $json_end === false) {
			Debug::log("AI digest: no JSON found in LLM response", Debug::LOG_VERBOSE);
			return null;
		}

		$json_str = substr($response, (int) $json_start, (int) $json_end - (int) $json_start + 1);

		try {
			$data = json_decode($json_str, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			Debug::log("AI digest: failed to parse JSON from LLM response: " . mb_substr($json_str, 0, 200), Debug::LOG_VERBOSE);
			return null;
		}

		if (!isset($data["articles"]) || !is_array($data["articles"])) {
			Debug::log("AI digest: missing or invalid 'articles' array in LLM response", Debug::LOG_VERBOSE);
			return null;
		}

		$result = [];
		$seen_ids = [];

		foreach ($data["articles"] as $item) {
			$index = (int) ($item["index"] ?? 0);
			$reason = trim((string) ($item["reason"] ?? ""));

			if ($index < 1 || $index > count($articles)) continue;
			if ($reason === "") continue;

			$article = $articles[$index - 1];

			if (in_array($article["id"], $seen_ids, true)) continue;
			$seen_ids[] = $article["id"];

			$result[] = [
				"id" => $article["id"],
				"title" => $article["title"],
				"reason" => $reason,
				"link" => $article["link"],
			];

			if (count($result) >= 15) break;
		}

		return $result;
	}

	/** @param array{articles: list<array>, generated_at: string, total_unread: int} $digest */
	private function cache_digest(int $feed_id, int $owner_uid, array $digest): void {
		try {
			$sth = $this->pdo->prepare(
				"INSERT INTO ttrss_ai_digests (feed_id, owner_uid, content, generated_at)
				VALUES (:feed_id, :owner_uid, :content, NOW())
				ON CONFLICT (feed_id, owner_uid) DO UPDATE SET
					content = EXCLUDED.content,
					generated_at = NOW()"
			);

			$sth->execute([
				":feed_id" => $feed_id,
				":owner_uid" => $owner_uid,
				":content" => json_encode($digest),
			]);
		} catch (Throwable $e) {
			Debug::log("AI digest cache failed for feed $feed_id: " . $e->getMessage(), Debug::LOG_VERBOSE);
		}
	}

	private function is_stale(int $feed_id, int $owner_uid, int $ttl_minutes): bool {
		$sth = $this->pdo->prepare(
			"SELECT 1 FROM ttrss_ai_digests
			WHERE feed_id = :feed_id AND owner_uid = :owner_uid
			AND generated_at > NOW() - CAST(:ttl AS integer) * INTERVAL '1 minute'"
		);
		$sth->execute([
			":feed_id" => $feed_id,
			":owner_uid" => $owner_uid,
			":ttl" => $ttl_minutes,
		]);
		return !$sth->fetch();
	}

	/** @return ?array{articles: list<array{id:int, title:string, reason:string, link:string}>, generated_at: string, total_unread: int} */
	private function get_cached(int $feed_id, int $owner_uid, ?int $ttl_minutes = null): ?array {
		$ttl = $ttl_minutes ?? (int) Prefs::get(Prefs::AI_DIGEST_CACHE_TTL_MINUTES, $owner_uid);

		$sth = $this->pdo->prepare(
			"SELECT content FROM ttrss_ai_digests
			WHERE feed_id = :feed_id AND owner_uid = :owner_uid
			AND generated_at > NOW() - CAST(:ttl AS integer) * INTERVAL '1 minute'"
		);

		$sth->execute([
			":feed_id" => $feed_id,
			":owner_uid" => $owner_uid,
			":ttl" => $ttl,
		]);

		$row = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$row) return null;

		try {
			return json_decode($row["content"], true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}
	}
}
