<?php
use PHPUnit\Framework\TestCase;

final class HeadlineThumbnailRenderingTest extends TestCase {
	public function test_headline_list_renders_article_thumbnail_preview(): void {
		$feeds_php = file_get_contents(dirname(__DIR__) . "/classes/Feeds.php");
		$headlines_js = file_get_contents(dirname(__DIR__) . "/js/Headlines.js");
		$theme_less = file_get_contents(dirname(__DIR__) . "/themes/light/tt-rss.less");

		$this->assertStringContainsString('Article::_get_image', $feeds_php);
		$this->assertStringContainsString('"flavor_image"', $feeds_php);
		$this->assertStringContainsString('headline-thumbnail', $headlines_js);
		$this->assertStringContainsString('headline-thumbnail-image', $headlines_js);
		$this->assertStringContainsString('validateThumbnail', $headlines_js);
		$this->assertStringContainsString('naturalWidth', $headlines_js);
		$this->assertStringContainsString('naturalHeight', $headlines_js);
		$this->assertStringContainsString('onerror', $headlines_js);
		$this->assertStringContainsString('closest(\'.headline-thumbnail\').remove()', $headlines_js);
		$this->assertStringContainsString('.headline-thumbnail', $theme_less);
		$this->assertStringContainsString('object-fit : cover', $theme_less);
	}
}
