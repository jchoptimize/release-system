<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Site\Event;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Table\CategoryTable;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Akeeba\Component\ARS\Administrator\Table\ReleaseTable;
use Joomla\CMS\User\User;
use Joomla\Event\Event;

/**
 * Raised as `onARSBeforeDownload` when a download has passed every access check, just before the file is sent.
 *
 * A plugin may refuse the download with {@see refuse()}. ARS then answers with that HTTP status and the message as
 * plain text, and never redirects: update clients follow redirects and would try to install the page they land on.
 * The attempt is logged as unauthorised, but the banUnauth option never bans for a refusal: the visitor is usually a
 * paying customer whose site a plugin's rules leave out, not an attacker.
 *
 * The user is the identity the download runs as: the visitor, or the owner of the Download ID in the request.
 */
class BeforeDownloadEvent extends Event
{
	public const NAME = 'onARSBeforeDownload';

	private ?string $refusalMessage = null;

	private int $refusalCode = 403;

	public function __construct(ItemTable $item, ReleaseTable $release, CategoryTable $category, User $user)
	{
		parent::__construct(
			self::NAME,
			[
				'item'     => $item,
				'release'  => $release,
				'category' => $category,
				'user'     => $user,
			]
		);
	}

	public function getItem(): ItemTable
	{
		return $this->getArgument('item');
	}

	public function getRelease(): ReleaseTable
	{
		return $this->getArgument('release');
	}

	public function getCategory(): CategoryTable
	{
		return $this->getArgument('category');
	}

	public function getUser(): User
	{
		return $this->getArgument('user');
	}

	/**
	 * Refuse the download. The message is sent to the client as plain text, so keep it short and free of markup.
	 *
	 * @param   string  $message  Why the download is refused.
	 * @param   int     $code     The HTTP status to answer with, 400–599 (anything else becomes 403).
	 */
	public function refuse(string $message, int $code = 403): void
	{
		$this->refusalMessage = $message;
		$this->refusalCode    = ($code >= 400 && $code <= 599) ? $code : 403;

		$this->stopPropagation();
	}

	public function isRefused(): bool
	{
		return $this->refusalMessage !== null;
	}

	public function getRefusalMessage(): ?string
	{
		return $this->refusalMessage;
	}

	public function getRefusalCode(): int
	{
		return $this->refusalCode;
	}
}
