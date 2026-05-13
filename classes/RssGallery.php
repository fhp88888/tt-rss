<?php

class RssGallery {
	const PREVIEW_LIMIT = 8;

	/**
	 * @return array<int, array{title: string, url: string}>
	 */
	static function readCsv(string $path): array {
		if (!is_readable($path))
			return [];

		$handle = fopen($path, 'r');

		if (!$handle)
			return [];

		$feeds = [];
		$is_first_row = true;

		while (($row = fgetcsv($handle)) !== false) {
			$title = trim((string)($row[0] ?? ''));
			$url = trim((string)($row[1] ?? ''));

			if ($is_first_row) {
				$is_first_row = false;

				if (mb_strtolower($title) === 'title' && mb_strtolower($url) === 'url')
					continue;
			}

			$url = UrlHelper::validate($url) ?: '';

			if ($title !== '' && $url !== '') {
				$feeds[] = [
					'title' => $title,
					'url' => $url,
				];
			}
		}

		fclose($handle);

		return $feeds;
	}

	/**
	 * @return array{title: string, site_url: string, items: array<int, array{title: string, link: string, timestamp: int, date: string}>}
	 */
	static function previewFromXml(string $xml, string $url, int $limit = self::PREVIEW_LIMIT): array {
		$parser = new FeedParser($xml);

		if (!$parser->init())
			throw new InvalidArgumentException('Unable to parse feed: ' . $parser->error());

		$items = [];

		foreach ($parser->get_items() as $item) {
			if (count($items) >= $limit)
				break;

			$timestamp = (int)$item->get_date();

			$items[] = [
				'title' => $item->get_title() ?: __('Untitled'),
				'link' => UrlHelper::rewrite_relative($url, $item->get_link()),
				'timestamp' => $timestamp,
				'date' => self::formatDate($timestamp),
			];
		}

		return [
			'title' => $parser->get_title() ?: __('Untitled feed'),
			'site_url' => UrlHelper::rewrite_relative($url, $parser->get_link()),
			'items' => $items,
		];
	}

	private static function formatDate(int $timestamp): string {
		if ($timestamp <= 0)
			return __('Unknown');

		if (!empty($_SESSION['uid'])) {
			return TimeHelper::make_local_datetime(gmdate('Y-m-d H:i:s', $timestamp), owner_uid: $_SESSION['uid']);
		}

		return gmdate('Y-m-d H:i', $timestamp);
	}
}
