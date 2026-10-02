<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

/**
 * Listeners of ger.feedpostbot.parse_item_append may defer an item: it is left out of the
 * current run only and is offered again on the next run. Feeds are parsed by the real
 * parse_feed() and processed by the real fetch_items(), as fetch_all() does.
 */
class defer_test extends \phpbb_test_case
{
	/** @var defer_fixture */
	protected $driver;
	protected $db;
	/** @var array Listeners per event name, each called with and returning the event data */
	protected $listeners = array();
	/** @var array "event name:guid" of every triggered event */
	protected $triggered = array();
	/** @var array Feed state as stored in config_text */
	protected $saved;

	public function setUp(): void
	{
		parent::setUp();
		$config = new \phpbb\config\config(array('feedpostbot_locked' => 0));
		$config_text = $this->getMockBuilder('\phpbb\config\db_text')->disableOriginalConstructor()->getMock();
		$config_text->method('get')->willReturnCallback(function () { return json_encode($this->saved); });
		$config_text->method('set')->willReturnCallback(function ($key, $value) { $this->saved = json_decode($value, true); });
		$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$user->data = array('user_id' => 2, 'username' => 'Administrator', 'user_lang' => 'en', 'is_registered' => true);
		$user->timezone = new \DateTimeZone('UTC');
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$log = $this->getMockBuilder('\phpbb\log\log')->disableOriginalConstructor()->getMock();
		$dispatcher = $this->getMockBuilder('\phpbb\event\dispatcher')->disableOriginalConstructor()->getMock();
		$dispatcher->method('trigger_event')->willReturnCallback(function ($event, $data) {
			$this->triggered[] = $event . ':' . $data['append']['guid'];
			return isset($this->listeners[$event]) ? call_user_func($this->listeners[$event], $data) : $data;
		});
		$this->driver = new defer_fixture($config, $config_text, $user, $language, $auth, $this->db, $log, './', 'php', $dispatcher);
		$this->saved = array(array('url' => 'https://example.com/feed', 'type' => 'rss', 'user_id' => 3,
			'forum_id' => 5, 'timeout' => 3, 'textlimit' => 0, 'append_link' => 1,
			'latest' => array('guid' => '', 'link' => '', 'pubDate' => 0), 'handled' => array()));
		$this->driver->current_state = $this->saved;
		// Newest first, as feeds usually are: a is the newest item, c the oldest.
		$this->driver->xml = '<rss version="2.0"><channel><title>Feed</title>'
			. $this->rss_item('a', 'Wed, 03 Jan 2024 10:00:00 GMT')
			. $this->rss_item('b', 'Tue, 02 Jan 2024 10:00:00 GMT')
			. $this->rss_item('c', 'Mon, 01 Jan 2024 10:00:00 GMT')
			. '</channel></rss>';
	}

	protected function rss_item($guid, $date)
	{
		return '<item><guid>' . $guid . '</guid><title>Title ' . $guid . '</title><link>https://example.com/' . $guid
			. '</link><description>Body ' . $guid . '</description><pubDate>' . $date . '</pubDate></item>';
	}

	/** Feed bot user and target forum, loaded by every prepare_destinations() call */
	protected function destinations()
	{
		$user = array('user_id' => 3, 'username' => 'Feed bot', 'user_lang' => 'en', 'user_type' => USER_NORMAL,
			'user_timezone' => 'UTC', 'user_dateformat' => 'd/m/Y');
		$forum = array('forum_id' => 5, 'forum_name' => 'News', 'forum_type' => FORUM_POST);
		$rows = array();
		$this->db->method('sql_fetchrow')->willReturnCallback(function () use (&$rows, $user, $forum) {
			$rows = $rows ?: array($user, false, $forum, false);
			return array_shift($rows);
		});
	}

