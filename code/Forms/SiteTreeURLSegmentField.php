<?php

namespace SilverStripe\CMS\Forms;

use SilverStripe\CMS\Model\SiteTree;
use SS_HTTPRequest;
use TextField;
use Requirements;
use Controller;
use Convert;

/**
 * Used to edit the SiteTree->URLSegment property, and suggest input based on the serverside rules
 * defined through {@link SiteTree->generateURLSegment()} and {@link URLSegmentFilter}.
 *
 * Note: The actual conversion for saving the value takes place in the model layer.
 *
 * @package cms
 * @subpackage forms
 */
class SiteTreeURLSegmentField extends TextField {

	/**
	 * @var string
	 */
	protected $helpText, $urlPrefix, $urlSuffix, $defaultUrl;

	private static $allowed_actions = array(
		'suggest'
	);

	public function Value() {
		return rawurldecode($this->value);
	}

	public function getAttributes() {
		return array_merge(
			parent::getAttributes(),
			array(
				'data-prefix' => $this->getURLPrefix(),
				'data-suffix' => '?stage=Stage',
				'data-default-url' => $this->getDefaultURL()
			)
		);
	}

	public function Field($properties = array()) {
		Requirements::javascript(CMS_DIR . '/client/dist/js/SiteTreeURLSegmentField.js');
		Requirements::add_i18n_javascript(CMS_DIR . '/client/lang', false, true);
		Requirements::css(FRAMEWORK_ADMIN_DIR . '/client/dist/styles/bundle.css');
		return parent::Field($properties);
	}

	/**
	 * @param SS_HTTPRequest $request
	 * @return string
	 */
	public function suggest($request) {
		if(!$request->getVar('value')) {
			return $this->httpError(405,
				_t('SiteTreeURLSegmentField.EMPTY', 'Please enter a URL Segment or click cancel')
			);
		}
		$page = $this->getPage();

		// Same logic as SiteTree->onBeforeWrite
		$page->URLSegment = $page->generateURLSegment($request->getVar('value'));
		$count = 2;
		while(!$page->validURLSegment()) {
			$page->URLSegment = preg_replace('/-[0-9]+$/', null, $page->URLSegment) . '-' . $count;
			$count++;
		}

		Controller::curr()->getResponse()->addHeader('Content-Type', 'application/json');
		return Convert::raw2json(array('value' => $page->URLSegment));
	}

	/**
	 * @return SiteTree
	 */
	public function getPage() {
		$idField = $this->getForm()->Fields()->dataFieldByName('ID');
		return ($idField && $idField->Value())
			? SiteTree::get()->byID($idField->Value())
			: SiteTree::singleton();
	}

	/**
	 * @param string $string The secondary text to show
	 * @return $this
	 */
	public function setHelpText($string){
		$this->helpText = $string;
		return $this;
	}

	/**
	 * @return string the secondary text to show in the template
	 */
	public function getHelpText(){
		return $this->helpText;

	}

	/**
	 * @param string $url the url that prefixes the page url segment field
	 * @return $this
	 */
	public function setURLPrefix($url){
		$this->urlPrefix = $url;
		return $this;
	}

	/**
	 * @return string the url prefixes the page url segment field to show in template
	 */
	public function getURLPrefix(){
		return $this->urlPrefix;
	}

	public function getURLSuffix() {
		return $this->urlSuffix;
	}

	/**
	 * @return string Indicator for UI to respond to changes accurately,
	 * and auto-update the field value if changes to the default occur.
	 * Does not set the field default value.
	 */
	public function getDefaultURL(){
		return $this->defaultUrl;
	}

	public function setDefaultURL($url) {
		$this->defaultUrl = $url;
		return $this;
	}

	public function setURLSuffix($suffix) {
		$this->urlSuffix = $suffix;
		return $this;
	}

	public function Type() {
		return 'text urlsegment';
	}

	public function getURL() {
		return Controller::join_links($this->getURLPrefix(), $this->Value(), $this->getURLSuffix());
	}

	public function performReadonlyTransformation() {
		$newInst = parent::performReadonlyTransformation();
		$newInst->helpText = $this->helpText;
		$newInst->urlPrefix = $this->urlPrefix;
		$newInst->urlSuffix = $this->urlSuffix;
		$newInst->defaultUrl = $this->defaultUrl;
		return $newInst;
	}
}


/**
 * Readonly version of a site tree URL segment field
 *
 * @package forms
 * @subpackage fields-basic
 */
class SiteTreeURLSegmentField_Readonly extends SiteTreeURLSegmentField {
	protected $readonly = true;

	public function performReadonlyTransformation() {
		return clone $this;
	}
}
