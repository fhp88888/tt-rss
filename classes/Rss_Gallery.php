<?php

class Rss_Gallery extends Handler_Protected {
	const CSV_PATH = __DIR__ . '/../data/rss-gallery.csv';
	const CACHE_DIR = __DIR__ . '/../cache/rss-gallery';
	const CACHE_TTL = 600;

	function csrf_ignore(string $method): bool {
		return in_array($method, ['list', 'preview']);
	}

	function list(): void {
		print json_encode([
			'feeds' => RssGallery::readCsv(self::CSV_PATH),
		]);
	}

	function preview(): void {
		$url = UrlHelper::validate(clean($_REQUEST['url'] ?? ''));

		if (!$url) {
			$this->print_error(__('Invalid feed URL.'));
			return;
		}

		$cached = $this->read_cache($url);

		if ($cached) {
			print json_encode($cached);
			return;
		}

		$contents = UrlHelper::fetch([
			'url' => $url,
			'timeout' => Config::get(Config::FEED_FETCH_TIMEOUT),
		]);

		if (empty($contents)) {
			$this->print_error(
				__('Could not download feed.'),
				truncate_string(clean(UrlHelper::$fetch_last_error), 250, '…')
			);
			return;
		}

		try {
			$preview = RssGallery::previewFromXml($contents, $url);
			$this->write_cache($url, $preview);

			print json_encode($preview);
		} catch (InvalidArgumentException $e) {
			$this->print_error(__('Unable to parse feed.'), truncate_string(clean($e->getMessage()), 250, '…'));
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_cache(string $url): ?array {
		$path = $this->cache_path($url);

		if (!$path || !is_readable($path) || filemtime($path) < time() - self::CACHE_TTL)
			return null;

		$data = json_decode((string)file_get_contents($path), true);

		return is_array($data) ? $data : null;
	}

	/**
	 * @param array<string, mixed> $preview
	 */
	private function write_cache(string $url, array $preview): void {
		$path = $this->cache_path($url, true);

		if ($path)
			file_put_contents($path, json_encode($preview));
	}

	private function cache_path(string $url, bool $create_dir = false): ?string {
		if ($create_dir && !is_dir(self::CACHE_DIR) && !mkdir(self::CACHE_DIR, 0777, true))
			return null;

		if (!is_dir(self::CACHE_DIR))
			return null;

		return self::CACHE_DIR . '/' . hash('sha256', $url) . '.json';
	}

	private function print_error(string $message, string $detail = ''): void {
		print json_encode([
			'error' => $message,
			'detail' => $detail,
		]);
	}
}
