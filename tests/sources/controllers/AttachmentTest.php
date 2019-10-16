<?php

/**
 * TestCase class for the Attachment Controller
 *
 * WARNING. These tests work directly with the local database. Don't run
 * them local if you need to keep your data untouched!
 */
class TestAttachment extends ElkArteCommonSetupTest
{
	protected $backupGlobalsBlacklist = ['user_info'];

	/**
	 * Initialize or add whatever necessary for these tests
	 */
	function setUp()
	{
		// Load in the common items so the system thinks we have an active login
		parent::setUp();
		$this->setSession();

		new ElkArte\Themes\ThemeLoader();
	}

	/**
	 * Cleanup data we no longer need at the end of the tests in this class.
	 *
	 * tearDown() is run automatically by the testing framework after each test method.
	 */
	public function tearDown()
	{
		parent::tearDown();
	}

	/**
	 * Test getting the group list for an announcement
	 */
	public function testNeedTheme()
	{
		// Get the attachment controller
		$controller = new \ElkArte\Controller\Attachment(new \ElkArte\EventManager());
		$check = $controller->needTheme();

		// Check
		$this->assertFalse($check);
	}

	public function testUlattach()
	{
		global $context;

		// Get the attachment controller
		$controller = new \ElkArte\Controller\Attachment(new \ElkArte\EventManager());
		$controller->action_ulattach();

		// With no files supplied, the json result should be false
		$this->assertFalse($context['json_data']['result']);

		// Some BS file name
		$_FILES['attachment'] = [0 => 'blablabla', 'tmp_name' => 'blablabla'];
		$controller->action_ulattach();
		$this->assertTrue($context['json_data']['result']);
	}
}