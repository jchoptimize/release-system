<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Helper;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Helper\Changelog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Changelog::class)]
#[Group('Helper')]
class ChangelogTest extends TestCase
{
	public static function glyphProvider(): array
	{
		return [
			'! is security'  => ['!', 'security'],
			'# is fix'       => ['#', 'fix'],
			'$ is language'  => ['$', 'language'],
			'+ is addition'  => ['+', 'addition'],
			'~ is change'    => ['~', 'change'],
			'- is remove'    => ['-', 'remove'],
			'^ is note'      => ['^', 'note'],
		];
	}

	#[DataProvider('glyphProvider')]
	public function testEachGlyphMapsToItsJoomlaGroup(string $glyph, string $group): void
	{
		$this->assertSame([$group => ['Some text']], Changelog::parse($glyph . ' Some text'));
	}

	public function testGroupsComeOutInJoomlaOrderWithSecurityFirst(): void
	{
		$parsed = Changelog::parse(
			implode("\n", ['^ A note', '+ A feature', '# A fix', '! A security fix', '- A removal', '~ A change', '$ A translation'])
		);

		$this->assertSame(Changelog::GROUPS, array_keys($parsed));
	}

	public function testEntriesKeepTheirOrderWithinAGroup(): void
	{
		$this->assertSame(
			['fix' => ['[HIGH] First', '[LOW] Second']],
			Changelog::parse("# [HIGH] First\n# [LOW] Second")
		);
	}

	public function testEmptyGroupsAreLeftOut(): void
	{
		$this->assertSame([], Changelog::parse(''));
		$this->assertSame([], Changelog::parse(null));
		$this->assertSame([], Changelog::parse("\n   \n"));
	}

	public function testALineWithoutAKnownGlyphIsKeptAsANote(): void
	{
		$this->assertSame(
			['note' => ['* Legacy security glyph', 'No glyph at all', '+NoSpace']],
			Changelog::parse("* Legacy security glyph\nNo glyph at all\n+NoSpace")
		);
	}

	public function testSpecialCharactersAreKeptVerbatim(): void
	{
		$this->assertSame(
			['change' => ['Fixed <select> & "quotes" for Müller, Ångström']],
			Changelog::parse('~ Fixed <select> & "quotes" for Müller, Ångström')
		);
	}

	public function testControlCharactersXmlCannotCarryAreDropped(): void
	{
		$this->assertSame(['fix' => ['Bell and backspace']], Changelog::parse("# Bell\x07 and back\x08space"));
	}

	public function testWindowsAndOldMacLineEndingsAreHandled(): void
	{
		$this->assertSame(
			['fix' => ['Three'], 'addition' => ['One', 'Two']],
			Changelog::parse("+ One\r\n+ Two\r# Three\r\n")
		);
	}

	public function testLatestSectionReturnsOnlyTheTopmostVersion(): void
	{
		$file = <<<TXT
<?php die(); ?>
My Extension 1.2.3
================================================================================
! SECURITY: Something
+ New thing

My Extension 1.2.2
================================================================================
# Old fix
TXT;

		$this->assertSame(
			['heading' => 'My Extension 1.2.3', 'body' => "! SECURITY: Something\n+ New thing"],
			Changelog::latestSection($file)
		);
	}

	public function testLatestSectionWorksWithoutTheDieLine(): void
	{
		$this->assertSame(
			['heading' => 'Foo 2.0.0', 'body' => '+ Bar'],
			Changelog::latestSection("Foo 2.0.0\r\n===\r\n\r\n+ Bar\r\n")
		);
	}

	public function testLatestSectionIsNullWithoutAHeading(): void
	{
		$this->assertNull(Changelog::latestSection("+ Just entries\n# No heading"));
		$this->assertNull(Changelog::latestSection(''));
	}

	public function testAShortUnderlineIsNotAHeading(): void
	{
		$this->assertNull(Changelog::latestSection("Foo 1.0\n==\n+ Bar"));
	}
}
