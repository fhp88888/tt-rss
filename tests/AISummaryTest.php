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
}
