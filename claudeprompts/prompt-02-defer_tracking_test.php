<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

require_once __DIR__ . '/fixtures/tracking.php';

/**
 * Deferred items with update tracking: feeds are parsed by the real parse_feed(), so a
 * parse_item_append listener defers items exactly as in production, and fetch_all() runs
 * with the in-memory forum and tracker.
 */
class defer_tracking_test extends fixtures\tracking_test_case
{
	/** @var array guid => true for items the parse_item_append listener defers */
	protected $deferred = array();

	public function setUp(): void
	{
		parent::setUp();
		$args = \Closure::bind(function () {
			return array($this->config, $this->config_text, $this->user, $this->language, $this->auth, $this->db, $this->log,
				$this->phpbb_root_path, $this->php_ext, $this->phpbb_dispatcher, $this->tracker);
		}, $this->driver, \ger\feedpostbot\classes\driver::class)();
		$driver = new defer_tracking_driver(...$args);
		$driver->forum = $this->forum;
		$driver->bot_users = $this->driver->bot_users;
		$this->driver = $driver;
		$this->listeners['ger.feedpostbot.parse_item_append'] = function ($data) {
			$data['defer'] = isset($this->deferred[$data['append']['guid']]);
			return $data;
		};
	}

	/** One cron run with an RSS feed of the given items: guid => body, newest first */
	protected function run_rss(array $items, array $links = array())
	{
		$xml = '<rss version="2.0"><channel><title>Feed</title>';
		foreach ($items as $guid => $body)
		{
			$guid = (string) $guid;
			$id = strpos($guid, '#') === false ? $guid : strstr($guid, '#', true);
			$link = isset($links[$guid]) ? $links[$guid] : 'https://example.com/' . $id;
			$xml .= '<item><guid>' . $id . '</guid><title>Title ' . $id . '</title><link>' . $link . '</link><description>&lt;p&gt;'
				. $body . '&lt;/p&gt;</description></item>';
		}
		$this->driver->xml = $xml . '</channel></rss>';
		$this->driver->functions_loaded = false;
		$this->driver->current_state = array();
		return $this->driver->fetch_all();
	}

	protected function key($guid)
	{
		return hash('sha256', 'guid:' . $guid);
	}

	protected function subjects()
	{
		return array_column($this->calls('post'), 'subject');
	}

	public function test_deferred_new_item_is_neither_posted_nor_mapped_and_is_retried()
	{
		$this->deferred = array('b' => true);
		$this->assertSame(2, $this->run_rss(array('a' => 'A', 'b' => 'B', 'c' => 'C')));
		$this->assertSame(array('Title c', 'Title a'), $this->subjects());
		$this->assertCount(2, $this->tracker->rows);
		$this->assertSame(array(), $this->tracker->load($this->saved[0]['source_uid'], array($this->key('b'))));
		$this->assertArrayNotHasKey($this->key('b'), $this->saved[0]['handled']);
		$this->assertSame('a', $this->saved[0]['latest']['guid']);

		$this->deferred = array();
		$this->assertSame(1, $this->run_rss(array('a' => 'A', 'b' => 'B', 'c' => 'C')));
		$this->assertSame(array('Title c', 'Title a', 'Title b'), $this->subjects());
		$this->assertCount(3, $this->tracker->rows);
		$this->assertArrayHasKey($this->key('b'), $this->saved[0]['handled']);
	}