	/** Defer the items with the given guids in parse_item_append */
	protected function defer(array $guids)
	{
		$this->listeners['ger.feedpostbot.parse_item_append'] = function ($data) use ($guids) {
			$this->assertFalse($data['defer']);
			$data['defer'] = in_array($data['append']['guid'], $guids, true);
			return $data;
		};
	}

	/** One feed run as performed by fetch_all() for a source */
	protected function run_feed()
	{
		return $this->driver->fetch_items($this->driver->parse_feed('https://example.com/feed', 'rss'), 0);
	}

	/** One complete cron run of the feed */
	protected function run_all()
	{
		$this->driver->current_state = array();
		return $this->driver->fetch_all();
	}

	protected function guids(array $items)
	{
		return array_column($items, 'guid');
	}

	protected function handled($guid)
	{
		return isset($this->driver->current_state[0]['handled'][hash('sha256', 'guid:' . $guid)]);
	}

	public function test_support_is_advertised()
	{
		$this->assertTrue(\ger\feedpostbot\classes\driver::SUPPORTS_ITEM_DEFER);
	}

	public function test_deferred_item_is_left_out_of_the_parse_result()
	{
		$this->defer(array('b'));
		$items = $this->driver->parse_feed('https://example.com/feed', 'rss');
		$this->assertSame(array('a', 'c'), $this->guids($items));
		// The backwards-compatible event is not triggered for the deferred item.
		$this->assertSame(array(
			'ger.feedpostbot.parse_item_append:a', 'ger.feedpostbot.parse_rss_append:a',
			'ger.feedpostbot.parse_item_append:b',
			'ger.feedpostbot.parse_item_append:c', 'ger.feedpostbot.parse_rss_append:c',
		), $this->triggered);
	}

	public function test_deferred_item_is_not_handled_and_is_processed_on_the_next_run()
	{
		$this->destinations();
		$this->defer(array('b'));
		$this->assertSame(2, $this->run_feed());
		$this->assertSame(array('c', 'a'), $this->driver->posted);
		$this->assertFalse($this->handled('b'));
		$this->assertTrue($this->handled('a') && $this->handled('c'));
		$this->assertSame('a', $this->driver->current_state[0]['latest']['guid']);

		// The listener no longer defers: only the deferred item is processed now.
		unset($this->listeners['ger.feedpostbot.parse_item_append']);
		$this->assertSame(1, $this->run_feed());
		$this->assertSame(array('c', 'a', 'b'), $this->driver->posted);
		$this->assertTrue($this->handled('b'));
		$this->assertSame(0, $this->run_feed());
	}

	public function test_deferring_the_first_processed_item_does_not_stop_newer_items()
	{
		$this->destinations();
		// Items are processed oldest first, so c would be the first one.
		$this->defer(array('c'));
		$this->assertSame(2, $this->run_feed());
		$this->assertSame(array('b', 'a'), $this->driver->posted);
		$this->assertFalse($this->handled('c'));
	}

	public function test_item_stays_deferred_as_long_as_a_listener_defers_it()
	{
		$this->destinations();
		$this->defer(array('a'));
		$this->assertSame(2, $this->run_feed());
		$this->assertSame(0, $this->run_feed());
		$this->assertSame(array('c', 'b'), $this->driver->posted);
		$this->assertFalse($this->handled('a'));
		$this->assertSame('b', $this->driver->current_state[0]['latest']['guid']);
	}

	public function test_listeners_ignoring_defer_keep_existing_behaviour()
	{
		$this->destinations();
		$this->listeners['ger.feedpostbot.parse_item_append'] = function ($data) {
			$data['append']['title'] .= ' (changed)';
			return $data;
		};
		$this->listeners['ger.feedpostbot.parse_rss_append'] = function ($data) {
			$data['append']['title'] .= ' (legacy)';
			return $data;
		};
		$items = $this->driver->parse_feed('https://example.com/feed', 'rss');
		$this->assertSame(array('a', 'b', 'c'), $this->guids($items));
		$this->assertSame('Title a (changed) (legacy)', $items[0]['title']);
		$this->assertSame(3, $this->driver->fetch_items($items, 0));
		$this->assertSame(array('c', 'b', 'a'), $this->driver->posted);
	}

