<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Site\View\Update;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Site\View\Update\Common;
use Joomla\CMS\MVC\View\HtmlView;
use Joomla\CMS\Uri\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A test host for the {@see Common} trait, like the ones next to the other update view tests.
 */
class ChangelogXmlHost extends HtmlView
{
	use Common;

	public array $envs = [];

	public $category;
}

/**
 * Tests {@see Common::buildChangelogXml()}, {@see Common::getChangelogUrl()} and
 * {@see Common::getJoomlaExtensionType()}.
 *
 * The XML is read back the way Joomla's own `Joomla\CMS\Changelog\Changelog` reads it: with the expat parser,
 * appending one bullet for every character-data callback inside `<item>`. That's what splits a plain-text item
 * at every entity in Joomla 6.2 (joomla-cms issue 48392), so a test that counts bullets the same way catches a
 * regression that a DOM-based assertion would miss.
 */
#[CoversClass(Common::class)]
class ChangelogXmlTest extends TestCase
{
	private ChangelogXmlHost $host;

	private string $rootUri;

	protected function setUp(): void
	{
		$this->host    = new ChangelogXmlHost();
		$this->rootUri = Uri::$rootUri;
	}

	protected function tearDown(): void
	{
		Uri::$rootUri = $this->rootUri;
	}

	private function item(array $overrides = []): object
	{
		return (object) array_merge(
			[
				'id'         => 7,
				'element'    => 'pkg_example',
				'type'       => 'packages',
				'release_id' => 100,
				'version'    => '1.2.3',
				'changelog'  => "! A security fix\n+ A feature",
			],
			$overrides
		);
	}

	/**
	 * Parse the changelog XML like Joomla does.
	 *
	 * @return  array<string, array<string, array<string,string[]>>>  version => ['meta' => [...], 'groups' => [...]]
	 */
	private function parseLikeJoomla(string $xml): array
	{
		$result  = [];
		$stack   = [];
		$current = null;
		$group   = null;

		$parser = xml_parser_create('UTF-8');

		xml_set_element_handler(
			$parser,
			function ($parser, $name) use (&$stack, &$current, &$group) {
				$stack[] = $name;

				if ($name === 'CHANGELOG')
				{
					$current = ['meta' => [], 'groups' => []];
				}
				elseif (in_array(strtolower($name), ['security', 'fix', 'language', 'addition', 'change', 'remove', 'note']))
				{
					$group                     = strtolower($name);
					$current['groups'][$group] = [];
				}
			},
			function ($parser, $name) use (&$stack, &$current, &$result) {
				array_pop($stack);

				if ($name === 'CHANGELOG')
				{
					$result[$current['meta']['VERSION']] = $current;
				}
			}
		);

		xml_set_character_data_handler(
			$parser,
			function ($parser, $data) use (&$stack, &$current, &$group) {
				$tag = end($stack);

				if ($tag === 'ITEM')
				{
					$current['groups'][$group][] = $data;
				}
				elseif (in_array($tag, ['ELEMENT', 'TYPE', 'VERSION']))
				{
					$current['meta'][$tag] = ($current['meta'][$tag] ?? '') . $data;
				}
			}
		);

		$this->assertSame(1, xml_parse($parser, $xml, true), xml_error_string(xml_get_error_code($parser)));

		return $result;
	}

	public function testAReleaseBecomesOneChangelogEntry(): void
	{
		$parsed = $this->parseLikeJoomla($this->host->buildChangelogXml([$this->item()]));

		$this->assertSame(['1.2.3'], array_map('strval', array_keys($parsed)));
		$this->assertSame(
			['ELEMENT' => 'pkg_example', 'TYPE' => 'package', 'VERSION' => '1.2.3'],
			$parsed['1.2.3']['meta']
		);
		$this->assertSame(
			['security' => ['A security fix'], 'addition' => ['A feature']],
			$parsed['1.2.3']['groups']
		);
	}

	public function testTheVersionIsCopiedExactly(): void
	{
		// Joomla matches with version_compare(==), so '1.1' must never be normalised to '1.1.0' or vice versa.
		$parsed = $this->parseLikeJoomla($this->host->buildChangelogXml([$this->item(['version' => '1.1'])]));

		$this->assertSame('1.1', $parsed['1.1']['meta']['VERSION']);
	}

	public function testAReleaseWithSeveralItemsIsListedOnce(): void
	{
		$xml = $this->host->buildChangelogXml([$this->item(), $this->item(), $this->item()]);

		$this->assertSame(1, substr_count($xml, '<changelog>'));
	}

	public function testReleasesWithoutAChangelogAreLeftOut(): void
	{
		$xml = $this->host->buildChangelogXml(
			[
				$this->item(['release_id' => 1, 'version' => '3.0.0', 'changelog' => '']),
				$this->item(['release_id' => 2, 'version' => '2.0.0', 'changelog' => null]),
				$this->item(['release_id' => 3, 'version' => '1.0.0']),
			]
		);

		$this->assertSame(['1.0.0'], array_keys($this->parseLikeJoomla($xml)));
	}

	public function testAStreamWithoutChangelogsGivesAnEmptyRoot(): void
	{
		$dom = new \DOMDocument();
		$dom->loadXML($this->host->buildChangelogXml([]));

		$this->assertSame('changelogs', $dom->documentElement->nodeName);
		$this->assertSame(0, $dom->documentElement->childNodes->length);
	}

	public function testSpecialCharactersStayInOneBulletAndCannotInjectMarkup(): void
	{
		$xml = $this->host->buildChangelogXml(
			[$this->item(['changelog' => '~ Fixed <select> & <script>x</script> for Müller ]]> done'])]
		);

		$parsed = $this->parseLikeJoomla($xml);
		$items  = $parsed['1.2.3']['groups']['change'];

		// One bullet, i.e. one character-data callback: not split at the ampersand or the umlaut.
		$this->assertCount(1, $items);

		// Joomla echoes the item verbatim, so it must reach Joomla already HTML-escaped.
		$this->assertSame(
			'Fixed &lt;select&gt; &amp; &lt;script&gt;x&lt;/script&gt; for Müller ]]&gt; done',
			$items[0]
		);
	}

	public function testTheChangelogUrlIsAnAbsoluteNonSefUrlOnTheSite(): void
	{
		Uri::$rootUri = 'https://www.example.com/';

		$this->assertSame(
			'https://www.example.com/index.php?option=com_ars&view=update&task=changelog&format=xml&id=42',
			$this->host->getChangelogUrl(42)
		);
	}

	public function testStreamTypesMapToJoomlaExtensionTypes(): void
	{
		$this->assertSame('component', $this->host->getJoomlaExtensionType('components'));
		$this->assertSame('package', $this->host->getJoomlaExtensionType('packages'));
		$this->assertSame('library', $this->host->getJoomlaExtensionType('libraries'));
		$this->assertSame('', $this->host->getJoomlaExtensionType(null));
	}
}
