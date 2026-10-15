<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Site\Event;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Table\CategoryTable;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Akeeba\Component\ARS\Administrator\Table\ReleaseTable;
use Akeeba\Component\ARS\Site\Event\BeforeDownloadEvent;
use Joomla\CMS\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The tables are built without their constructors, which need a database; the event only carries them.
 */
#[CoversClass(BeforeDownloadEvent::class)]
class BeforeDownloadEventTest extends TestCase
{
	public function testCarriesTheDownloadAndItsUser(): void
	{
		[$item, $release, $category] = $this->tables();
		$user = new User(42);

		$event = new BeforeDownloadEvent($item, $release, $category, $user);

		$this->assertSame('onARSBeforeDownload', $event->getName());
		$this->assertSame($item, $event->getItem());
		$this->assertSame($release, $event->getRelease());
		$this->assertSame($category, $event->getCategory());
		$this->assertSame($user, $event->getUser());
	}

	public function testIsNotRefusedUntilAPluginRefuses(): void
	{
		$event = $this->event();

		$this->assertFalse($event->isRefused());
		$this->assertNull($event->getRefusalMessage());
		$this->assertFalse($event->isStopped());
	}

	public function testRefusingRecordsTheAnswerAndStopsPropagation(): void
	{
		$event = $this->event();

		$event->refuse('This site is not activated.', 402);

		$this->assertTrue($event->isRefused());
		$this->assertSame('This site is not activated.', $event->getRefusalMessage());
		$this->assertSame(402, $event->getRefusalCode());
		$this->assertTrue($event->isStopped());
	}

	public static function codes(): array
	{
		return [
			'default'       => [null, 403],
			'client error'  => [404, 404],
			'server error'  => [503, 503],
			'success'       => [200, 403],
			'redirect'      => [302, 403],
			'out of range'  => [600, 403],
		];
	}

	#[DataProvider('codes')]
	public function testRefusalIsAlwaysAnErrorStatus(?int $code, int $expected): void
	{
		$event = $this->event();

		$code === null ? $event->refuse('No.') : $event->refuse('No.', $code);

		$this->assertSame($expected, $event->getRefusalCode());
	}

	private function event(): BeforeDownloadEvent
	{
		[$item, $release, $category] = $this->tables();

		return new BeforeDownloadEvent($item, $release, $category, new User(42));
	}

	private function tables(): array
	{
		return [
			(new ReflectionClass(ItemTable::class))->newInstanceWithoutConstructor(),
			(new ReflectionClass(ReleaseTable::class))->newInstanceWithoutConstructor(),
			(new ReflectionClass(CategoryTable::class))->newInstanceWithoutConstructor(),
		];
	}
}
