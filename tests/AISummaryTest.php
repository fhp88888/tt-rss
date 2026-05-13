<?php
use PHPUnit\Framework\TestCase;

final class AISummaryTest extends TestCase {
	public function test_build_prompt_requests_chinese_summary(): void {
		$prompt = AISummary::build_prompt(
			'Example title',
			'<p>This is an English article body.</p>',
			180
		);

		$this->assertStringContainsString('请用中文总结以下 RSS 文章。', $prompt);
		$this->assertStringContainsString('摘要必须使用中文', $prompt);
		$this->assertStringContainsString('控制在 180 个字符以内', $prompt);
		$this->assertStringContainsString("标题：Example title", $prompt);
		$this->assertStringContainsString("文章：\nThis is an English article body.", $prompt);
	}

	public function test_has_current_summary_requires_non_empty_matching_hash(): void {
		$this->assertTrue(AISummary::has_current_summary('Summary', 'hash-a', 'hash-a'));
		$this->assertFalse(AISummary::has_current_summary('', 'hash-a', 'hash-a'));
		$this->assertFalse(AISummary::has_current_summary('Summary', 'hash-b', 'hash-a'));
		$this->assertFalse(AISummary::has_current_summary(null, 'hash-a', 'hash-a'));
	}

	public function test_should_display_cached_summary_requires_user_opt_in(): void {
		$this->assertTrue(AISummary::should_display_cached_summary(true, 'Summary', 'hash-a', 'hash-a'));
		$this->assertFalse(AISummary::should_display_cached_summary(false, 'Summary', 'hash-a', 'hash-a'));
	}
}
