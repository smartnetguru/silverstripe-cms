<?php
/**
 * @package cms
 * @subpackage tests
 */
class ErrorPageTest extends FunctionalTest {
	
	protected static $fixture_file = 'ErrorPageTest.yml';

	/**
	 * Location of temporary cached files
	 *
	 * @var string
	 */
	protected $tmpAssetsPath = '';

	public function setUp() {
		parent::setUp();
		// Set temporary asset backend store
		AssetStoreTest_SpyStore::activate('ErrorPageTest');
		Config::inst()->update('ErrorPage', 'static_filepath', AssetStoreTest_SpyStore::base_path());
		Config::inst()->update('ErrorPage', 'enable_static_file', true);
		Config::inst()->update('Director', 'environment_type', 'live');
		$this->logInWithPermission('ADMIN');
	}

	public function tearDown() {
		AssetStoreTest_SpyStore::reset();
		parent::tearDown();
	}
	
	public function test404ErrorPage() {
		$page = $this->objFromFixture('ErrorPage', '404');
		// ensure that the errorpage exists as a physical file
		$page->publish('Stage', 'Live');
		
		$response = $this->get('nonexistent-page');
		
		/* We have body text from the error page */
		$this->assertNotNull($response->getBody(), 'We have body text from the error page');

		/* Status code of the SS_HTTPResponse for error page is "404" */
		$this->assertEquals($response->getStatusCode(), '404', 'Status code of the SS_HTTPResponse for error page is "404"');
		
		/* Status message of the SS_HTTPResponse for error page is "Not Found" */
		$this->assertEquals($response->getStatusDescription(), 'Not Found', 'Status message of the HTTResponse for error page is "Not found"');
	}
	
	public function testBehaviourOfShowInMenuAndShowInSearchFlags() {
		$page = $this->objFromFixture('ErrorPage', '404');
		
		/* Don't show the error page in the menus */
		$this->assertEquals($page->ShowInMenus, 0, 'Don\'t show the error page in the menus');
		
		/* Don't show the error page in the search */
		$this->assertEquals($page->ShowInSearch, 0, 'Don\'t show the error page in search');
	}

	public function testBehaviourOf403() {
		$page = $this->objFromFixture('ErrorPage', '403');
		$page->publish('Stage', 'Live');
		
		$response = $this->get($page->RelativeLink());
		
		$this->assertEquals($response->getStatusCode(), '403');
		$this->assertNotNull($response->getBody(), 'We have body text from the error page');
	}
	
	public function testSecurityError() {
		// Generate 404 page
		$page = $this->objFromFixture('ErrorPage', '404');
		$page->publish('Stage', 'Live');
		
		// Test invalid action
		$response = $this->get('Security/nosuchaction');
		$this->assertEquals($response->getStatusCode(), '404');
		$this->assertNotNull($response->getBody());
		$this->assertContains('text/html', $response->getHeader('Content-Type'));
	}

	public function testStaticCaching() {
		// Test new error code does not have static content
		$this->assertEmpty(ErrorPage::get_content_for_errorcode('401'));
		$expectedErrorPagePath = AssetStoreTest_SpyStore::base_path() . '/error-401.html';
		$this->assertFileNotExists($expectedErrorPagePath, 'Error page is not automatically cached');

		// Write new 401 page
		$page = new ErrorPage();
		$page->ErrorCode = 401;
		$page->Title = 'Unauthorised';
		$page->write();
		$page->publish('Stage', 'Live');
		$page->doPublish();
		
		// Static cache should now exist
		$this->assertNotEmpty(ErrorPage::get_content_for_errorcode('401'));
		$expectedErrorPagePath = AssetStoreTest_SpyStore::base_path() . '/error-401.html';
		$this->assertFileExists($expectedErrorPagePath, 'Error page is cached');
	}

	/**
	 * Test fallback to file generation API with enable_static_file disabled
	 */
	public function testGeneratedFile() {
		Config::inst()->update('ErrorPage', 'enable_static_file', false);
		$this->logInWithPermission('ADMIN');

		$page = new ErrorPage();
		$page->ErrorCode = 405;
		$page->Title = 'Method Not Allowed';
		$page->write();
		$page->doPublish();

		// Error content is available, even though the static file does not exist (only in assetstore)
		$this->assertNotEmpty(ErrorPage::get_content_for_errorcode('405'));
		$expectedErrorPagePath = AssetStoreTest_SpyStore::base_path() . '/error-405.html';
		$this->assertFileNotExists($expectedErrorPagePath, 'Error page is not cached in static location');
	}
}