	public function test_deferred_tracked_item_is_left_untouched_until_it_is_offered_again()
	{
		$this->settings(array('update_reply' => 1));
		$this->assertSame(2, $this->run_rss(array('a' => 'A', 'b' => 'B')));
		$rows = $this->tracker->rows;
		$writes = count($this->tracker->writes);

		// a changed, but is deferred; b changed and is processed normally.
		$this->deferred = array('a' => true);
		$this->assertSame(0, $this->run_rss(array('a' => 'A changed', 'b' => 'B changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertSame('Title b', $this->calls('edit')[0]['subject']);
		$a = $this->tracker->load($this->saved[0]['source_uid'], array($this->key('a')))[$this->key('a')];
		$before = $rows[$a['tracking_id']];
		foreach (array('source_hash', 'content_hash', 'notified_hash', 'pending_op', 'item_state', 'reply_retry_count', 'reply_next_retry') as $field)
		{
			$this->assertSame($before[$field], $a[$field], $field);
		}
		$this->assertGreaterThan($writes, count($this->tracker->writes));

		// Offered again: the change of a is applied now.
		$this->deferred = array();
		$this->run_rss(array('a' => 'A changed', 'b' => 'B changed'));
		$this->assertSame(array('Title b', 'Title a'), array_column($this->calls('edit'), 'subject'));
		$this->assertCount(2, $this->topics());
	}

	public function test_deferred_item_does_not_make_an_identity_ambiguous()
	{
		// Two current items share one guid: without deferral the identity is ambiguous and not mapped.
		$links = array('dup#1' => 'https://example.com/first', 'dup#2' => 'https://example.com/second');
		$this->run_rss(array('dup#1' => 'One', 'dup#2' => 'Two'), $links);
		$this->assertCount(1, $this->topics());
		$this->assertCount(0, $this->tracker->rows);
	}

	public function test_identity_is_unambiguous_when_its_other_item_is_deferred()
	{
		$links = array('dup#1' => 'https://example.com/first', 'dup#2' => 'https://example.com/second');
		$this->listeners['ger.feedpostbot.parse_item_append'] = function ($data) {
			$data['defer'] = $data['append']['link'] === 'https://example.com/second';
			return $data;
		};
		$this->assertSame(1, $this->run_rss(array('dup#1' => 'One', 'dup#2' => 'Two'), $links));
		$this->assertCount(1, $this->topics());
		$this->assertSame($this->key('dup'), $this->row()['item_key']);
	}

	// --- Upgrade from the former single latest marker -----------------------------------

	/** Source state of an upgraded board: only the latest marker, no handled history yet */
	protected function legacy_marker($guid)
	{
		unset($this->saved[0]['handled']);
		$this->saved[0]['latest'] = array('guid' => $guid, 'link' => 'https://example.com/' . $guid, 'pubDate' => 0);
	}

	protected function legacy_feed()
	{
		// new2 and new1 appeared after the last legacy run; marker and older ones were posted by it.
		return array('new2' => 'N2', 'new1' => 'N1', 'marker' => 'M', 'old1' => 'O1', 'old2' => 'O2');
	}

	public function test_legacy_upgrade_with_deferred_marker_does_not_repost_history()
	{
		$this->legacy_marker('marker');
		$this->deferred = array('marker' => true);
		$this->run_rss($this->legacy_feed());
		$this->assertSame(array('Title new1', 'Title new2'), $this->subjects());

		$this->deferred = array();
		$this->run_rss($this->legacy_feed());
		$this->assertSame(array('Title new1', 'Title new2'), $this->subjects());
	}

	public function test_legacy_upgrade_with_deferred_historical_item_does_not_repost_it_later()
	{
		$this->legacy_marker('marker');
		$this->deferred = array('old1' => true);
		$this->run_rss($this->legacy_feed());
		$this->deferred = array();
		$this->run_rss($this->legacy_feed());
		$this->assertSame(array('Title new1', 'Title new2'), $this->subjects());
	}

	public function test_legacy_upgrade_keeps_a_deferred_new_item_retryable()
	{
		$this->legacy_marker('marker');
		$this->deferred = array('new1' => true);
		$this->run_rss($this->legacy_feed());
		$this->assertSame(array('Title new2'), $this->subjects());
		$this->deferred = array();
		$this->run_rss($this->legacy_feed());
		$this->assertSame(array('Title new2', 'Title new1'), $this->subjects());
		$this->assertCount(2, $this->tracker->rows);
	}
}

/**
 * Tracking driver that parses real feed XML, so parse_item_append listeners can defer items.
 */
class defer_tracking_driver extends fixtures\tracking_driver
{
	public $xml = '';

	public function parse_feed($url, $type, $timeout = self::FEED_TIMEOUT_PARSE)
	{
		return \ger\feedpostbot\classes\driver::parse_feed($url, $type, $timeout);
	}

	public function get_simplepie_instance($url, $timeout = self::FEED_TIMEOUT_DEFAULT, $raw_data = null)
	{
		return \ger\feedpostbot\classes\driver::get_simplepie_instance($url, $timeout, $raw_data === null ? $this->xml : $raw_data);
	}
}
