<?php

namespace SilverStripe\CMS\Model;

/**
 * @package cms
 * @subpackage model
 */

use DOMElement;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\Versioning\Versioned;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use Injector;
use SS_HTMLValue;
use Director;

/**
 * Adds tracking of links in any HTMLText fields which reference SiteTree or File items.
 *
 * Attaching this to any DataObject will add four fields which contain all links to SiteTree and File items
 * referenced in any HTMLText fields, and two booleans to indicate if there are any broken links. Call
 * augmentSyncLinkTracking to update those fields with any changes to those fields.
 *
 * Note that since both SiteTree and File are versioned, LinkTracking and ImageTracking will
 * only be enabled for the Stage record.
 *
 * {@see SiteTreeFileExtension} for the extension applied to {@see File}
 *
 * @property SiteTree $owner
 *
 * @property bool $HasBrokenFile
 * @property bool $HasBrokenLink
 *
 * @method ManyManyList LinkTracking() List of site pages linked on this page.
 * @method ManyManyList ImageTracking() List of Images linked on this page.
 * @method ManyManyList BackLinkTracking List of site pages that link to this page.
 */
class SiteTreeLinkTracking extends DataExtension {

	/**
	 * @var SiteTreeLinkTracking_Parser
	 */
	protected $parser;

	/**
	 * Inject parser for each page
	 *
	 * @var array
	 * @config
	 */
	private static $dependencies = array(
		'Parser' => '%$SiteTreeLinkTracking_Parser'
	);

	/**
	 * Parser for link tracking
	 *
	 * @return SiteTreeLinkTracking_Parser
	 */
	public function getParser() {
		return $this->parser;
	}

	/**
	 * @param SiteTreeLinkTracking_Parser $parser
	 * @return $this
	 */
	public function setParser($parser) {
		$this->parser = $parser;
		return $this;
	}

	private static $db = array(
		"HasBrokenFile" => "Boolean",
		"HasBrokenLink" => "Boolean"
	);

	private static $many_many = array(
		"LinkTracking" => "SilverStripe\\CMS\\Model\\SiteTree",
		"ImageTracking" => "File"  // {@see SiteTreeFileExtension}
	);

	private static $belongs_many_many = array(
		"BackLinkTracking" => "SilverStripe\\CMS\\Model\\SiteTree.LinkTracking"
	);

	/**
	 * Tracked images are considered owned by this page
	 *
	 * @config
	 * @var array
	 */
	private static $owns = array(
		"ImageTracking"
	);

	private static $many_many_extraFields = array(
		"LinkTracking" => array("FieldName" => "Varchar"),
		"ImageTracking" => array("FieldName" => "Varchar")
	);

	/**
	 * Scrape the content of a field to detect anly links to local SiteTree pages or files
	 *
	 * @param string $fieldName The name of the field on {@link @owner} to scrape
	 */
	public function trackLinksInField($fieldName) {
		$record = $this->owner;

		$linkedPages = array();
		$linkedFiles = array();

		$htmlValue = Injector::inst()->create('HTMLValue', $record->$fieldName);
		$links = $this->parser->process($htmlValue);

		// Highlight broken links in the content.
		foreach ($links as $link) {
			// Skip links without domelements
			if(!isset($link['DOMReference'])) {
				continue;
			}

			/** @var DOMElement $domReference */
			$domReference = $link['DOMReference'];
			$classStr = trim($domReference->getAttribute('class'));
			if (!$classStr) {
				$classes = array();
			} else {
				$classes = explode(' ', $classStr);
			}

			// Add or remove the broken class from the link, depending on the link status.
			if ($link['Broken']) {
				$classes = array_unique(array_merge($classes, array('ss-broken')));
			} else {
				$classes = array_diff($classes, array('ss-broken'));
			}

			if (!empty($classes)) {
				$domReference->setAttribute('class', implode(' ', $classes));
			} else {
				$domReference->removeAttribute('class');
			}
		}
		$record->$fieldName = $htmlValue->getContent();

		// Populate link tracking for internal links & links to asset files.
		foreach ($links as $link) {
			switch ($link['Type']) {
				case 'sitetree':
					if ($link['Broken']) {
						$record->HasBrokenLink = true;
					} else {
						$linkedPages[] = $link['Target'];
					}
					break;

				case 'file':
				case 'image':
					if ($link['Broken']) {
						$record->HasBrokenFile = true;
					} else {
						$linkedFiles[] = $link['Target'];
					}
					break;

				default:
					if ($link['Broken']) {
						$record->HasBrokenLink = true;
					}
					break;
			}
		}

		// Update the "LinkTracking" many_many
		if($record->ID && $record->manyManyComponent('LinkTracking') && ($tracker = $record->LinkTracking())) {
			$tracker->removeByFilter(array(
				sprintf('"FieldName" = ? AND "%s" = ?', $tracker->getForeignKey())
					=> array($fieldName, $record->ID)
			));

			if($linkedPages) foreach($linkedPages as $item) {
				$tracker->add($item, array('FieldName' => $fieldName));
			}
		}

		// Update the "ImageTracking" many_many
		if($record->ID && $record->manyManyComponent('ImageTracking') && ($tracker = $record->ImageTracking())) {
			$tracker->removeByFilter(array(
				sprintf('"FieldName" = ? AND "%s" = ?', $tracker->getForeignKey())
					=> array($fieldName, $record->ID)
			));

			if($linkedFiles) foreach($linkedFiles as $item) {
				$tracker->add($item, array('FieldName' => $fieldName));
			}
		}
	}

