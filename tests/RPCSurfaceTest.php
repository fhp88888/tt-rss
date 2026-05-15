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

	public function test_widescreen_mode_is_forced_in_runtime_info(): void {
		$source = file_get_contents(__DIR__ . '/../classes/RPC.php');

		$this->assertIsString($source);
		$this->assertStringContainsString('$params["widescreen"] = 1;', $source);
		$this->assertStringNotContainsString('$params["widescreen"] = (int) Prefs::get(Prefs::WIDESCREEN_MODE', $source);
	}

	public function test_widescreen_toggle_is_not_exposed(): void {
		$rpc_source = file_get_contents(__DIR__ . '/../classes/RPC.php');
		$app_source = file_get_contents(__DIR__ . '/../js/App.js');
		$index_source = file_get_contents(__DIR__ . '/../index.php');

		$this->assertIsString($rpc_source);
		$this->assertIsString($app_source);
		$this->assertIsString($index_source);
		$this->assertStringNotContainsString('"toggle_widescreen"', $rpc_source);
		$this->assertStringNotContainsString('"toggle_widescreen"', $app_source);
		$this->assertStringNotContainsString('qmcToggleWidescreen', $index_source);
	}

	public function test_initial_index_markup_uses_widescreen_layout(): void {
		$source = file_get_contents(__DIR__ . '/../index.php');

		$this->assertIsString($source);
		$this->assertStringContainsString('id="headlines-wrap-inner" dojoType="dijit.layout.BorderContainer" region="center" design="sidebar"', $source);
		$this->assertStringContainsString('id="headlines-frame" dojoType="dijit.layout.ContentPane" tabindex="0" data-is-wide-screen="true"', $source);
		$this->assertStringContainsString('id="content-insert" dojoType="dijit.layout.ContentPane" region="trailing"', $source);
		$this->assertStringNotContainsString('id="content-insert" dojoType="dijit.layout.ContentPane" region="bottom"', $source);
	}

	public function test_widescreen_setter_keeps_article_pane_attached(): void {
		$source = file_get_contents(__DIR__ . '/../js/App.js');

		$this->assertIsString($source);
		$this->assertStringContainsString('headlines_wrap.addChild(content_insert);', $source);
		$this->assertStringContainsString('headlines_wrap.resize();', $source);
		$this->assertDoesNotMatchRegularExpression('/setWideScreenMode: function\\(\\) \\{.*Article\\.close\\(\\);/s', $source);
	}

	public function test_empty_article_pane_has_placeholder(): void {
		$source = file_get_contents(__DIR__ . '/../js/Article.js');

		$this->assertIsString($source);
		$this->assertStringContainsString('article-empty-state', $source);
		$this->assertStringContainsString('Pick an article to start reading.', $source);
		$this->assertStringNotContainsString('removeChild(', $source);
	}

	public function test_scroll_past_mark_read_preference_is_exposed_and_default_on(): void {
		$prefs_source = file_get_contents(__DIR__ . '/../classes/Prefs.php');
		$rpc_source = file_get_contents(__DIR__ . '/../classes/RPC.php');
		$pref_ui_source = file_get_contents(__DIR__ . '/../classes/Pref_Prefs.php');

		$this->assertIsString($prefs_source);
		$this->assertIsString($rpc_source);
		$this->assertIsString($pref_ui_source);
		$this->assertStringContainsString('const AUTO_MARK_READ_ON_SCROLL = "AUTO_MARK_READ_ON_SCROLL";', $prefs_source);
		$this->assertStringContainsString('Prefs::AUTO_MARK_READ_ON_SCROLL => [ true, Config::T_BOOL ]', $prefs_source);
		$this->assertStringContainsString('Prefs::AUTO_MARK_READ_ON_SCROLL] as $param', $rpc_source);
		$this->assertStringContainsString('Mark articles as read after scrolling past them', $pref_ui_source);
	}

	public function test_headlines_auto_marks_seen_rows_after_they_leave_viewport(): void {
		$source = file_get_contents(__DIR__ . '/../js/Headlines.js');

		$this->assertIsString($source);
		$this->assertStringContainsString('_auto_mark_read_seen_ids: new Set()', $source);
		$this->assertStringContainsString('updateAutoMarkReadOnScroll: function ()', $source);
		$this->assertStringContainsString('App.getInitParam("auto_mark_read_on_scroll") !== 1', $source);
		$this->assertStringContainsString('this.rowIntersectsViewport(row, container)', $source);
		$this->assertStringContainsString("row.classList.remove('Unread');", $source);
		$this->assertStringContainsString('this.resetAutoMarkReadState();', $source);
	}
}
