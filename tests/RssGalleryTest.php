<?php

use PHPUnit\Framework\TestCase;

final class RssGalleryTest extends TestCase {

	public function testReadCsvReturnsValidFeedRowsAndSkipsInvalidRows(): void {
		$path = tempnam(sys_get_temp_dir(), 'rss-gallery-');

		file_put_contents($path, implode("\n", [
			'title,url',
			'Valid Feed,https://example.com/feed.xml',
			'Missing URL,',
			'Invalid URL,not-a-url',
			'"Comma, Title",https://example.com/comma.xml',
			'',
		]));

		$feeds = RssGallery::readCsv($path);

		$this->assertSame([
			[
				'title' => 'Valid Feed',
				'url' => 'https://example.com/feed.xml',
			],
			[
				'title' => 'Comma, Title',
				'url' => 'https://example.com/comma.xml',
			],
		], $feeds);

		unlink($path);
	}

	public function testPreviewFromXmlReturnsTopEightItems(): void {
		$items = [];

		for ($i = 1; $i <= 9; $i++) {
			$items[] = sprintf(
				'<item><title>Article %d</title><link>https://example.com/%d</link><pubDate>Wed, %02d May 2026 10:00:00 GMT</pubDate></item>',
				$i,
				$i,
				$i
			);
		}

		$xml = sprintf(
			'<?xml version="1.0"?><rss version="2.0"><channel><title>Example Feed</title><link>https://example.com</link>%s</channel></rss>',
			implode('', $items)
		);

		$preview = RssGallery::previewFromXml($xml, 'https://example.com/feed.xml');

		$this->assertSame('Example Feed', $preview['title']);
		$this->assertSame('https://example.com', $preview['site_url']);
		$this->assertCount(8, $preview['items']);
		$this->assertSame('Article 1', $preview['items'][0]['title']);
		$this->assertSame('https://example.com/1', $preview['items'][0]['link']);
		$this->assertIsInt($preview['items'][0]['timestamp']);
		$this->assertNotEmpty($preview['items'][0]['date']);
	}

	public function testPreviewFromXmlRejectsInvalidXml(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unable to parse feed');

		RssGallery::previewFromXml('<broken>', 'https://example.com/feed.xml');
	}
}
