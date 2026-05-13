<?php
use PHPUnit\Framework\TestCase;

final class RPCSurfaceTest extends TestCase {
	public function test_runtime_info_does_not_expose_combined_mode_state(): void {
		$source = file_get_contents(__DIR__ . '/../classes/RPC.php');

		$this->assertIsString($source);
		$this->assertStringNotContainsString('combined_display_mode', $source);
		$this->assertStringNotContainsString('cdm_enable_grid', $source);
		$this->assertStringNotContainsString('cdm_expanded', $source);
	}

	public function test_hotkeys_info_does_not_expose_combined_mode_actions(): void {
		$source = file_get_contents(__DIR__ . '/../classes/RPC.php');

		$this->assertIsString($source);
		$this->assertStringNotContainsString('feed_toggle_grid', $source);
		$this->assertStringNotContainsString('combined mode', $source);
	}
}