	public function test_backwards_compatible_events_neither_receive_nor_apply_defer()
	{
		$this->listeners['ger.feedpostbot.parse_rss_append'] = function ($data) {
			$this->assertArrayNotHasKey('defer', $data);
			// Even a listener returning the variable cannot defer through this event.
			return $data + array('defer' => true);
		};
		$items = $this->driver->parse_feed('https://example.com/feed', 'rss');
		$this->assertSame(array('a', 'b', 'c'), $this->guids($items));
	}

	// --- Upgrade from the former single latest marker -----------------------------------

	/**
	 * Board upgraded from a version that only kept the latest posted item: no handled history yet.
	 * new2 and new1 appeared after the last old run; marker and the older items were posted by it.
	 */
	protected function legacy_marker()
	{
		$this->destinations();
		unset($this->saved[0]['handled']);
		$this->saved[0]['latest'] = array('guid' => 'marker', 'link' => 'https://example.com/marker', 'pubDate' => 0);
		$this->driver->xml = '<rss version="2.0"><channel><title>Feed</title>';
		foreach (array('new2' => 5, 'new1' => 4, 'marker' => 3, 'old1' => 2, 'old2' => 1) as $guid => $day)
		{
			$this->driver->xml .= $this->rss_item($guid, 'Fri, 0' . $day . ' Jan 2024 10:00:00 GMT');
		}
		$this->driver->xml .= '</channel></rss>';
	}

	public function test_legacy_marker_is_adopted_when_the_marker_item_is_deferred()
	{
		$this->legacy_marker();
		$this->defer(array('marker'));
		$this->assertSame(2, $this->run_all());
		$this->assertSame(array('new1', 'new2'), $this->driver->posted);
		unset($this->listeners['ger.feedpostbot.parse_item_append']);
		$this->assertSame(0, $this->run_all());
		$this->assertSame(array('new1', 'new2'), $this->driver->posted);
	}

	public function test_legacy_marker_adoption_covers_deferred_older_items()
	{
		$this->legacy_marker();
		$this->defer(array('old1'));
		$this->assertSame(2, $this->run_all());
		unset($this->listeners['ger.feedpostbot.parse_item_append']);
		$this->assertSame(0, $this->run_all());
		$this->assertSame(array('new1', 'new2'), $this->driver->posted);
	}

	public function test_legacy_marker_adoption_keeps_deferred_new_items_retryable()
	{
		$this->legacy_marker();
		$this->defer(array('new1'));
		$this->assertSame(1, $this->run_all());
		$this->assertSame(array('new2'), $this->driver->posted);
		$this->assertArrayNotHasKey(hash('sha256', 'guid:new1'), $this->saved[0]['handled']);
		unset($this->listeners['ger.feedpostbot.parse_item_append']);
		$this->assertSame(1, $this->run_all());
		$this->assertSame(array('new2', 'new1'), $this->driver->posted);
	}
}

class defer_fixture extends \ger\feedpostbot\classes\driver
{
	/** @var string Feed served by get_simplepie_instance() */
	public $xml = '';
	/** @var array Guids in the order they were posted */
	public $posted = array();

	public function get_simplepie_instance($url, $timeout = self::FEED_TIMEOUT_DEFAULT, $raw_data = null)
	{
		return parent::get_simplepie_instance($url, $timeout, $raw_data === null ? $this->xml : $raw_data);
	}

	protected function post_message($item, $source_id)
	{
		$this->posted[] = $item['guid'];
		return '/post/1';
	}

	protected function create_lock()
	{
		return new defer_lock_fixture();
	}
}

class defer_lock_fixture
{
	public function acquire()
	{
		return true;
	}

	public function release()
	{
	}
}