	/**
	 * Find HTMLText fields on {@link owner} to scrape for links that need tracking
	 *
	 * @todo Support versioned many_many for per-stage page link tracking
	 */
	public function augmentSyncLinkTracking() {
		// Skip live tracking
		if(Versioned::get_stage() == Versioned::LIVE) {
			return;
		}

		// Reset boolean broken flags
		$this->owner->HasBrokenLink = false;
		$this->owner->HasBrokenFile = false;

		// Build a list of HTMLText fields
		$allFields = $this->owner->db();
		$htmlFields = array();
		foreach($allFields as $field => $fieldSpec) {
			$fieldObj = $this->owner->dbObject($field);
			if($fieldObj instanceof DBHTMLText) {
				$htmlFields[] = $field;
			}
		}

		foreach($htmlFields as $field) {
			$this->trackLinksInField($field);
		}
	}
}

/**
 * A helper object for extracting information about links.
 */
class SiteTreeLinkTracking_Parser {

	/**
	 * Finds the links that are of interest for the link tracking automation. Checks for brokenness and attaches
	 * extracted metadata so consumers can decide what to do with the DOM element (provided as DOMReference).
	 *
	 * @param SS_HTMLValue $htmlValue Object to parse the links from.
	 * @return array Associative array containing found links with the following field layout:
	 *		Type: string, name of the link type
	 *		Target: any, a reference to the target object, depends on the Type
	 *		Anchor: string, anchor part of the link
	 *		DOMReference: DOMElement, reference to the link to apply changes.
	 *		Broken: boolean, a flag highlighting whether the link should be treated as broken.
	 */
	public function process(SS_HTMLValue $htmlValue) {
		$results = array();

		$links = $htmlValue->getElementsByTagName('a');
		if(!$links) {
			return $results;
		}

		foreach($links as $link) {
			if (!$link->hasAttribute('href')) {
				continue;
			}

			$href = Director::makeRelative($link->getAttribute('href'));

			// Definitely broken links.
			if($href == '' || $href[0] == '/') {
				$results[] = array(
					'Type' => 'broken',
					'Target' => null,
					'Anchor' => null,
					'DOMReference' => $link,
					'Broken' => true
				);

				continue;
			}

			// Link to a page on this site.
			$matches = array();
			if(preg_match('/\[sitetree_link(?:\s*|%20|,)?id=(?<id>[0-9]+)\](#(?<anchor>.*))?/i', $href, $matches)) {
				$page = DataObject::get_by_id('SilverStripe\\CMS\\Model\\SiteTree', $matches['id']);
				$broken = false;

				if (!$page) {
					// Page doesn't exist.
					$broken = true;
				} else if (!empty($matches['anchor'])) {
					$anchor = preg_quote($matches['anchor'], '/');

					if (!preg_match("/(name|id)=\"{$anchor}\"/", $page->Content)) {
						// Broken anchor on the target page.
						$broken = true;
					}
				}

				$results[] = array(
					'Type' => 'sitetree',
					'Target' => $matches['id'],
					'Anchor' => empty($matches['anchor']) ? null : $matches['anchor'],
					'DOMReference' => $link,
					'Broken' => $broken
				);

				continue;
			}

			// Link to a file on this site.
			$matches = array();
			if(preg_match('/\[file_link(?:\s*|%20|,)?id=(?<id>[0-9]+)/i', $href, $matches)) {
				$results[] = array(
					'Type' => 'file',
					'Target' => $matches['id'],
					'Anchor' => null,
					'DOMReference' => $link,
					'Broken' => !DataObject::get_by_id('File', $matches['id'])
				);

				continue;
			}

			// Local anchor.
			$matches = array();
			if(preg_match('/^#(.*)/i', $href, $matches)) {
				$anchor = preg_quote($matches[1], '#');
				$results[] = array(
					'Type' => 'localanchor',
					'Target' => null,
					'Anchor' => $matches[1],
					'DOMReference' => $link,
					'Broken' => !preg_match("#(name|id)=\"{$anchor}\"#", $htmlValue->getContent())
				);

				continue;
			}

		}

		// Find all [image ] shortcodes (will be inline, not inside attributes)
		$content = $htmlValue->getContent();
		if(preg_match_all('/\[image([^\]]+)\bid=(["])?(?<id>\d+)\D/i', $content, $matches)) {
			foreach($matches['id'] as $id) {
				$results[] = array(
					'Type' => 'image',
					'Target' => (int)$id,
					'Anchor' => null,
					'DOMReference' => null,
					'Broken' => !DataObject::get_by_id('Image', (int)$id)
				);
			}
		}
		return $results;
	}

}
